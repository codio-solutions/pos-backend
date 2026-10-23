<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SaleReturn;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        // unified Orders page — filterable by source: pos | website | visit
        $orders = Order::with('items.product', 'creator')
            ->when($request->source, fn ($q, $s) => $q->where('source', $s))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(25);

        return response()->json($orders);
    }

    /**
     * Manual POS sale/checkout. Creates the order and completes it immediately
     * (stock decrements right away, matching "sale is automatic at checkout").
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('is_active', true)],
            'items.*.qty' => 'required|integer|min:1|max:1000000',
            'items.*.unit_price' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
        ]);

        $order = DB::transaction(function () use ($data, $request) {
            $order = Order::create([
                'source' => 'pos',
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['items'] as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => Money::multiply($item['unit_price'], $item['qty']),
                ]);
            }

            $order->load('items');
            $order->complete();

            return $order;
        });

        return response()->json($order->load('items.product'), 201);
    }

    public function storeReturn(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1|max:1000000',
            'reason' => 'nullable|string|max:255',
        ]);
        $return = DB::transaction(function () use ($data, $request) {
            $order = Order::whereKey($data['order_id'])->lockForUpdate()->firstOrFail();
            abort_unless($order->status === 'completed', 422, 'Only completed sales can be returned.');
            $lines = $order->items()->where('product_id', $data['product_id'])->get();
            $sold = $lines->sum('qty');
            $prior = SaleReturn::where('order_id', $order->id)->where('product_id', $data['product_id']);
            $returned = (clone $prior)->sum('qty');
            abort_if(! $sold || $data['qty'] > $sold - $returned, 422, 'Return exceeds the remaining quantity sold.');
            $totalCents = $lines->sum(fn ($line) => Money::cents($line->line_total));
            $priorCents = Money::cents((clone $prior)->sum('value'));
            $value = $data['qty'] === $sold - $returned ? $totalCents - $priorCents : (int) round($totalCents * $data['qty'] / $sold);
            $return = SaleReturn::create([...$data, 'value' => $value / 100, 'created_by' => $request->user()->id]);
            Audit::record('sale.returned', $return, $data);

            return $return;
        });

        return response()->json($return->load('product'), 201);
    }

    /**
     * Website order webhook. Idempotent: the unique(source, external_ref) constraint
     * on `orders` means a retried delivery of the same website order won't double-process.
     *
     * Expected payload:
     * {
     *   "external_ref": "WEB-10234",
     *   "items": [{ "sku": "FINA-GRO-SERUM", "qty": 2, "unit_price": 970.00 }]
     * }
     */
    public function websiteWebhook(Request $request)
    {
        $this->verifyWebhookSignature($request);

        $data = $request->validate([
            'external_ref' => 'required|string|max:255',
            'items' => 'required|array|min:1|max:100',
            'items.*.sku' => 'required|string|max:100',
            'items.*.qty' => 'required|integer|min:1|max:1000000',
            'items.*.unit_price' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
        ]);

        // A unique source/reference key handles races; completion stays in the same transaction.
        try {
            $order = DB::transaction(function () use ($data) {
                $order = Order::where('source', 'website')->where('external_ref', $data['external_ref'])->lockForUpdate()->first();
                if (! $order) {
                    $order = Order::create(['source' => 'website', 'external_ref' => $data['external_ref'], 'status' => 'pending', 'webhook_payload' => $data]);
                }
                if ($order->status === 'pending') {
                    $this->reconcileOrder($order);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            $order = Order::where('source', 'website')->where('external_ref', $data['external_ref'])->firstOrFail();
            DB::transaction(fn () => $this->reconcileOrder($order));
        }

        return response()->json($order->fresh()->load('items.product'), 200);
    }

    public function reconcile(Request $request, Order $order)
    {
        abort_unless($order->source === 'website', 422, 'Only website orders can be reconciled here.');
        DB::transaction(function () use ($order) {
            $this->reconcileOrder($order);
            Audit::record('order.reconciled', $order);
        });

        return response()->json($order->fresh()->load('items.product'));
    }

    private function reconcileOrder(Order $order): void
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($order->status !== 'pending') {
            return;
        }
        $payload = $order->webhook_payload;
        if (! $payload) {
            abort(422, 'This legacy order has no original payload. Recover it from the source website before reconciliation.');
        }
        $resolved = [];
        $missing = [];
        foreach ($payload['items'] as $item) {
            $product = Product::where('sku', $item['sku'])->where('is_active', true)->first();
            if (! $product) {
                $missing[] = $item['sku'];

                continue;
            }
            $resolved[] = ['product_id' => $product->id, 'qty' => $item['qty'], 'unit_price' => $item['unit_price'],
                'line_total' => Money::multiply($item['unit_price'], $item['qty'])];
        }
        if ($missing) {
            $order->update(['notes' => 'Unmatched/inactive SKU(s): '.implode(', ', $missing)]);

            return;
        }
        $order->items()->delete();
        $order->items()->createMany($resolved);
        $order->load('items');
        $order->complete();
        $order->update(['notes' => null]);
    }

    private function verifyWebhookSignature(Request $request): void
    {
        $secret = config('services.website.webhook_secret');
        abort_unless(is_string($secret) && strlen(trim($secret)) >= 32, 503, 'Webhook integration is not configured.');
        $signature = $request->header('X-Webhook-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        abort_unless($signature && hash_equals($expected, $signature), 401, 'Invalid webhook signature');
    }
}
