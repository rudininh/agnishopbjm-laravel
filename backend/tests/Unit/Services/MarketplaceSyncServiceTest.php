<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceApiService;
use App\Services\MarketplaceFailureNotifier;
use App\Services\MarketplaceSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MarketplaceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('shopee_product_model')) {
            Schema::create('shopee_product_model', function (Blueprint $table): void {
                $table->string('model_id');
                $table->string('item_id');
                $table->integer('stock')->default(0);
                $table->timestamps();
                $table->primary(['model_id', 'item_id']);
            });
        }

        if (! Schema::hasTable('stock_master')) {
            Schema::create('stock_master', function (Blueprint $table): void {
                $table->id();
                $table->string('internal_sku')->unique();
                $table->string('shopee_product_id')->nullable();
                $table->string('shopee_sku')->nullable();
                $table->string('shopee_seller_sku')->nullable();
                $table->integer('stock_qty')->default(0);
                $table->string('tiktok_product_id')->nullable();
                $table->string('tiktok_sku')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tiktok_products')) {
            Schema::create('tiktok_products', function (Blueprint $table): void {
                $table->id();
                $table->string('product_id');
                $table->string('sku_id');
                $table->string('seller_sku')->nullable();
                $table->integer('stock_qty')->default(0);
                $table->string('warehouse_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tiktok_tokens')) {
            Schema::create('tiktok_tokens', function (Blueprint $table): void {
                $table->id();
                $table->string('access_token')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tiktok_shops')) {
            Schema::create('tiktok_shops', function (Blueprint $table): void {
                $table->id();
                $table->string('cipher')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_shopee_push_uses_the_configured_account_when_another_account_token_is_newer(): void
    {
        config([
            'shopee.account_key' => 'shopee-agnishopbjm',
            'shopee.host' => 'https://shopee.test',
            'shopee.partner_id' => 123,
            'shopee.partner_key' => 'test-partner-key',
        ]);

        DB::table('shopee_product_model')->insert([
            'item_id' => '101',
            'model_id' => '202',
            'stock' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('shopee_tokens')->insert([
            [
                'account_key' => 'shopee-agnishopbjm',
                'account_name' => 'Shopee AgniShopBJM',
                'shop_id' => 111,
                'access_token' => 'agnishop-token',
                'refresh_token' => 'agnishop-refresh',
                'is_active' => DB::raw('true'),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'account_key' => 'shopee-gitacollectionbjm',
                'account_name' => 'Shopee Gita Collection BJM',
                'shop_id' => 222,
                'access_token' => 'gita-token',
                'refresh_token' => 'gita-refresh',
                'is_active' => DB::raw('true'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Http::fake([
            'https://shopee.test/*' => Http::response(['error' => '', 'response' => []]),
        ]);

        $service = new MarketplaceSyncService(
            Mockery::mock(MarketplaceApiService::class),
            Mockery::mock(MarketplaceFailureNotifier::class),
        );

        $result = $service->pushTargetStock((object) [
            'shopee_product_id' => '101',
            'shopee_sku' => '202',
        ], 'shopee', 8, true);

        $this->assertSame('success', $result['status']);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['shop_id'] ?? null) === '111'
                && ($query['access_token'] ?? null) === 'agnishop-token';
        });
    }

    public function test_gita_push_does_not_fallback_to_primary_shopee_mapping(): void
    {
        $apiService = Mockery::mock(MarketplaceApiService::class);
        $apiService->shouldNotReceive('updateShopeeModelStockForAccount');

        $service = new MarketplaceSyncService(
            $apiService,
            Mockery::mock(MarketplaceFailureNotifier::class),
        );

        $result = $service->pushTargetStockForAccount((object) [
            'shopee_product_id' => '53016356558',
            'shopee_sku' => '302891262876',
        ], 'shopee-gitacollectionbjm', 3, true);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('Push Shopee dibatalkan: item/model target belum lengkap.', $result['message']);
    }

    public function test_primary_shopee_push_keeps_legacy_mapping_fallback(): void
    {
        $apiService = Mockery::mock(MarketplaceApiService::class);
        $apiService
            ->shouldReceive('updateShopeeModelStockForAccount')
            ->once()
            ->with('shopee-agnishopbjm', '53016356558', '302891262876', 3, null)
            ->andReturn(['status' => 'success', 'message' => 'ok']);

        $service = new MarketplaceSyncService(
            $apiService,
            Mockery::mock(MarketplaceFailureNotifier::class),
        );

        $result = $service->pushTargetStockForAccount((object) [
            'shopee_product_id' => '53016356558',
            'shopee_sku' => '302891262876',
        ], 'shopee-agnishopbjm', 3, true);

        $this->assertSame('success', $result['status']);
    }

    public function test_tiktok_push_uses_cached_sku_warehouse_when_default_configuration_is_empty(): void
    {
        config([
            'tiktok.app_key' => 'test-app-key',
            'tiktok.app_secret' => 'test-app-secret',
            'tiktok.api_host' => 'https://tiktok.test',
            'tiktok.default_warehouse_id' => '',
        ]);

        DB::table('tiktok_products')->insert([
            'product_id' => 'product-1',
            'sku_id' => 'sku-1',
            'seller_sku' => 'INT-TEST-1',
            'stock_qty' => 2,
            'warehouse_id' => 'warehouse-1',
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tiktok_tokens')->insert([
            'access_token' => 'test-access-token',
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tiktok_shops')->insert([
            'cipher' => 'test-shop-cipher',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://tiktok.test/*' => Http::response(['code' => 0]),
        ]);

        $service = new MarketplaceSyncService(
            Mockery::mock(MarketplaceApiService::class),
            Mockery::mock(MarketplaceFailureNotifier::class),
        );

        $result = $service->pushTargetStock((object) [
            'tiktok_product_id' => 'product-1',
            'tiktok_sku' => 'sku-1',
        ], 'tiktok', 7, true);

        $this->assertSame('success', $result['status']);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return data_get($payload, 'skus.0.id') === 'sku-1'
                && data_get($payload, 'skus.0.inventory.0.warehouse_id') === 'warehouse-1'
                && data_get($payload, 'skus.0.inventory.0.quantity') === 7;
        });
    }

    public function test_mirror_updates_the_resolved_tiktok_sku_cache_when_mapping_ids_are_stale(): void
    {
        $stockMasterId = DB::table('stock_master')->insertGetId([
            'internal_sku' => 'INT-TEST-STALE',
            'shopee_product_id' => '101',
            'shopee_sku' => '202',
            'stock_qty' => 3,
            'tiktok_product_id' => 'stale-product',
            'tiktok_sku' => 'stale-sku',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tiktok_products')->insert([
            'product_id' => 'resolved-product',
            'sku_id' => 'resolved-sku',
            'seller_sku' => 'INT-TEST-STALE',
            'stock_qty' => 3,
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $apiService = Mockery::mock(MarketplaceApiService::class);
        $apiService->shouldReceive('fetchShopeeModelStock')
            ->once()
            ->with('101', '202')
            ->andReturn(['status' => 'success', 'stock' => 1]);
        $failureNotifier = Mockery::mock(MarketplaceFailureNotifier::class);
        $failureNotifier->shouldReceive('notifySyncLog')->once()->andReturnNull();
        $service = new MarketplaceSyncService($apiService, $failureNotifier);

        $result = $service->mirrorShopeeStockToTiktok((object) [
            'id' => $stockMasterId,
            'internal_sku' => 'INT-TEST-STALE',
            'shopee_product_id' => '101',
            'shopee_sku' => '202',
            'tiktok_product_id' => 'stale-product',
            'tiktok_sku' => 'stale-sku',
            'tiktok_stock' => 3,
        ], 'Uji mapping lama');

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, DB::table('tiktok_products')
            ->where('product_id', 'resolved-product')
            ->where('sku_id', 'resolved-sku')
            ->value('stock_qty'));
    }

    public function test_tiktok_sku_resolver_ignores_an_ambiguous_shopee_seller_sku_fallback(): void
    {
        DB::table('stock_master')->insert([
            'internal_sku' => 'INT-TEST-SOFT-GREY',
            'shopee_seller_sku' => 'INT-TEST-SOFT-GREY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tiktok_products')->insert([
            'product_id' => 'product-soft-grey',
            'sku_id' => 'sku-soft-grey',
            'seller_sku' => 'INT-TEST-SOFT-GREY',
            'stock_qty' => 1,
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new MarketplaceSyncService(
            Mockery::mock(MarketplaceApiService::class),
            Mockery::mock(MarketplaceFailureNotifier::class),
        );
        $method = new \ReflectionMethod($service, 'resolveTiktokSku');

        $resolved = $method->invoke($service, (object) [
            'id' => 999,
            'internal_sku' => 'INT-TEST-BEIGI',
            'shopee_seller_sku' => 'INT-TEST-SOFT-GREY',
        ]);

        $this->assertNull($resolved);
    }

    public function test_ambiguous_shopee_seller_sku_returns_tiktok_sku_for_manual_mapping_review(): void
    {
        DB::table('stock_master')->insert([
            'internal_sku' => 'INT-TEST-BURGANDY',
            'shopee_seller_sku' => 'INT-TEST-BURGANDY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tiktok_products')->insert([
            'product_id' => 'product-burgandy',
            'sku_id' => 'sku-burgandy',
            'seller_sku' => 'INT-TEST-BURGANDY',
            'stock_qty' => 0,
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new MarketplaceSyncService(
            Mockery::mock(MarketplaceApiService::class),
            Mockery::mock(MarketplaceFailureNotifier::class),
        );
        $method = new \ReflectionMethod($service, 'ambiguousTiktokSkuForShopeeSellerSku');

        $resolved = $method->invoke($service, (object) [
            'internal_sku' => 'INT-TEST-SEAMESON',
            'shopee_seller_sku' => 'INT-TEST-BURGANDY',
        ]);

        $this->assertSame('product-burgandy', $resolved->product_id);
        $this->assertSame('sku-burgandy', $resolved->sku_id);
    }
}
