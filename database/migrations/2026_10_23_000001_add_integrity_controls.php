<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        Schema::table('orders', fn (Blueprint $t) => $t->json('webhook_payload')->nullable());
        Schema::create('request_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('request_key', 100);
            $t->string('fingerprint', 64);
            $t->unsignedSmallInteger('status')->nullable();
            $t->longText('response')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->json('details')->nullable();
            $t->timestamp('created_at');
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('request_receipts');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('webhook_payload'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_active'));
    }
};
