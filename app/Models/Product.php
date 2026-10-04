<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sku', 'tax_rate', 'trade_price', 'unit', 'is_active'];

    protected $casts = [
        'tax_rate' => 'decimal:4',
        'trade_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Current stock quantity, always derived from stock_movements — never stored.
     * quantity column is already signed (+ for purchase/purchase_return, - for sale/sale_return).
     */
    public function currentStock(): int
    {
        return (int) $this->stockMovements()->sum('quantity');
    }
}
