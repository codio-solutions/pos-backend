<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Http\Request;

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
            'qty' => 'required|integer|min:1',
            'bonus_qty' => 'nullable|integer|min:0',
            'value' => 'required|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
        ]);

        $data['created_by'] = $request->user()->id;

        // stock_movement is created automatically via the Purchase model's booted() hook
        $purchase = Purchase::create($data);

        return response()->json($purchase->load('product'), 201);
    }

    public function storeReturn(Request $request)
    {
        $data = $request->validate([
            'purchase_id' => 'nullable|exists:purchases,id',
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1',
            'value' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $data['created_by'] = $request->user()->id;

        $return = PurchaseReturn::create($data);

        return response()->json($return->load('product'), 201);
    }
}
