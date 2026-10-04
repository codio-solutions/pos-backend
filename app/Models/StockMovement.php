<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id', 'type', 'quantity', 'bonus_qty', 'value',
        'source', 'reference_type', 'reference_id', 'created_by',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Helper to write a movement with the correct sign baked in by type.
     * Purchase / sale_return: stock increases (+) — goods coming in.
     * Sale / purchase_return: stock decreases (-) — goods going out.
     */
    public static function record(array $data): self
    {
        $incoming = in_array($data['type'], ['purchase', 'sale_return']);
        $qty = abs($data['quantity']);

        return self::create([
            'product_id' => $data['product_id'],
            'type' => $data['type'],
            'quantity' => $incoming ? $qty : -$qty,
            'bonus_qty' => $data['bonus_qty'] ?? 0,
            'value' => $data['value'] ?? 0,
            'source' => $data['source'] ?? 'manual',
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }
}
