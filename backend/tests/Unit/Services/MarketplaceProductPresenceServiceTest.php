<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceProductPresenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceProductPresenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['shopee-agnishopbjm' => 11, 'shopee-gitacollectionbjm' => 22] as $key => $shop) {
            DB::table('shopee_tokens')->insert(['account_key' => $key, 'shop_id' => $shop, 'is_active' => true, 'created_at' => now()]);
        }
        Schema::create('shopee_product', function (Blueprint $t) {
            $t->id(); $t->string('item_id'); $t->string('shop_id'); $t->boolean('is_active')->default(true);
        });
        Schema::create('shopee_product_model', function (Blueprint $t) {
            $t->id(); $t->string('item_id'); $t->string('model_sku')->nullable();
        });
        Schema::create('tiktok_products', function (Blueprint $t) {
            $t->id(); $t->string('product_id'); $t->string('account_key')->default('tiktok-agnishopbjm');
            $t->string('seller_sku')->nullable(); $t->boolean('is_active')->default(true);
        });
        $this->target('900', 'INT-999-OTHER');
        $this->gita('800', 'INT-999-OTHER');
    }

    private function source(string $id, array $skus = [], string $shop = '11'): array
    {
        return ['item_id' => $id, 'shop_id' => $shop, 'skus' => $skus];
    }

    private function target(string $id, string $sku, bool $active = true, string $account = 'tiktok-agnishopbjm'): void
    {
        DB::table('tiktok_products')->insert(['product_id' => $id, 'seller_sku' => $sku, 'is_active' => $active, 'account_key' => $account]);
    }

    private function gita(string $id, string $sku, string $shop = '22', bool $active = true): void
    {
        DB::table('shopee_product')->insert(['item_id' => $id, 'shop_id' => $shop, 'is_active' => $active]);
        DB::table('shopee_product_model')->insert(['item_id' => $id, 'model_sku' => $sku]);
    }

    public function test_it_reports_missing_per_destination_and_uses_exact_item_prefix(): void
    {
        $this->target('901', 'INT-10-RED');
        $this->gita('801', 'INT-100-BLUE');
        $result = app(MarketplaceProductPresenceService::class)->forProducts([$this->source('10'), $this->source('100'), $this->source('20')]);
        $this->assertSame(['tiktok-agnishopbjm' => 'present', 'shopee-gitacollectionbjm' => 'missing'], $result['10']);
        $this->assertSame(['tiktok-agnishopbjm' => 'missing', 'shopee-gitacollectionbjm' => 'present'], $result['100']);
        $this->assertSame(['tiktok-agnishopbjm' => 'missing', 'shopee-gitacollectionbjm' => 'missing'], $result['20']);
        Http::assertNothingSent();
    }

    public function test_unique_real_skus_prove_presence_but_shared_source_skus_do_not(): void
    {
        $this->target('901', 'CUSTOM');
        $this->gita('801', 'CUSTOM');
        $service = app(MarketplaceProductPresenceService::class);
        $this->assertSame('present', $service->forProducts([$this->source('10', ['CUSTOM'])])['10']['tiktok-agnishopbjm']);
        $result = $service->forProducts([$this->source('10', ['CUSTOM']), $this->source('20', ['CUSTOM'])]);
        $this->assertSame('unknown', $result['10']['tiktok-agnishopbjm']);
        $this->assertSame('unknown', $result['20']['shopee-gitacollectionbjm']);
    }

    public function test_removed_and_other_account_rows_do_not_prove_presence(): void
    {
        $this->target('901', 'INT-10-RED', false);
        $this->target('902', 'INT-10-BLUE', true, 'tiktok-other');
        $this->gita('801', 'INT-10-RED', '33');
        $this->gita('802', 'INT-10-BLUE', '22', false);
        $result = app(MarketplaceProductPresenceService::class)->forProducts([$this->source('10')]);
        $this->assertSame(['tiktok-agnishopbjm' => 'missing', 'shopee-gitacollectionbjm' => 'missing'], $result['10']);
    }

    public function test_unavailable_cache_or_source_shop_identity_is_unknown(): void
    {
        DB::table('tiktok_products')->delete();
        $service = app(MarketplaceProductPresenceService::class);
        $this->assertSame('unknown', $service->forProducts([$this->source('10')])['10']['tiktok-agnishopbjm']);
        $result = $service->forProducts([$this->source('10', [], '22')]);
        $this->assertSame(['tiktok-agnishopbjm' => 'unknown', 'shopee-gitacollectionbjm' => 'unknown'], $result['10']);
        DB::table('shopee_tokens')->where('account_key', 'shopee-gitacollectionbjm')->delete();
        $this->assertSame('unknown', $service->forProducts([$this->source('10')])['10']['shopee-gitacollectionbjm']);
    }

    public function test_explicit_links_need_an_existing_target_in_the_correct_catalog(): void
    {
        Schema::dropIfExists('sku_mappings');
        Schema::create('sku_mappings', function (Blueprint $t) {
            $t->id(); $t->string('shopee_item_id'); $t->string('tiktok_product_id');
        });
        DB::table('sku_mappings')->insert([
            ['shopee_item_id' => '10', 'tiktok_product_id' => '900'],
            ['shopee_item_id' => '20', 'tiktok_product_id' => 'gone'],
        ]);
        Schema::dropIfExists('marketplace_listings');
        Schema::create('marketplace_listings', function (Blueprint $t) {
            $t->id(); $t->integer('stock_master_id'); $t->string('account_key');
            $t->string('remote_product_id'); $t->boolean('is_active')->default(true);
        });
        DB::table('marketplace_listings')->insert([
            ['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'remote_product_id' => '10'],
            ['stock_master_id' => 1, 'account_key' => 'shopee-gitacollectionbjm', 'remote_product_id' => '800'],
            ['stock_master_id' => 2, 'account_key' => 'shopee-agnishopbjm', 'remote_product_id' => '20'],
            ['stock_master_id' => 2, 'account_key' => 'shopee-gitacollectionbjm', 'remote_product_id' => 'gone'],
        ]);
        $result = app(MarketplaceProductPresenceService::class)->forProducts([$this->source('10'), $this->source('20')]);
        $this->assertSame(['tiktok-agnishopbjm' => 'present', 'shopee-gitacollectionbjm' => 'present'], $result['10']);
        $this->assertSame(['tiktok-agnishopbjm' => 'missing', 'shopee-gitacollectionbjm' => 'missing'], $result['20']);
        $this->assertSame(4, DB::table('marketplace_listings')->count());
        Http::assertNothingSent();
    }

    public function test_legacy_single_account_tiktok_catalog_and_unavailable_schema_are_handled(): void
    {
        Schema::dropIfExists('tiktok_products');
        Schema::create('tiktok_products', function (Blueprint $t) {
            $t->id(); $t->string('product_id'); $t->string('seller_sku')->nullable();
        });
        DB::table('tiktok_products')->insert(['product_id' => '901', 'seller_sku' => 'INT-10-RED']);
        $service = app(MarketplaceProductPresenceService::class);
        $this->assertSame('present', $service->forProducts([$this->source('10')])['10']['tiktok-agnishopbjm']);
        Schema::table('tiktok_products', fn (Blueprint $t) => $t->dropColumn('seller_sku'));
        $this->assertSame('unknown', $service->forProducts([$this->source('10')])['10']['tiktok-agnishopbjm']);
        Schema::dropIfExists('tiktok_products');
        $this->assertSame('unknown', $service->forProducts([$this->source('10')])['10']['tiktok-agnishopbjm']);
    }

    public function test_gita_identity_must_not_alias_the_source_shop(): void
    {
        DB::table('shopee_tokens')->where('account_key', 'shopee-gitacollectionbjm')->update(['shop_id' => 11]);
        $this->gita('801', 'INT-10-RED', '11');
        $result = app(MarketplaceProductPresenceService::class)->forProducts([$this->source('10')]);
        $this->assertSame('unknown', $result['10']['shopee-gitacollectionbjm']);
    }

    public function test_a_corrupted_source_sku_cannot_claim_another_items_target_product(): void
    {
        $this->target('901', 'INT-20-RED');
        $this->gita('801', 'INT-20-RED');
        $service = app(MarketplaceProductPresenceService::class);
        $result = $service->forProducts([$this->source('10', ['INT-20-RED']), $this->source('20')]);
        $this->assertSame(['tiktok-agnishopbjm' => 'unknown', 'shopee-gitacollectionbjm' => 'unknown'], $result['10']);
        $this->assertSame(['tiktok-agnishopbjm' => 'present', 'shopee-gitacollectionbjm' => 'present'], $result['20']);
        // Independent evidence can still prove product presence despite a bad source SKU.
        $this->target('902', 'CUSTOM-TEN');
        $this->assertSame('present', $service->forProducts([$this->source('10', ['INT-20-RED', 'CUSTOM-TEN'])])['10']['tiktok-agnishopbjm']);
    }
}
