<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('doctor_photo_path')->nullable()->after('lng');
            $table->string('building_photo_path')->nullable()->after('doctor_photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['doctor_photo_path', 'building_photo_path']);
        });
    }
};
