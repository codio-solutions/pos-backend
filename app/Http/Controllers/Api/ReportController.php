<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Sales & Stock Report: opening, purchase, sale, sale return, net sale, closing —
     * per product, for a given date range. Everything derived live from stock_movements,
     * matching the report format used before this system (opening + purchase - net sale = closing).
     */
    public function salesAndStock(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = $request->from;
        $to = $request->to;

        $products = Product::where('is_active', true)->get();

        $rows = $products->map(function (Product $product) use ($from, $to) {
            // opening = everything before the range start
            $opening = StockMovement::where('product_id', $product->id)
                ->whereDate('created_at', '<', $from)
                ->sum('quantity');

            $openingValue = StockMovement::where('product_id', $product->id)
                ->whereDate('created_at', '<', $from)
                ->where('quantity', '>', 0) // value tracked on incoming movements
                ->sum('value');

            $movements = StockMovement::where('product_id', $product->id)
                ->whereDate('created_at', '>=', $from)
                ->whereDate('created_at', '<=', $to)
                ->get();

            $purchaseQty = $movements->where('type', 'purchase')->sum('quantity');
            $purchaseValue = $movements->where('type', 'purchase')->sum('value');
            $purchaseBonus = $movements->where('type', 'purchase')->sum('bonus_qty');

            $saleQty = abs($movements->where('type', 'sale')->sum('quantity'));
            $saleValue = abs($movements->where('type', 'sale')->sum('value'));

            $saleReturnQty = $movements->where('type', 'sale_return')->sum('quantity');
            $saleReturnValue = $movements->where('type', 'sale_return')->sum('value');

            $purchaseReturnQty = abs($movements->where('type', 'purchase_return')->sum('quantity'));

            $netSaleQty = $saleQty - $saleReturnQty;
            $netSaleValue = $saleValue - $saleReturnValue;

            $closingQty = $opening + $purchaseQty - $purchaseReturnQty - $netSaleQty;
            $closingValue = $openingValue + $purchaseValue - $netSaleValue;

            return [
                'product' => $product->name,
                'sku' => $product->sku,
                'trade_price' => $product->trade_price,
                'opening' => ['qty' => $opening, 'value' => round($openingValue, 2)],
                'purchase' => ['qty' => $purchaseQty, 'bonus' => $purchaseBonus, 'value' => round($purchaseValue, 2)],
                'purchase_return' => ['qty' => $purchaseReturnQty],
                'sale' => ['qty' => $saleQty, 'value' => round($saleValue, 2)],
                'sale_return' => ['qty' => $saleReturnQty, 'value' => round($saleReturnValue, 2)],
                'net_sale' => ['qty' => $netSaleQty, 'value' => round($netSaleValue, 2)],
                'closing' => ['qty' => $closingQty, 'value' => round($closingValue, 2)],
            ];
        });

        return response()->json([
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'grand_total' => [
                'opening_qty' => $rows->sum('opening.qty'),
                'purchase_qty' => $rows->sum('purchase.qty'),
                'sale_qty' => $rows->sum('sale.qty'),
                'sale_return_qty' => $rows->sum('sale_return.qty'),
                'net_sale_qty' => $rows->sum('net_sale.qty'),
                'closing_qty' => $rows->sum('closing.qty'),
                'closing_value' => round($rows->sum('closing.value'), 2),
            ],
        ]);
    }
}
