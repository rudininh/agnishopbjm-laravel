<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceApiService;
use App\Services\MarketplaceFailureNotifier;
use App\Services\MarketplaceSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MarketplaceTargetSkuResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('tiktok_products');
        Schema::create('tiktok_products', function (Blueprint $table): void {
            $table->string('product_id');
            $table->string('sku_id');
            $table->string('seller_sku');
            $table->string('sku_name');
            $table->boolean('is_active')->default(true);
        });
        Schema::dropIfExists('shopee_product_model');
        Schema::dropIfExists('shopee_product');
        Schema::create('shopee_product', function (Blueprint $table): void {
            $table->string('item_id');
            $table->bigInteger('shop_id');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('shopee_product_model', function (Blueprint $table): void {
            $table->string('item_id');
            $table->string('model_id');
            $table->string('model_sku');
            $table->string('name');
        });
    }

    public function test_missing_tiktok_ids_resolve_from_unique_exact_active_sku(): void
    {
        DB::table('tiktok_products')->insert(['product_id' => '100', 'sku_id' => '200', 'seller_sku' => 'SKU-GREEN', 'sku_name' => 'Green']);
        $api = Mockery::mock(MarketplaceApiService::class);
        $api->shouldReceive('updateTiktokStockForAccount')->once()
            ->with('tiktok-agnishopbjm', '100', '200', 7, null, 'delivery-key')
            ->andReturn(['status' => 'success']);
        $result = $this->service($api)->pushTargetStockForAccount((object) ['internal_sku' => 'SKU-GREEN', 'variant_name' => 'Green'], 'tiktok-agnishopbjm', 7, true, 'delivery-key');
        $this->assertSame('success', $result['status']);
    }

    public function test_duplicate_sku_is_not_disambiguated_by_variant_name(): void
    {
        DB::table('tiktok_products')->insert([
            ['product_id' => '100', 'sku_id' => '200', 'seller_sku' => 'SKU-GREEN', 'sku_name' => 'Green'],
            ['product_id' => '101', 'sku_id' => '201', 'seller_sku' => 'SKU-GREEN', 'sku_name' => 'Green'],
        ]);
        $this->assertBlockedTiktok();
    }

    public function test_same_sku_with_different_variant_is_blocked(): void
    {
        DB::table('tiktok_products')->insert(['product_id' => '100', 'sku_id' => '200', 'seller_sku' => 'SKU-GREEN', 'sku_name' => 'Blue']);
        $this->assertBlockedTiktok();
    }

    public function test_inactive_sku_is_blocked(): void
    {
        DB::table('tiktok_products')->insert(['product_id' => '100', 'sku_id' => '200', 'seller_sku' => 'SKU-GREEN', 'sku_name' => 'Green', 'is_active' => false]);
        $this->assertBlockedTiktok();
    }

    public function test_gita_resolves_only_inside_its_own_shop_including_model_zero(): void
    {
        $this->seedShopee();
        $api = Mockery::mock(MarketplaceApiService::class);
        $api->shouldReceive('updateShopeeModelStockForAccount')->once()
            ->with('shopee-gitacollectionbjm', '302', '0', 7, 'delivery-key')
            ->andReturn(['status' => 'success']);
        $result = $this->service($api)->pushTargetStockForAccount((object) ['internal_sku' => 'SKU-GREEN', 'variant_name' => 'Green', 'shopee_product_id' => '301', 'shopee_sku' => '401'], 'shopee-gitacollectionbjm', 7, true, 'delivery-key');
        $this->assertSame('success', $result['status']);
    }

    public function test_gita_does_not_resolve_a_primary_only_catalog_match(): void
    {
        $this->seedShopee();
        DB::table('shopee_product')->where('item_id', '302')->delete();
        $api = Mockery::mock(MarketplaceApiService::class);
        $api->shouldNotReceive('updateShopeeModelStockForAccount');
        $result = $this->service($api)->pushTargetStockForAccount((object) ['internal_sku' => 'SKU-GREEN', 'variant_name' => 'Green'], 'shopee-gitacollectionbjm', 7, true);
        $this->assertSame('skipped', $result['status']);
    }

    private function seedShopee(): void
    {
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-gitacollectionbjm', 'shop_id' => 22, 'is_active' => true]);
        DB::table('shopee_product')->insert([['item_id' => '301', 'shop_id' => 11], ['item_id' => '302', 'shop_id' => 22]]);
        DB::table('shopee_product_model')->insert([
            ['item_id' => '301', 'model_id' => '401', 'model_sku' => 'SKU-GREEN', 'name' => 'Green'],
            ['item_id' => '302', 'model_id' => '0', 'model_sku' => 'SKU-GREEN', 'name' => 'Green'],
        ]);
    }

    private function assertBlockedTiktok(): void
    {
        $api = Mockery::mock(MarketplaceApiService::class);
        $api->shouldNotReceive('updateTiktokStockForAccount');
        $result = $this->service($api)->pushTargetStockForAccount((object) ['internal_sku' => 'SKU-GREEN', 'variant_name' => 'Green'], 'tiktok-agnishopbjm', 7, true);
        $this->assertSame('skipped', $result['status']);
    }

    private function service(MarketplaceApiService $api): MarketplaceSyncService
    {
        return new MarketplaceSyncService($api, Mockery::mock(MarketplaceFailureNotifier::class));
    }
}
