<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_hub_tiktok_variant_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('request_key')->unique();
            $t->string('status', 32); $t->boolean('pause_requested')->default(false);
            $t->string('message', 500); $t->timestamps();
        });
        Schema::create('stock_hub_tiktok_variant_batch_items', function (Blueprint $t): void {
            $t->id(); $t->foreignUuid('batch_id')->constrained('stock_hub_tiktok_variant_batches')->cascadeOnDelete();
            $t->unsignedInteger('position'); $t->string('source_product_id', 64); $t->string('name', 500);
            $t->uuid('request_key')->unique(); $t->uuid('child_run_id')->nullable();
            $t->string('status', 32); $t->unsignedInteger('missing_count')->nullable();
            $t->string('message', 500); $t->json('result')->nullable();
            $t->boolean('processed')->default(false); $t->bigInteger('retry_at')->nullable(); $t->timestamps();
            $t->unique(['batch_id', 'source_product_id']); $t->unique(['batch_id', 'position']);
        });
        Schema::create('stock_hub_tiktok_variant_batch_requests', function (Blueprint $t): void {
            $t->uuid('request_key')->primary();
            $t->foreignUuid('batch_id')->constrained('stock_hub_tiktok_variant_batches')->cascadeOnDelete();
        });
        Schema::create('stock_hub_tiktok_variant_batch_claims', function (Blueprint $t): void {
            $t->string('key', 32)->primary(); $t->uuid('run_id')->nullable();
            $t->uuid('owner')->nullable(); $t->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_hub_tiktok_variant_batch_claims');
        Schema::dropIfExists('stock_hub_tiktok_variant_batch_requests');
        Schema::dropIfExists('stock_hub_tiktok_variant_batch_items');
        Schema::dropIfExists('stock_hub_tiktok_variant_batches');
    }
};
