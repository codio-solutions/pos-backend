<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    protected $fillable = ['source', 'external_ref', 'status', 'created_by', 'notes', 'webhook_payload'];

    protected $casts = ['webhook_payload' => 'array'];

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
        DB::transaction(function () {
            $locked = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed') {
                return;
            }
            abort_unless(
                in_array($locked->status, ['pending', 'processing', 'delivered'], true),
                422,
                'This order cannot be completed from its current status.'
            );
            $this->load('items');
            // Always acquire product locks in the same order across multi-line orders.
            $products = Product::whereIn('id', $this->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            abort_if($products->contains(fn ($p) => ! $p->is_active), 422, 'An order product is inactive.');
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
