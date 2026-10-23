<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('shifts')->whereNull('check_out_at')->select('employee_id')->groupBy('employee_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicate) {
            throw new RuntimeException('Resolve duplicate open shifts before enabling the unique open-shift constraint.');
        }
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('open_employee_id')->nullable()->storedAs('CASE WHEN check_out_at IS NULL THEN employee_id ELSE NULL END');
            $table->unique('open_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique(['open_employee_id']);
            $table->dropColumn('open_employee_id');
        });
    }
};
