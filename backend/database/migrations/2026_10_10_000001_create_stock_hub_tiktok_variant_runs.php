<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_hub_tiktok_variant_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('request_key')->unique(); $t->string('request_hash', 64);
            $t->string('source_product_id', 64)->index(); $t->string('target_product_id', 64)->nullable()->index();
            $t->string('source_account')->default('shopee-agnishopbjm'); $t->string('target_account')->default('tiktok-agnishopbjm');
            $t->string('status', 32); $t->json('state'); $t->timestamp('attempted_at')->nullable(); $t->timestamp('accepted_at')->nullable(); $t->timestamps();
        });
        Schema::create('stock_hub_tiktok_variant_guards', function (Blueprint $t): void {
            $t->string('key', 100)->primary(); $t->uuid('run_id')->nullable(); $t->uuid('owner')->nullable(); $t->timestamp('expires_at')->nullable();
        });
        Schema::create('stock_hub_tiktok_variant_legacy_attempts', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->string('target_product_id', 64)->index(); $t->string('status', 32); $t->json('seller_skus'); $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_hub_tiktok_variant_legacy_attempts');
        Schema::dropIfExists('stock_hub_tiktok_variant_guards'); Schema::dropIfExists('stock_hub_tiktok_variant_runs');
    }
};
