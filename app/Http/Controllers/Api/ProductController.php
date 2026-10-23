<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($nested) => $nested->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
            ->orderBy('name')
            ->paginate(25);

        // attach current stock without N+1: sum stock_movements grouped by product_id in one query
        $stockByProduct = StockMovement::whereIn('product_id', $products->pluck('id'))
            ->selectRaw("product_id, SUM(quantity + CASE WHEN type = 'purchase' THEN bonus_qty ELSE 0 END) as qty")
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
            'trade_price' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'unit' => 'nullable|string|max:50',
        ]);

        $product = Product::create($data);

        return response()->json($product, 201);
    }

    public function show(Product $product)
    {
        $product->current_stock = $product->currentStock();

        return response()->json($product);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sku' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'trade_price' => 'sometimes|required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'unit' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($product, $data) {
            $before = $product->only(array_keys($data));
            $product->update($data);
            Audit::record('product.updated', $product, ['before' => $before, 'after' => $data]);
        });

        return response()->json($product);
    }

    public function destroy(Product $product)
    {
        // soft-disable instead of hard delete — product may be referenced by historical stock_movements
        DB::transaction(function () use ($product) {
            $product->update(['is_active' => false]);
            Audit::record('product.deactivated', $product);
        });

        return response()->json(['message' => 'Product deactivated']);
    }
}
