<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SkuMappingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sku_mappings_table_is_created_with_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('sku_mappings'));
        $this->assertTrue(Schema::hasColumns('sku_mappings', [
            'stock_master_id',
            'shopee_item_id',
            'shopee_model_id',
            'tiktok_product_id',
            'tiktok_sku_id',
            'tiktok_sku_name',
            'seller_sku',
            'internal_image_url',
            'shopee_image_url',
            'tiktok_image_url',
            'notes',
            'created_at',
            'updated_at',
        ]));
    }
}
