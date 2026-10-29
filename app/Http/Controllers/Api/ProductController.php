<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->when($request->input('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->input('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(! $request->has('status') && $request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($nested) => $nested->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
            ->orderBy('name')
            ->paginate(25);

        if (! $request->boolean('with_stock', true)) {
            return response()->json($products);
        }

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
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'trade_price' => 'required|numeric|min:0|max:9999999999.99|decimal:0,2',
            'unit' => 'nullable|string|max:50',
            'initial_stock_qty' => 'sometimes|integer|min:0|max:1000000',
            'initial_stock_value' => 'required_with:initial_stock_qty|numeric|min:0|max:9999999999.99|decimal:0,2',
        ]);

        $data['initial_stock_qty'] = $data['initial_stock_qty'] ?? 0;
        $data['initial_stock_value'] = $data['initial_stock_value'] ?? 0;

        abort_if(
            (int) $data['initial_stock_qty'] === 0 && (float) $data['initial_stock_value'] > 0,
            422,
            'Initial stock cost must be 0 when initial stock quantity is 0.'
        );

        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create([
                'name' => $data['name'],
                'sku' => $this->generateSku($data['name']),
                'tax_rate' => $data['tax_rate'] ?? 0,
                'trade_price' => $data['trade_price'],
                'unit' => $data['unit'] ?? 'pcs',
            ]);

            Audit::record('product.created', $product);

            if ($data['initial_stock_qty'] > 0) {
                $purchase = Purchase::create([
                    'product_id' => $product->id,
                    'qty' => $data['initial_stock_qty'],
                    'bonus_qty' => 0,
                    'value' => $data['initial_stock_value'],
                    'supplier' => 'Opening stock',
                    'created_by' => $request->user()->id,
                ]);
                Audit::record('purchase.created', $purchase, [
                    'opening_stock' => true,
                    'qty' => $data['initial_stock_qty'],
                    'value' => $data['initial_stock_value'],
                ]);
            }

            return $product;
        });

        $product->current_stock = $product->currentStock();

        return response()->json($product, 201);
    }

    private function generateSku(string $name): string
    {
        $base = Str::upper(Str::slug($name));
        $base = $base !== '' ? Str::limit($base, 90, '') : 'PRODUCT';
        $sku = $base;
        $suffix = 2;

        while (Product::where('sku', $sku)->exists()) {
            $sku = Str::limit($base, 90 - strlen((string) $suffix), '').'-'.$suffix;
            $suffix++;
        }

        return $sku;
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
