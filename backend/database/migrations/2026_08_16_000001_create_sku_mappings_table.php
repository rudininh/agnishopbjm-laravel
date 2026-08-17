<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sku_mappings')) {
            return;
        }

        Schema::create('sku_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_master_id')->unique();
            $table->text('shopee_item_id')->nullable();
            $table->text('shopee_model_id')->nullable();
            $table->text('tiktok_product_id')->nullable();
            $table->text('tiktok_sku_id')->nullable();
            $table->text('tiktok_sku_name')->nullable();
            $table->text('seller_sku')->nullable();
            $table->text('internal_image_url')->nullable();
            $table->text('shopee_image_url')->nullable();
            $table->text('tiktok_image_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_mappings');
    }
};
