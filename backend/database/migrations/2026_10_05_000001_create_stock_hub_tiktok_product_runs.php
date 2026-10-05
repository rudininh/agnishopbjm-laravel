<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_hub_tiktok_product_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('request_key')->unique(); $t->string('request_hash', 64);
            $t->string('source_product_id', 64)->index(); $t->string('source_account')->default('shopee-agnishopbjm');
            $t->string('target_account')->default('tiktok-agnishopbjm'); $t->string('status', 32);
            $t->json('state'); $t->timestamp('attempted_at')->nullable(); $t->string('remote_product_id', 64)->nullable(); $t->timestamps();
        });
        Schema::create('stock_hub_tiktok_product_guards', function (Blueprint $t): void {
            $t->string('source_product_id', 64)->primary(); $t->uuid('run_id')->nullable();
            $t->uuid('owner')->nullable(); $t->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_hub_tiktok_product_guards'); Schema::dropIfExists('stock_hub_tiktok_product_runs');
    }
};
