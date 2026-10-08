<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shifts')) {
            throw new RuntimeException('The shifts table is missing; cannot align legacy shift columns.');
        }

        if (!Schema::hasColumn('shifts', 'employee_id')) {
            if (!Schema::hasColumn('shifts', 'user_id')) {
                throw new RuntimeException('The shifts table has neither employee_id nor user_id.');
            }

            Schema::table('shifts', function (Blueprint $table) {
                $table->foreignId('employee_id')->nullable()->constrained('users')->cascadeOnDelete();
            });
        }

        if (Schema::hasColumn('shifts', 'user_id')) {
            DB::table('shifts')
                ->whereNull('employee_id')
                ->update(['employee_id' => DB::raw('user_id')]);
        }

        foreach ([
            'check_in_latitude' => 'check_in_lat',
            'check_in_longitude' => 'check_in_lng',
            'check_out_latitude' => 'check_out_lat',
            'check_out_longitude' => 'check_out_lng',
        ] as $legacyColumn => $column) {
            if (!Schema::hasColumn('shifts', $column)) {
                Schema::table('shifts', function (Blueprint $table) use ($column) {
                    $table->decimal($column, 10, 7)->nullable();
                });
            }

            if (Schema::hasColumn('shifts', $legacyColumn)) {
                DB::table('shifts')
                    ->whereNull($column)
                    ->update([$column => DB::raw($legacyColumn)]);
            }
        }
    }

    public function down(): void
    {
    }
};