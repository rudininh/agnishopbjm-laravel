<?php

namespace Tests\Feature;

use App\Services\OrphanVariantGateway;
use App\Services\OrphanVariantClassifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrphanVariantGatewayTest extends TestCase
{
    use RefreshDatabase;
    private array $authorizedShops = [['id' => '33', 'cipher' => 'test-cipher']];
    private string $sourceStatus = 'NORMAL';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['shopee-agnishopbjm' => 11, 'shopee-gitacollectionbjm' => 22] as $key => $shop) {
            config(['marketplace_accounts.accounts.'.$key => [
                'enabled' => true, 'channel' => 'shopee', 'credentials' => [
                    'partner_id' => 123, 'partner_key' => 'test-secret', 'host' => 'https://shop.test', 'redirect_url' => 'https://local.test',
                ],
            ]]);
            DB::table('shopee_tokens')->insert(['account_key' => $key, 'shop_id' => $shop, 'access_token' => 'token-'.$shop,
                'is_active' => true, 'access_token_expire_at' => now()->addHour(), 'created_at' => now()]);
        }
    }

    private function remote(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'delete_model')) return Http::response(['error' => '', 'response' => []]);
            if (str_contains($request->url(), 'get_item_base_info')) {
                return Http::response(['error' => '', 'response' => ['item_list' => [[
                    'item_id' => (int) $request['item_id_list'], 'item_name' => 'Product', 'item_status' => $request['shop_id'] == 11 ? $this->sourceStatus : 'NORMAL',
                ]]]]);
            }
            return Http::response(['error' => '', 'response' => ['model' => $request['shop_id'] == 11
                ? [['model_id' => 101, 'model_name' => 'Green', 'model_sku' => 'INT-10-GREEN']]
                : [['model_id' => 201, 'model_name' => 'Green', 'model_sku' => 'INT-10-GREEN'],
                   ['model_id' => 202, 'model_name' => 'Blue', 'model_sku' => 'INT-10-BLUE']]]]);
        });
    }

    public function test_snapshot_reads_source_and_target_under_their_own_accounts(): void
    {
        $this->remote();
        $s = app(OrphanVariantGateway::class)->snapshot('shopee-gitacollectionbjm', '20');
        $this->assertTrue($s['source_complete']);
        $this->assertSame('10', $s['source_product_id']);
        $this->assertCount(2, $s['target_variants']);
        Http::assertSent(fn ($r) => $r['shop_id'] == 11 && $r['access_token'] === 'token-11');
        Http::assertSent(fn ($r) => $r['shop_id'] == 22 && $r['access_token'] === 'token-22');
    }

    public function test_same_shop_or_expired_target_credentials_fail_before_http(): void
    {
        DB::table('shopee_tokens')->where('account_key', 'shopee-gitacollectionbjm')->update(['shop_id' => 11]);
        try {
            app(OrphanVariantGateway::class)->snapshot('shopee-gitacollectionbjm', '20');
            $this->fail('Must reject same source and target shop');
        } catch (\RuntimeException $e) {
            Http::assertNothingSent();
        }
    }

    public function test_deleted_source_product_is_not_evidence_for_variant_cleanup(): void
    {
        $this->sourceStatus = 'DELETED';
        $this->remote();
        $s = app(OrphanVariantGateway::class)->snapshot('shopee-gitacollectionbjm', '20');
        $this->assertFalse($s['source_complete']);
        $this->assertNotContains('eligible', array_column((new OrphanVariantClassifier)->classify($s)['items'], 'status'));
    }

    public function test_gita_delete_uses_only_target_identity_and_rejects_last_variant(): void
    {
        $this->remote();
        $gateway = app(OrphanVariantGateway::class);
        $s = $gateway->snapshot('shopee-gitacollectionbjm', '20');
        $this->assertTrue($gateway->delete('shopee-gitacollectionbjm', $s, ['202'])['accepted']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['item_id'] === 20 && $r['model_id'] === 202
            && str_contains($r->url(), 'shop_id=22'));
        $this->expectException(\RuntimeException::class);
        $gateway->delete('shopee-gitacollectionbjm', $s, ['201', '202']);
    }

    public function test_missing_model_field_and_incomplete_pagination_are_rejected(): void
    {
        Http::fake(['*' => Http::response(['error' => '', 'response' => ['item' => [], 'has_next_page' => true]])]);
        $this->expectException(\RuntimeException::class);
        app(OrphanVariantGateway::class)->catalogPage('shopee-gitacollectionbjm', null);
    }

    private function tiktokFixture(): void
    {
        Schema::create('tiktok_tokens', function (Blueprint $t): void {
            $t->id(); $t->string('account_key'); $t->string('access_token'); $t->string('shop_id')->nullable();
            $t->timestamp('expire_at'); $t->boolean('is_active');
        });
        Schema::create('tiktok_shops', function (Blueprint $t): void {
            $t->id(); $t->string('shop_id'); $t->string('cipher');
        });
        DB::table('tiktok_tokens')->insert(['account_key' => 'tiktok-agnishopbjm', 'access_token' => 'tt-test-token', 'shop_id' => '33', 'expire_at' => now()->addDay(), 'is_active' => true]);
        DB::table('tiktok_shops')->insert(['shop_id' => '33', 'cipher' => 'test-cipher']);
        config(['marketplace_accounts.accounts.tiktok-agnishopbjm' => ['enabled' => true, 'channel' => 'tiktok', 'credentials' => [
            'app_key' => 'test-app', 'app_secret' => 'test-secret', 'auth_host' => 'https://tt.test', 'api_host' => 'https://tt.test', 'redirect_url' => 'https://local.test', 'warehouse_id' => 'wh',
        ]]]);
        Http::fake(function ($r) {
            if (str_contains($r->url(), 'tt.test')) {
                if (str_contains($r->url(), '/authorization/')) return Http::response(['code' => 0, 'data' => ['shops' => $this->authorizedShops]]);
                if (str_contains($r->url(), 'partial_edit')) return Http::response(['code' => 0, 'data' => []]);
                return Http::response(['code' => 0, 'data' => ['product' => ['id' => '20', 'title' => 'Product', 'skus' => [
                    ['id' => '201', 'seller_sku' => 'INT-10-GREEN', 'price' => ['currency' => 'IDR', 'sale_price' => '20000'],
                     'inventory' => [['warehouse_id' => 'wh', 'quantity' => 3]], 'sales_attributes' => [['id' => 'c', 'value_name' => 'Green']]],
                    ['id' => '202', 'seller_sku' => 'INT-10-BLUE', 'price' => ['currency' => 'IDR', 'sale_price' => '20000'],
                     'inventory' => [['warehouse_id' => 'wh', 'quantity' => 2]], 'sales_attributes' => [['id' => 'c', 'value_name' => 'Blue']]],
                ]]]]);
            }
            if (str_contains($r->url(), 'get_item_base_info')) return Http::response(['error' => '', 'response' => ['item_list' => [['item_id' => 10, 'item_name' => 'Source', 'item_status' => 'NORMAL']]]]);
            return Http::response(['error' => '', 'response' => ['model' => [['model_id' => 101, 'model_name' => 'Green', 'model_sku' => 'INT-10-GREEN']]]]);
        });
    }

    public function test_tiktok_resolves_legacy_shop_table_only_through_account_token_shop_id_and_preserves_survivor(): void
    {
        $this->tiktokFixture();
        $g = app(OrphanVariantGateway::class);
        $s = $g->snapshot('tiktok-agnishopbjm', '20');
        $this->assertTrue($s['source_complete']);
        $this->assertSame('33', $s['target_shop_id']);
        $g->delete('tiktok-agnishopbjm', $s, ['202']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'partial_edit') && $r['skus'] === [[
            'id' => '201', 'seller_sku' => 'INT-10-GREEN', 'price' => ['currency' => 'IDR', 'sale_price' => '20000', 'tax_exclusive_price' => '20000', 'amount' => '20000'],
            'inventory' => [['warehouse_id' => 'wh', 'quantity' => 3]], 'sales_attributes' => [['id' => 'c', 'value_name' => 'Green']],
        ]]);
    }

    public function test_conflicting_source_model_links_block_deletion_even_with_one_source_product(): void
    {
        $this->tiktokFixture();
        foreach ([['tiktok-agnishopbjm', 'tiktok', '20', '202'], ['shopee-agnishopbjm', 'shopee', '10', '101']] as [$key, $channel, $product, $variant]) {
            DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => $key, 'channel' => $channel,
                'remote_product_id' => $product, 'remote_variant_id' => $variant, 'remote_identity_hash' => hash('sha256', $key)]);
        }
        DB::table('sku_mappings')->insert(['stock_master_id' => 1, 'shopee_item_id' => '10', 'shopee_model_id' => '102', 'tiktok_product_id' => '20', 'tiktok_sku_id' => '202']);
        $s = app(OrphanVariantGateway::class)->snapshot('tiktok-agnishopbjm', '20');
        $this->assertTrue($s['mapping_conflicts']);
        $this->assertNotContains('eligible', array_column((new OrphanVariantClassifier)->classify($s)['items'], 'status'));
    }

    public function test_tiktok_missing_token_shop_id_uses_fresh_authorized_shop_identity(): void
    {
        $this->tiktokFixture();
        DB::table('tiktok_tokens')->update(['shop_id' => null]);
        $s = app(OrphanVariantGateway::class)->snapshot('tiktok-agnishopbjm', '20');
        $this->assertSame('33', $s['target_shop_id']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/authorization/202309/shops'));
    }

    public function test_ambiguous_authorized_shops_never_trigger_product_mutation(): void
    {
        $this->tiktokFixture();
        DB::table('tiktok_tokens')->update(['shop_id' => null]);
        $this->authorizedShops[] = ['id' => '44', 'cipher' => 'other-cipher'];
        try { app(OrphanVariantGateway::class)->snapshot('tiktok-agnishopbjm', '20'); $this->fail(); }
        catch (\RuntimeException $e) { Http::assertNotSent(fn ($r) => str_contains($r->url(), '/products/')); }
    }

    public function test_verified_gita_cleanup_preserves_source_cache_and_stock_master(): void
    {
        $this->remote();
        Schema::create('stock_master', function (Blueprint $t): void { $t->id(); $t->integer('stock_qty'); $t->string('shopee_product_id'); $t->string('shopee_sku'); });
        Schema::create('shopee_product', function (Blueprint $t): void { $t->string('item_id'); $t->integer('shop_id'); });
        Schema::create('shopee_product_model', function (Blueprint $t): void { $t->string('item_id'); $t->string('model_id'); });
        DB::table('stock_master')->insert(['id' => 1, 'stock_qty' => 7, 'shopee_product_id' => '10', 'shopee_sku' => '101']);
        DB::table('shopee_product')->insert([['item_id' => '10', 'shop_id' => 11], ['item_id' => '20', 'shop_id' => 22]]);
        DB::table('shopee_product_model')->insert([['item_id' => '10', 'model_id' => '101'], ['item_id' => '20', 'model_id' => '202']]);
        foreach ([['shopee-gitacollectionbjm', '20', '202'], ['shopee-agnishopbjm', '10', '101']] as [$key, $product, $variant]) {
            DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => $key, 'channel' => 'shopee',
                'remote_product_id' => $product, 'remote_variant_id' => $variant, 'remote_identity_hash' => hash('sha256', $key)]);
        }
        $g = app(OrphanVariantGateway::class);
        $before = $g->snapshot('shopee-gitacollectionbjm', '20');
        $g->reconcileVerified('shopee-gitacollectionbjm', $before, $before, ['202']);
        $this->assertDatabaseHas('stock_master', ['id' => 1, 'stock_qty' => 7, 'shopee_product_id' => '10', 'shopee_sku' => '101']);
        $this->assertDatabaseHas('shopee_product_model', ['item_id' => '10', 'model_id' => '101']);
        $this->assertDatabaseMissing('shopee_product_model', ['item_id' => '20', 'model_id' => '202']);
        $this->assertDatabaseHas('marketplace_listings', ['account_key' => 'shopee-agnishopbjm', 'is_active' => true]);
        $this->assertDatabaseHas('marketplace_listings', ['account_key' => 'shopee-gitacollectionbjm', 'is_active' => false]);
    }
}
