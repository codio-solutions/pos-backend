<?php

namespace App\Support;

use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use Carbon\Carbon;

class InventoryReport
{
    public function generate(string $from, string $to): array
    {
        $end = Carbon::parse($to)->endOfDay();
        $start = Carbon::parse($from)->startOfDay();
        $products = Product::where('is_active', true)->orWhereHas('stockMovements', fn ($q) => $q->where('created_at', '<=', $end))->get();
        $returns = SaleReturn::where('created_at', '<=', $end)->pluck('order_id', 'id');
        $state = [];
        $rows = [];
        $saleCosts = [];
        $warnings = [];
        foreach ($products as $p) {
            $state[$p->id] = ['qty' => 0, 'cost' => 0];
            $rows[$p->id] = ['product' => $p->name, 'sku' => $p->sku, 'trade_price' => $p->trade_price];
            foreach (['opening', 'purchase', 'purchase_return', 'sale', 'sale_return', 'net_sale', 'closing'] as $c) {
                $rows[$p->id][$c] = ['qty' => 0, 'value' => 0];
            }
            $rows[$p->id]['purchase']['bonus'] = 0;
        }
        // Stream history once for all products, in deterministic ledger order.
        foreach (StockMovement::where('created_at', '<=', $end)->orderBy('created_at')->orderBy('id')->cursor() as $m) {
            if (! isset($state[$m->product_id])) {
                continue;
            }
            $s = &$state[$m->product_id];
            $r = &$rows[$m->product_id];
            $qty = abs($m->quantity) + ($m->type === 'purchase' ? $m->bonus_qty : 0);
            $value = Money::cents($m->value);
            $average = $s['qty'] > 0 ? $s['cost'] / $s['qty'] : 0;
            if ($m->type === 'purchase') {
                $s['qty'] += $qty;
                $s['cost'] += $value;
            } elseif ($m->type === 'sale_return') {
                $key = ($returns[$m->reference_id] ?? 'unknown').':'.$m->product_id;
                $cost = $saleCosts[$key] ?? null;
                $unitCost = $cost && $cost['qty'] > 0 ? $cost['cost'] / $cost['qty'] : $average;
                if (! $cost) {
                    $warnings['unlinked_returns'] = 'Some historical returns have no original sale cost; the prevailing average cost was used.';
                }
                $s['qty'] += $qty;
                $s['cost'] += (int) round($unitCost * $qty);
            } else {
                $outCost = $qty === $s['qty'] ? $s['cost'] : (int) round($average * $qty);
                if ($qty > $s['qty']) {
                    $warnings['negative_stock'] = 'Historical movements contain negative stock. Reconcile them before relying on valuation.';
                }
                if ($m->type === 'sale') {
                    $key = $m->reference_id.':'.$m->product_id;
                    $previous = $saleCosts[$key] ?? ['qty' => 0, 'cost' => 0];
                    $saleCosts[$key] = ['qty' => $previous['qty'] + $qty, 'cost' => $previous['cost'] + $outCost];
                }
                $s['qty'] -= $qty;
                $s['cost'] -= $outCost;
                if ($s['qty'] === 0) {
                    $s['cost'] = 0;
                }
            }
            if ($m->created_at < $start) {
                $r['opening'] = ['qty' => $s['qty'], 'value' => $s['cost']];
            } else {
                $r[$m->type]['qty'] += $qty;
                $r[$m->type]['value'] += $value;
                if ($m->type === 'purchase') {
                    $r['purchase']['bonus'] += $m->bonus_qty;
                }
            }
            unset($s, $r);
        }
        foreach ($rows as $id => &$r) {
            $r['closing'] = ['qty' => $state[$id]['qty'], 'value' => $state[$id]['cost']];
            $r['net_sale'] = ['qty' => $r['sale']['qty'] - $r['sale_return']['qty'], 'value' => $r['sale']['value'] - $r['sale_return']['value']];
            foreach (['opening', 'purchase', 'purchase_return', 'sale', 'sale_return', 'net_sale', 'closing'] as $c) {
                $r[$c]['value'] /= 100;
            }
        }
        unset($r);
        $collection = collect(array_values($rows));

        return ['from' => $from, 'to' => $to, 'rows' => $collection, 'warnings' => array_values($warnings),
            'valuation_method' => 'Moving weighted-average cost. Returns restore original average sale cost where available.',
            'grand_total' => [
                'opening_qty' => $collection->sum('opening.qty'), 'purchase_qty' => $collection->sum('purchase.qty'),
                'sale_qty' => $collection->sum('sale.qty'), 'sale_return_qty' => $collection->sum('sale_return.qty'),
                'net_sale_qty' => $collection->sum('net_sale.qty'), 'closing_qty' => $collection->sum('closing.qty'),
                'closing_value' => round($collection->sum('closing.value'), 2),
            ]];
    }
}
