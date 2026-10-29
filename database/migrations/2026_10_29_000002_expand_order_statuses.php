<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'delivered', 'completed', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('orders')
            ->whereIn('status', ['processing', 'delivered'])
            ->update(['status' => 'pending']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'completed', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }
};