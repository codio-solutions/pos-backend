<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturn extends Model
{
    protected $fillable = ['purchase_id', 'product_id', 'qty', 'value', 'reason', 'created_by'];

    protected static function booted(): void
    {
        static::created(function (PurchaseReturn $return) {
            StockMovement::record([
                'product_id' => $return->product_id,
                'type' => 'purchase_return',
                'quantity' => $return->qty,
                'value' => $return->value,
                'source' => 'manual',
                'reference_type' => self::class,
                'reference_id' => $return->id,
                'created_by' => $return->created_by,
            ]);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
