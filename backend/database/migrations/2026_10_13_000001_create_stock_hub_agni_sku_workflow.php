<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_hub_agni_sku_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('request_key')->unique(); $t->string('source_product_id',64)->index();
            $t->string('target_product_id',64)->index(); $t->string('status',32); $t->json('state'); $t->timestamps();
        });
        Schema::create('stock_hub_agni_sku_attempts', function (Blueprint $t): void {
            $t->id(); $t->uuid('run_id'); $t->foreign('run_id')->references('id')->on('stock_hub_agni_sku_runs')->cascadeOnDelete();
            $t->string('model_id',64); $t->string('name',500); $t->string('old_sku',100); $t->string('seller_sku',100);
            $t->string('status',32); $t->timestamp('attempted_at')->nullable(); $t->timestamp('verified_at')->nullable(); $t->timestamps(); $t->unique(['run_id','model_id']);
        });
        Schema::create('stock_hub_agni_sku_guards', function (Blueprint $t): void {
            $t->string('key',100)->primary(); $t->uuid('run_id')->nullable(); $t->uuid('owner')->nullable(); $t->timestamp('expires_at')->nullable();
        });
        Schema::table('stock_hub_tiktok_variant_batches', function (Blueprint $t): void { $t->string('workflow',64)->default('legacy'); });
        Schema::table('stock_hub_tiktok_variant_batch_items', function (Blueprint $t): void {
            $t->string('workflow_stage',32)->nullable(); $t->uuid('source_sku_request_key')->nullable(); $t->uuid('source_sku_run_id')->nullable();
            $t->uuid('repair_request_key')->nullable(); $t->uuid('repair_run_id')->nullable(); $t->json('source_sku_result')->nullable(); $t->json('repair_result')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('stock_hub_tiktok_variant_batch_items', function (Blueprint $t): void { $t->dropColumn(['workflow_stage','source_sku_request_key','source_sku_run_id','repair_request_key','repair_run_id','source_sku_result','repair_result']); });
        Schema::table('stock_hub_tiktok_variant_batches', function (Blueprint $t): void { $t->dropColumn('workflow'); });
        Schema::dropIfExists('stock_hub_agni_sku_guards'); Schema::dropIfExists('stock_hub_agni_sku_attempts'); Schema::dropIfExists('stock_hub_agni_sku_runs');
    }
};
