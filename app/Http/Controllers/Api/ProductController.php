<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(25);

        // attach current stock without N+1: sum stock_movements grouped by product_id in one query
        $stockByProduct = \App\Models\StockMovement::whereIn('product_id', $products->pluck('id'))
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $products->getCollection()->transform(function ($p) use ($stockByProduct) {
            $p->current_stock = (int) ($stockByProduct[$p->id] ?? 0);
            return $p;
        });

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100|unique:products,sku',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'trade_price' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:50',
        ]);

        $product = Product::create($data);

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sku' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'trade_price' => 'sometimes|required|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $product->update($data);

        return response()->json($product);
    }

    public function destroy(Product $product)
    {
        // soft-disable instead of hard delete — product may be referenced by historical stock_movements
        $product->update(['is_active' => false]);

        return response()->json(['message' => 'Product deactivated']);
    }
}
