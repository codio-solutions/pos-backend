<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // purchase / sale_return add stock (positive), sale / purchase_return move stock out (negative)
            $table->enum('type', ['purchase', 'sale', 'sale_return', 'purchase_return']);

            $table->integer('quantity');       // signed: +qty for purchase/purchase_return, -qty for sale/sale_return
            $table->integer('bonus_qty')->default(0);
            $table->decimal('value', 14, 2)->default(0);

            // where this movement originated from
            $table->enum('source', ['manual', 'website', 'visit'])->default('manual');
            $table->string('reference_type')->nullable(); // e.g. App\Models\Order, App\Models\Purchase
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
