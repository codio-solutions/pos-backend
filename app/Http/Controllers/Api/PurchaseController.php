<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $purchases = Purchase::with('product')
            ->when($request->product_id, fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(25);

        return response()->json($purchases);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1|max:1000000',
            'bonus_qty' => 'nullable|integer|min:0|max:1000000',
            'value' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'supplier' => 'nullable|string|max:255',
        ]);

        $data['created_by'] = $request->user()->id;

        // stock_movement is created automatically via the Purchase model's booted() hook
        $data['bonus_qty'] = $data['bonus_qty'] ?? 0;
        $purchase = DB::transaction(function () use ($data) {
            $purchase = Purchase::create($data);
            Audit::record('purchase.created', $purchase, $data);

            return $purchase;
        });

        return response()->json($purchase->load('product'), 201);
    }

    public function storeReturn(Request $request)
    {
        $data = $request->validate([
            'purchase_id' => 'required|exists:purchases,id',
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1|max:1000000',
            'reason' => 'nullable|string|max:255',
        ]);
        $return = DB::transaction(function () use ($data, $request) {
            $purchase = Purchase::whereKey($data['purchase_id'])->lockForUpdate()->firstOrFail();
            abort_unless($purchase->product_id == $data['product_id'], 422, 'Product does not belong to this purchase.');
            $prior = PurchaseReturn::where('purchase_id', $purchase->id);
            $total = $purchase->qty + $purchase->bonus_qty;
            $remaining = $total - (clone $prior)->sum('qty');
            abort_if($data['qty'] > $remaining, 422, 'Return exceeds the remaining quantity purchased.');
            $cents = Money::cents($purchase->value);
            $value = $data['qty'] === $remaining ? $cents - Money::cents((clone $prior)->sum('value')) : (int) round($cents * $data['qty'] / $total);
            $return = PurchaseReturn::create([...$data, 'value' => $value / 100, 'created_by' => $request->user()->id]);
            Audit::record('purchase.returned', $return, $data);

            return $return;
        });

        return response()->json($return->load('product'), 201);
    }
}
