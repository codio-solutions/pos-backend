<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    protected $fillable = ['product_id', 'qty', 'bonus_qty', 'value', 'supplier', 'created_by'];

    protected static function booted(): void
    {
        static::created(function (Purchase $purchase) {
            StockMovement::record([
                'product_id' => $purchase->product_id,
                'type' => 'purchase',
                'quantity' => $purchase->qty,
                'bonus_qty' => $purchase->bonus_qty,
                'value' => $purchase->value,
                'source' => 'manual',
                'reference_type' => self::class,
                'reference_id' => $purchase->id,
                'created_by' => $purchase->created_by,
            ]);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
