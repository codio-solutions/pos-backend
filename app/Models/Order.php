<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    protected $fillable = ['source', 'external_ref', 'status', 'created_by', 'notes'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Marks the order completed and writes one stock_movement (type: sale)
     * per order_item. Wrapped in a transaction so stock never gets
     * partially decremented if something fails mid-way.
     *
     * Safe to call from: POS manual sale, website webhook, or visit order capture.
     */
    public function complete(): void
    {
        if ($this->status === 'completed') {
            return; // idempotent — a retried webhook won't double-decrement stock
        }

        DB::transaction(function () {
            foreach ($this->items as $item) {
                StockMovement::record([
                    'product_id' => $item->product_id,
                    'type' => 'sale',
                    'quantity' => $item->qty,
                    'value' => $item->line_total,
                    'source' => $this->source,
                    'reference_type' => self::class,
                    'reference_id' => $this->id,
                    'created_by' => $this->created_by,
                ]);
            }

            $this->update(['status' => 'completed']);
        });
    }
}
