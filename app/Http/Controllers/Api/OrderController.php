<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
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
                    'line_total' => $item['qty'] * $item['unit_price'],
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
            'order_id' => 'nullable|exists:orders,id',
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1',
            'value' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $data['created_by'] = $request->user()->id;

        $return = SaleReturn::create($data);

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
            'external_ref' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.sku' => 'required|string',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        // idempotency: if we've already processed this website order, return it as-is
        $existing = Order::where('source', 'website')->where('external_ref', $data['external_ref'])->first();
        if ($existing) {
            return response()->json($existing->load('items.product'));
        }

        $unmatchedSkus = [];

        $order = DB::transaction(function () use ($data, &$unmatchedSkus) {
            $order = Order::create([
                'source' => 'website',
                'external_ref' => $data['external_ref'],
                'status' => 'pending',
            ]);

            foreach ($data['items'] as $item) {
                $product = Product::where('sku', $item['sku'])->first();

                if (!$product) {
                    $unmatchedSkus[] = $item['sku'];
                    continue;
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['qty'] * $item['unit_price'],
                ]);
            }

            return $order;
        });

        if (!empty($unmatchedSkus)) {
            // flagged for manual reconciliation on the Orders page rather than silently failing
            $order->update(['notes' => 'Unmatched SKU(s): ' . implode(', ', $unmatchedSkus)]);
            Log::warning('Website order has unmatched SKUs', ['order_id' => $order->id, 'skus' => $unmatchedSkus]);
        } else {
            $order->load('items');
            $order->complete();
        }

        return response()->json($order->load('items.product'), 201);
    }

    private function verifyWebhookSignature(Request $request): void
    {
        $signature = $request->header('X-Webhook-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), config('services.website.webhook_secret'));

        abort_unless($signature && hash_equals($expected, $signature), 401, 'Invalid webhook signature');
    }
}
