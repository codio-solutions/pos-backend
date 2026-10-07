<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('check_in_at');
            $table->dateTime('check_out_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'check_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};