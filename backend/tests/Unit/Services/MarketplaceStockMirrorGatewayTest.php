<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceStockMirrorGateway;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceStockMirrorGatewayTest extends TestCase
{
    use RefreshDatabase;
    private array $rows = [];
    private string $status = 'NORMAL';
    private string $mode = '';
    private bool $targetSkuDrift = false;
    private array $tiktokInventory = [['warehouse_id' => 'wh', 'quantity' => 0]];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Schema::create('stock_master', function (Blueprint $t) {
            $t->id();
            $t->integer('stock');
        });
        DB::table('stock_master')->insert(['stock' => 17]);
        Schema::create('shopee_product', function (Blueprint $t) {
            $t->id();
            $t->string('item_id');
            $t->string('shop_id');
        });
        Schema::create('shopee_product_model', function (Blueprint $t) {
            $t->id();
            $t->string('item_id');
            $t->string('model_id');
            $t->integer('stock');
            $t->string('model_sku')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        foreach (['shopee-agnishopbjm' => 11, 'shopee-gitacollectionbjm' => 22] as $key => $shop) {
            config(['marketplace_accounts.accounts.'.$key => ['enabled' => true, 'channel' => 'shopee', 'credentials' => ['partner_id' => 123, 'partner_key' => 'test-secret', 'host' => 'https://shop.test', 'redirect_url' => 'https://local.test']]]);
            DB::table('shopee_tokens')->insert(['account_key' => $key, 'shop_id' => $shop, 'access_token' => 'token-'.$shop, 'is_active' => true, 'access_token_expire_at' => now()->addHour(), 'created_at' => now()]);
        }
        $this->rows = [['model_id' => 101, 'model_name' => 'Green', 'model_sku' => 'GREEN', 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 0]]], ['model_id' => 102, 'model_sku' => 'BLUE']];
        Http::fake(function ($r) {
            if (str_contains($r->url(), 'tt.test')) {
                if (str_contains($r->url(), 'inventory/update')) {
                    return Http::response(['code' => 0, 'data' => []]);
                }

                return Http::response(['code' => 0, 'data' => ['product' => ['id' => '30', 'title' => 'Target', 'status' => 'ACTIVATE', 'shop_id' => '33', 'skus' => [['id' => '301', 'seller_sku' => 'GREEN', 'inventory' => $this->tiktokInventory]]]]]);
            }
            if ('error' === $this->mode) {
                return Http::response(['error' => 'token-secret', 'message' => 'signed-secret'], 500);
            }
            if ('cursor' === $this->mode) {
                return Http::response(['error' => '', 'response' => ['item' => [], 'has_next_page' => true]]);
            }
            if (str_contains($r->url(), 'update_stock')) {
                return Http::response(['error' => '', 'response' => ['failure_list' => [], 'success_list' => [['model_id' => 101]]]]);
            }
            if (str_contains($r->url(), 'get_item_list')) {
                return Http::response(['error' => '', 'response' => ['item' => [['item_id' => 0 == $r['offset'] ? 10 : 20]], 'has_next_page' => 0 == $r['offset'], 'next_offset' => 50]]);
            }
            if (str_contains($r->url(), 'get_item_base_info')) {
                return Http::response(['error' => '', 'response' => ['item_list' => [['item_id' => (int) $r['item_id_list'], 'item_name' => 'Product', 'item_status' => $this->status, 'shop_id' => $r['shop_id']]]]]);
            }

            $rows = $this->rows;
            if ($this->targetSkuDrift && $r['shop_id'] == 22) {
                $rows[0]['model_sku'] = 'CHANGED';
            }

            return Http::response(['error' => '', 'response' => ['model' => $rows]]);
        });
    }

    public function testFreshZeroIsDistinctFromMissingAndAccountsAreIsolated(): void
    {
        $g = app(MarketplaceStockMirrorGateway::class);
        $p = $g->product('shopee-agnishopbjm', '10');
        $this->assertSame(0, $p['variants'][0]['stock']);
        $this->assertNull($p['variants'][1]['stock']);
        $this->assertSame('22', $g->product('shopee-gitacollectionbjm', '20')['shop_id']);
        Http::assertSent(fn ($r) => 22 == $r['shop_id'] && 'token-22' === $r['access_token']);
    }

    public function testCatalogAdvancesAndRejectsMissingCursor(): void
    {
        $g = app(MarketplaceStockMirrorGateway::class);
        $p = $g->catalogPage(null);
        $this->assertSame(['10'], $p['products']);
        $this->assertFalse($p['complete']);
        $this->assertSame(['20'], $g->catalogPage($p['next_cursor'])['products']);
        $this->mode = 'cursor';
        $this->expectException(\RuntimeException::class);
        $g->catalogPage(null);
    }

    public function testSelectedSourceVariantNeverExpands(): void
    {
        $this->assertSame([['source_product_id' => '10', 'source_variant_id' => '101']], app(MarketplaceStockMirrorGateway::class)->sourceSelection('shopee-agnishopbjm', '10', '101'));
    }

    public function testDeletedProductCannotBeSelected(): void
    {
        $this->status = 'DELETED';
        $this->expectException(\RuntimeException::class);
        app(MarketplaceStockMirrorGateway::class)->sourceSelection('shopee-agnishopbjm', '10', null);
    }

    public function testDuplicateExactSkuTargetsAreSkipped(): void
    {
        $this->rows[] = ['model_id' => 103, 'model_sku' => 'GREEN', 'normal_stock' => 2];
        $this->assertSame('skipped', app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm')['status']);
    }

    public function testWriteIsStockOnlyAndPreservesStockMaster(): void
    {
        $before = DB::table('stock_master')->get()->toJson();
        $r = app(MarketplaceStockMirrorGateway::class)->write('shopee-gitacollectionbjm', '20', '101', 0, 'test-key');
        $this->assertSame('success', $r['status']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'update_stock') && $r->data() === ['item_id' => 20, 'stock_list' => [['model_id' => 101, 'seller_stock' => [['stock' => 0]]]]]);
        $this->assertSame($before, DB::table('stock_master')->get()->toJson());
    }

    public function testSourceCannotBeWrittenAndFailuresAreSanitized(): void
    {
        $g = app(MarketplaceStockMirrorGateway::class);
        $this->assertSame('error', $g->write('shopee-agnishopbjm', '10', '101', 1, 'key')['status']);
        $this->mode = 'error';
        $r = $g->write('shopee-gitacollectionbjm', '20', '101', 1, 'key');
        $this->assertSame('error', $r['status']);
        $this->assertStringNotContainsString('secret', json_encode($r));
    }

    public function testSameShopFailsClosed(): void
    {
        DB::table('shopee_tokens')->where('account_key', 'shopee-gitacollectionbjm')->update(['shop_id' => 11]);
        $this->expectException(\RuntimeException::class);
        app(MarketplaceStockMirrorGateway::class)->product('shopee-gitacollectionbjm', '20');
    }

    public function testReadyMappingAndTargetScopedSelectionUseRemoteIdentities(): void
    {
        $this->links();
        $g = app(MarketplaceStockMirrorGateway::class);
        $this->assertSame(['status' => 'ready', 'product_id' => '20', 'variant_id' => '101', 'reason' => null], $g->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm'));
        $this->assertSame([['source_product_id' => '10', 'source_variant_id' => '101', 'view_product_id' => '20', 'view_variant_id' => '101']], $g->sourceSelection('shopee-gitacollectionbjm', '20', '101'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'get_item_list'));
    }

    private function links(): void
    {
        foreach ([['shopee-agnishopbjm', '10', '101'], ['shopee-gitacollectionbjm', '20', '101']] as [$key,$product,$variant]) {
            DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => $key, 'channel' => 'shopee', 'remote_product_id' => $product, 'remote_variant_id' => $variant, 'remote_identity_hash' => hash('sha256', $key)]);
        }
    }

    public function testInactiveMappingAndDeletedRemoteTargetAreSkipped(): void
    {
        $this->links();
        DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->update(['is_active' => false]);
        $g = app(MarketplaceStockMirrorGateway::class);
        $this->assertSame('skipped', $g->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm')['status']);
        DB::table('marketplace_listings')->update(['is_active' => true]);
        $this->status = 'DELETED';
        $this->assertSame('skipped', $g->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm')['status']);
    }

    public function testVerifiedCacheChangesOnlyTheOwnedTargetRow(): void
    {
        DB::table('shopee_product')->insert([['item_id' => '10', 'shop_id' => '11'], ['item_id' => '20', 'shop_id' => '22']]);
        DB::table('shopee_product_model')->insert([['item_id' => '10', 'model_id' => '101', 'stock' => 17], ['item_id' => '20', 'model_id' => '101', 'stock' => 4]]);
        app(MarketplaceStockMirrorGateway::class)->cacheVerified('shopee-gitacollectionbjm', '20', '101', 0);
        $this->assertSame(0, DB::table('shopee_product_model')->where('item_id', '20')->value('stock'));
        $this->assertSame(17, DB::table('shopee_product_model')->where('item_id', '10')->value('stock'));
        $this->assertSame(17, DB::table('stock_master')->value('stock'));
    }

    public function testExpiredCredentialsAreRejectedBeforeHttp(): void
    {
        DB::table('shopee_tokens')->where('account_key', 'shopee-agnishopbjm')->update(['access_token_expire_at' => now()->subMinute()]);
        $this->expectException(\RuntimeException::class);
        app(MarketplaceStockMirrorGateway::class)->product('shopee-agnishopbjm', '10');
    }

    public function testInactiveSourceMappingCannotAuthorizeATarget(): void
    {
        $this->links();
        DB::table('marketplace_listings')->where('account_key', 'shopee-agnishopbjm')->update(['is_active' => false]);
        $this->assertSame('skipped', app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm')['status']);
    }

    public function testConflictingReverseMappingBlocksTargetDelivery(): void
    {
        $this->links();
        $this->tiktok();
        DB::table('marketplace_listings')->insert(['stock_master_id' => 2, 'account_key' => 'shopee-agnishopbjm', 'channel' => 'shopee', 'remote_product_id' => '10', 'remote_variant_id' => '102', 'remote_identity_hash' => hash('sha256', 'other')]);
        DB::table('sku_mappings')->insert(['stock_master_id' => 2, 'shopee_item_id' => '10', 'shopee_model_id' => '102', 'tiktok_product_id' => '30', 'tiktok_sku_id' => '301']);
        DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => 'tiktok-agnishopbjm', 'channel' => 'tiktok', 'remote_product_id' => '30', 'remote_variant_id' => '301', 'remote_identity_hash' => hash('sha256', 'tt')]);
        $this->assertSame('skipped', app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'tiktok-agnishopbjm')['status']);
    }

    private function tiktok(): void
    {
        Schema::create('tiktok_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('account_key');
            $t->string('access_token');
            $t->string('shop_id');
            $t->timestamp('expire_at');
            $t->boolean('is_active');
        });
        Schema::create('tiktok_shops', function (Blueprint $t) {
            $t->id();
            $t->string('shop_id');
            $t->string('cipher');
            $t->timestamp('updated_at')->nullable();
        });
        DB::table('tiktok_tokens')->insert(['account_key' => 'tiktok-agnishopbjm', 'access_token' => 'tt-token', 'shop_id' => '33', 'expire_at' => now()->addDay(), 'is_active' => true]);
        DB::table('tiktok_shops')->insert(['shop_id' => '33', 'cipher' => 'cipher']);
        config(['marketplace_accounts.accounts.tiktok-agnishopbjm' => ['enabled' => true, 'channel' => 'tiktok', 'credentials' => ['app_key' => 'key', 'app_secret' => 'secret', 'auth_host' => 'https://tt.test', 'api_host' => 'https://tt.test', 'redirect_url' => 'https://local.test', 'warehouse_id' => 'wh']]]);
    }

    public function testTiktokReadsOnlyTheConfiguredWarehouseAndWritesInventoryOnly(): void
    {
        $this->tiktok();
        $g = app(MarketplaceStockMirrorGateway::class);
        $this->assertSame(0, $g->product('tiktok-agnishopbjm', '30')['variants'][0]['stock']);
        $this->tiktokInventory = [['warehouse_id' => 'other', 'quantity' => 9]];
        $this->assertNull($g->product('tiktok-agnishopbjm', '30')['variants'][0]['stock']);
        $this->assertSame('success', $g->write('tiktok-agnishopbjm', '30', '301', 5, 'tt-key')['status']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'inventory/update') && $r->data() === ['skus' => [['id' => '301', 'inventory' => [['warehouse_id' => 'wh', 'quantity' => 5]]]]]);
    }

    public function testStaleSourceIdentityCannotAuthorizeTarget(): void
    {
        $this->links();
        $this->assertSame('skipped', app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'STALE'], 'shopee-gitacollectionbjm')['status']);
    }

    public function testWriterRejectsLatestShopThatDiffersFromTokenShop(): void
    {
        $this->tiktok();
        DB::table('tiktok_shops')->insert(['shop_id' => '44', 'cipher' => 'foreign', 'updated_at' => now()->addMinute()]);
        $result = app(MarketplaceStockMirrorGateway::class)->write('tiktok-agnishopbjm', '30', '301', 3, 'key');
        $this->assertSame('error', $result['status']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'inventory/update'));
    }

    private function fallbackCandidates(bool $active = true): void
    {
        foreach ([['shopee-agnishopbjm', '10', '101', 1], ['shopee-gitacollectionbjm', '20', '101', 2]] as [$key,$product,$variant,$master]) {
            DB::table('marketplace_listings')->insert(['stock_master_id' => $master, 'account_key' => $key, 'channel' => 'shopee', 'remote_product_id' => $product, 'remote_variant_id' => $variant, 'remote_identity_hash' => hash('sha256', $key), 'seller_sku' => 'GREEN', 'is_active' => $key === 'shopee-agnishopbjm' || $active]);
        }
    }

    public function testFallbackUsesLocalIdentityAndFreshStockWithoutCatalogEnumeration(): void
    {
        $this->fallbackCandidates();
        $result = app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm');
        $this->assertSame('ready', $result['status']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'get_item_list'));
    }

    public function testInactiveIndependentTargetListingCannotBeBypassedByFallback(): void
    {
        $this->fallbackCandidates(false);
        DB::table('marketplace_listings')->where('account_key', 'shopee-agnishopbjm')->delete();
        DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->update(['seller_sku' => 'OLD']);
        DB::table('shopee_product')->insert(['item_id' => '20', 'shop_id' => '22']);
        DB::table('shopee_product_model')->insert(['item_id' => '20', 'model_id' => '101', 'stock' => 999, 'model_sku' => 'GREEN']);
        DB::table('shopee_product')->insert(['item_id' => '10', 'shop_id' => '11']);
        DB::table('shopee_product_model')->insert(['item_id' => '10', 'model_id' => '101', 'stock' => 999, 'model_sku' => 'GREEN']);
        $this->assertSame('skipped', app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm')['status']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'get_item_list'));
    }

    public function testFallbackRejectsFreshSkuDrift(): void
    {
        $this->fallbackCandidates();
        $this->targetSkuDrift = true;
        $this->assertSame('skipped', app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm')['status']);
    }

    public function testMissingLocalCandidateIsSkippedWithoutCatalogScan(): void
    {
        $this->assertSame('skipped', app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm')['status']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'get_item_list'));
    }

    public function testFallbackRejectsInactiveSourceCandidate(): void
    {
        $this->fallbackCandidates();
        DB::table('marketplace_listings')->where('account_key', 'shopee-agnishopbjm')->update(['is_active' => false]);
        $this->assertSame('skipped', app(MarketplaceStockMirrorGateway::class)->target('10', ['id' => '101', 'seller_sku' => 'GREEN'], 'shopee-gitacollectionbjm')['status']);
    }
}
