<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceSourceSkuRepairService;
use App\Http\Controllers\OmnichannelController;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceSourceSkuRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests();
        foreach (['shopee-agnishopbjm' => 11, 'shopee-gitacollectionbjm' => 22] as $account => $shop) {
            config(['marketplace_accounts.accounts.'.$account => ['enabled' => true, 'channel' => 'shopee', 'credentials' => ['partner_id' => $shop + 100, 'partner_key' => 'test-key', 'host' => 'https://sku.test', 'redirect_url' => 'https://local.test']]]);
            DB::table('shopee_tokens')->insert(['account_key' => $account, 'shop_id' => $shop, 'access_token' => 'test-'.$shop, 'is_active' => true, 'access_token_expire_at' => now()->addHour()]);
        }
        Schema::create('shopee_product', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('shop_id'); $t->string('name')->default('Same title'); $t->boolean('is_active')->default(true); });
        Schema::create('shopee_product_model', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('model_id'); $t->string('name'); $t->string('model_sku')->nullable(); });
        $this->source('42', '11', [['1', 'Americano', 'CUSTOM-AGNI-A'], ['2', 'Biru', 'INT-42-BIRU']]);
        $this->source('900', '22', [['91', 'Americano', 'INT-900-AMERICANO']]);
    }

    private function source(string $id, string $shop, array $variants): void
    {
        DB::table('shopee_product')->insert(['item_id' => $id, 'shop_id' => $shop]);
        foreach ($variants as [$mid, $name, $sku]) { DB::table('shopee_product_model')->insert(['item_id' => $id, 'model_id' => $mid, 'name' => $name, 'model_sku' => $sku]); }
    }

    private function variants(string $sku = 'INT-42-WRONG'): array
    {
        return [['id' => '91', 'name' => 'Americano', 'seller_sku' => $sku], ['id' => '92', 'name' => 'Biru', 'seller_sku' => 'INT-42-BIRU']];
    }

    private function link(string $source = '42', string $sourceVariant = '1'): void
    {
        $owner = random_int(1, 1000000);
        foreach ([['shopee-agnishopbjm', $source, $sourceVariant], ['shopee-gitacollectionbjm', '900', '91']] as [$account, $pid, $vid]) {
            DB::table('marketplace_listings')->insert(['stock_master_id' => $owner, 'account_key' => $account, 'channel' => 'shopee', 'remote_product_id' => $pid, 'remote_variant_id' => $vid, 'remote_identity_hash' => hash('sha256', $account.$pid.$vid), 'is_active' => true]);
        }
    }

    public function test_both_destination_plans_copy_source_skus_and_keep_target_ids(): void
    {
        foreach (['shopee-gitacollectionbjm', 'tiktok-agnishopbjm'] as $account) {
            $rows = app(MarketplaceSourceSkuRepairService::class)->plan($account, '900', $this->variants());
            $this->assertSame(['CUSTOM-AGNI-A', 'INT-42-BIRU'], array_column($rows, 'target'));
            $this->assertSame(['91', '92'], array_column($rows, 'id'));
            $this->assertSame(['', ''], array_column($rows, 'blocked'));
        }
        Http::assertNothingSent();
    }

    public function test_no_source_evidence_blocks_even_when_title_matches_or_target_prefix_exists(): void
    {
        $rows = app(MarketplaceSourceSkuRepairService::class)->plan('shopee-gitacollectionbjm', '900', [['id' => '91', 'name' => 'Americano', 'seller_sku' => 'INT-900-AMERICANO']]);
        $this->assertSame('', $rows[0]['target']); $this->assertNotEmpty($rows[0]['blocked']);
        Http::assertNothingSent();
    }

    public function test_listing_link_recovers_bad_destination_id_based_sku(): void
    {
        $this->link();
        $rows = app(MarketplaceSourceSkuRepairService::class)->plan('shopee-gitacollectionbjm', '900', [['id' => '91', 'name' => 'Coffee', 'seller_sku' => 'INT-900-COFFEE']]);
        $this->assertSame('CUSTOM-AGNI-A', $rows[0]['target']);
        $this->assertSame('', $rows[0]['blocked']);
    }

    public function test_conflicting_source_products_and_non_agni_source_links_block(): void
    {
        $this->source('43', '11', [['3', 'Biru', 'INT-43-BIRU']]);
        $rows = app(MarketplaceSourceSkuRepairService::class)->plan('tiktok-agnishopbjm', '900', [
            ['id' => '91', 'name' => 'Americano', 'seller_sku' => 'INT-42-WRONG'], ['id' => '92', 'name' => 'Biru', 'seller_sku' => 'INT-43-BIRU'],
        ]);
        $this->assertSame(['', ''], array_column($rows, 'target'));
        $this->link('900', '91');
        $rows = app(MarketplaceSourceSkuRepairService::class)->plan('shopee-gitacollectionbjm', '900', [['id' => '91', 'name' => 'Americano', 'seller_sku' => 'OLD']]);
        $this->assertNotEmpty($rows[0]['blocked']);
    }

    public function test_shared_source_sku_and_ambiguous_agni_shop_identity_block(): void
    {
        $this->source('43', '11', [['3', 'Biru', 'CUSTOM-AGNI-A']]);
        $row = app(MarketplaceSourceSkuRepairService::class)->plan('tiktok-agnishopbjm', '900', [['id' => '91', 'name' => 'Americano', 'seller_sku' => 'CUSTOM-AGNI-A']])[0];
        $this->assertNotEmpty($row['blocked']);
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-agnishopbjm', 'shop_id' => 33, 'is_active' => true]);
        $row = app(MarketplaceSourceSkuRepairService::class)->plan('tiktok-agnishopbjm', '900', $this->variants())[0];
        $this->assertNotEmpty($row['blocked']);
    }

    public function test_submission_reads_fresh_agni_sku_and_blocks_changed_source_without_writes(): void
    {
        Http::fake(function ($r) {
            $this->assertSame('GET', $r->method());
            $this->assertSame('11', (string) $r['shop_id']);
            $data = str_contains($r->url(), 'get_item_base_info') ? ['item_list' => [['item_id' => 42, 'shop_id' => 11, 'item_name' => 'Source', 'item_status' => 'NORMAL', 'has_model' => true]]]
                : ['model' => [['model_id' => 1, 'model_name' => 'Americano', 'model_sku' => 'CHANGED'], ['model_id' => 2, 'model_name' => 'Biru', 'model_sku' => 'INT-42-BIRU']]];
            return Http::response(['error' => '', 'response' => $data]);
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agni berubah');
        app(MarketplaceSourceSkuRepairService::class)->target('shopee-gitacollectionbjm', '900', $this->variants(), '91', ['seller_sku' => 'CUSTOM-AGNI-A', 'expected_sku' => 'INT-42-WRONG', 'expected_name' => 'Americano']);
    }

    public function test_gita_repair_endpoint_keeps_destination_ids_and_writes_exact_source_sku(): void
    {
        $this->link();
        Schema::table('shopee_product_model', fn (Blueprint $t) => $t->timestamp('updated_at')->nullable());
        Http::fake(function ($r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
            $pid = (string) ($r['item_id_list'] ?? $r['item_id'] ?? '');
            if ($r->method() === 'POST') {
                $this->assertStringContainsString('/product/update_model', $r->url());
                $this->assertSame('22', (string) $query['shop_id']);
                $body = json_decode($r->body(), true);
                $this->assertSame(['item_id' => 900, 'model' => [['model_id' => 91, 'model_sku' => 'CUSTOM-AGNI-A']]], $body);
                return Http::response(['error' => '', 'response' => []]);
            }
            $data = str_contains($r->url(), 'get_item_base_info') ? ['item_list' => [['item_id' => (int) $pid, 'shop_id' => $pid === '42' ? 11 : 22, 'item_name' => 'Product', 'item_status' => 'NORMAL', 'has_model' => true]]]
                : ['model' => $pid === '42' ? [['model_id' => 1, 'model_name' => 'Americano', 'model_sku' => 'CUSTOM-AGNI-A'], ['model_id' => 2, 'model_name' => 'Biru', 'model_sku' => 'INT-42-BIRU']] : [['model_id' => 91, 'model_name' => 'Americano', 'model_sku' => 'INT-900-AMERICANO']]];
            return Http::response(['error' => '', 'response' => $data]);
        });
        $controller = \Mockery::mock(OmnichannelController::class)->makePartial();
        $controller->shouldReceive('autoRefreshMarketplaceTokens')->andReturn(['status' => 'ok']);
        $result = $controller->updateMarketplaceVariantSku(Request::create('/api/sku-mapping/update-marketplace-variant-sku', 'POST', [
            'channel' => 'shopee', 'account_key' => 'shopee-gitacollectionbjm', 'item_id' => '900', 'model_id' => '91', 'seller_sku' => 'CUSTOM-AGNI-A', 'repair_template' => true, 'expected_name' => 'Americano', 'expected_sku' => 'INT-900-AMERICANO',
        ]));
        $this->assertSame('ok', $result->getData(true)['status'], $result->getData(true)['response']['message'] ?? '');
        $this->assertSame('CUSTOM-AGNI-A', DB::table('shopee_product_model')->where('item_id', '900')->value('model_sku'));
        $this->assertSame('CUSTOM-AGNI-A', DB::table('shopee_product_model')->where('item_id', '42')->where('model_id', '1')->value('model_sku'));
        Http::assertSentCount(5);
    }

    public function test_tiktok_repair_endpoint_copies_agni_and_preserves_other_skus(): void
    {
        config(['tiktok.app_key' => 'test-app', 'tiktok.app_secret' => 'test-secret', 'tiktok.api_host' => 'https://tiktok-sku.test']);
        config(['marketplace_accounts.accounts.tiktok-agnishopbjm' => ['enabled' => true, 'channel' => 'tiktok', 'credentials' => ['app_key' => 'test-app', 'app_secret' => 'test-secret', 'api_host' => 'https://tiktok-sku.test', 'redirect_url' => 'https://local.test']]]);
        if (!Schema::hasTable('tiktok_tokens')) { Schema::create('tiktok_tokens', function (Blueprint $t) { $t->id(); $t->string('account_key'); $t->string('access_token'); $t->string('shop_id'); $t->boolean('is_active'); $t->timestamp('access_token_expire_at')->nullable(); }); }
        DB::table('tiktok_tokens')->insert(['account_key' => 'tiktok-agnishopbjm', 'access_token' => 'test-tiktok', 'shop_id' => '33', 'is_active' => true, 'access_token_expire_at' => now()->addHour()]);
        Schema::create('tiktok_shops', function (Blueprint $t) { $t->id(); $t->string('shop_id'); $t->string('cipher'); $t->timestamps(); });
        DB::table('tiktok_shops')->insert(['shop_id' => '33', 'cipher' => 'test-cipher']);
        Schema::create('tiktok_products', function (Blueprint $t) { $t->id(); $t->string('product_id'); $t->string('sku_id'); $t->string('seller_sku'); $t->timestamp('updated_at')->nullable(); });
        Schema::create('stock_master', function (Blueprint $t) { $t->id(); $t->string('tiktok_product_id')->nullable(); $t->string('tiktok_sku')->nullable(); $t->string('tiktok_seller_sku')->nullable(); $t->timestamp('updated_at')->nullable(); });
        DB::table('tiktok_products')->insert(['product_id' => '900', 'sku_id' => '91', 'seller_sku' => 'INT-42-WRONG']);
        $detail = ['id' => '900', 'skus' => [
            ['id' => '91', 'sku_name' => 'Americano', 'seller_sku' => 'INT-42-WRONG', 'price' => ['currency' => 'IDR', 'sale_price' => '20000'], 'inventory' => [['warehouse_id' => '7', 'quantity' => 3]], 'sales_attributes' => [['id' => '1', 'name' => 'Color', 'value_name' => 'Americano']]],
            ['id' => '92', 'sku_name' => 'Biru', 'seller_sku' => 'INT-42-BIRU', 'price' => ['currency' => 'IDR', 'sale_price' => '21000'], 'inventory' => [['warehouse_id' => '7', 'quantity' => 4]], 'sales_attributes' => [['id' => '1', 'name' => 'Color', 'value_name' => 'Biru']]],
        ]];
        Http::fake(function ($r) use (&$detail) {
            if (str_contains($r->url(), 'tiktok-sku.test')) {
                if ($r->method() === 'POST') {
                    $body = json_decode($r->body(), true);
                    $this->assertSame(['91', '92'], array_column($body['skus'], 'id'));
                    $this->assertSame(['CUSTOM-AGNI-A', 'INT-42-BIRU'], array_column($body['skus'], 'seller_sku'));
                    $this->assertSame([3, 4], array_map(fn ($s) => $s['inventory'][0]['quantity'], $body['skus']));
                    $detail['skus'][0]['seller_sku'] = 'CUSTOM-AGNI-A';
                    return Http::response(['code' => 0, 'data' => []]);
                }
                return Http::response(['code' => 0, 'data' => $detail]);
            }
            $this->assertSame('GET', $r->method());
            $data = str_contains($r->url(), 'get_item_base_info') ? ['item_list' => [['item_id' => 42, 'shop_id' => 11, 'item_name' => 'Source', 'item_status' => 'NORMAL', 'has_model' => true]]]
                : ['model' => [['model_id' => 1, 'model_name' => 'Americano', 'model_sku' => 'CUSTOM-AGNI-A'], ['model_id' => 2, 'model_name' => 'Biru', 'model_sku' => 'INT-42-BIRU']]];
            return Http::response(['error' => '', 'response' => $data]);
        });
        $controller = \Mockery::mock(OmnichannelController::class)->makePartial();
        $controller->shouldReceive('autoRefreshMarketplaceTokens')->andReturn(['status' => 'ok']);
        $result = $controller->updateMarketplaceVariantSku(Request::create('/api/sku-mapping/update-marketplace-variant-sku', 'POST', [
            'channel' => 'tiktok', 'account_key' => 'tiktok-agnishopbjm', 'product_id' => '900', 'sku_id' => '91', 'seller_sku' => 'CUSTOM-AGNI-A', 'repair_template' => true, 'expected_name' => 'Americano', 'expected_sku' => 'INT-42-WRONG',
        ]));
        $this->assertSame('ok', $result->getData(true)['status'], $result->getData(true)['response']['message'] ?? '');
        $this->assertSame('CUSTOM-AGNI-A', DB::table('tiktok_products')->value('seller_sku'));
        Http::assertSentCount(5);
    }

    public function test_manual_gita_update_cannot_bypass_agni_reference_or_write_source(): void
    {
        $this->link();
        Http::fake(function ($r) {
            $this->assertSame('GET', $r->method(), 'A mismatched source SKU must never be submitted.');
            $pid = (string) ($r['item_id_list'] ?? $r['item_id']);
            $data = str_contains($r->url(), 'get_item_base_info') ? ['item_list' => [['item_id' => (int) $pid, 'shop_id' => $pid === '42' ? 11 : 22, 'item_name' => 'Product', 'item_status' => 'NORMAL', 'has_model' => true]]]
                : ['model' => $pid === '42' ? [['model_id' => 1, 'model_name' => 'Americano', 'model_sku' => 'CUSTOM-AGNI-A'], ['model_id' => 2, 'model_name' => 'Biru', 'model_sku' => 'INT-42-BIRU']] : [['model_id' => 91, 'model_name' => 'Americano', 'model_sku' => 'INT-900-AMERICANO']]];
            return Http::response(['error' => '', 'response' => $data]);
        });
        $controller = \Mockery::mock(OmnichannelController::class)->makePartial();
        $controller->shouldReceive('autoRefreshMarketplaceTokens')->andReturn(['status' => 'ok']);
        $result = $controller->updateMarketplaceVariantSku(Request::create('/api/sku-mapping/update-marketplace-variant-sku', 'POST', [
            'channel' => 'shopee', 'account_key' => 'shopee-gitacollectionbjm', 'item_id' => '900', 'model_id' => '91', 'seller_sku' => 'INT-900-AMERICANO',
        ]));
        $this->assertSame('error', $result->getData(true)['status']); $this->assertSame(422, $result->getStatusCode());
        $this->assertSame('INT-900-AMERICANO', DB::table('shopee_product_model')->where('item_id', '900')->value('model_sku'));
        Http::assertSentCount(4);
    }

    public function test_agni_bulk_empty_generation_excludes_gita_rows(): void
    {
        Schema::table('shopee_product_model', fn (Blueprint $t) => $t->timestamp('updated_at')->nullable());
        Schema::create('stock_master', function (Blueprint $t) { $t->id(); $t->string('shopee_product_id')->nullable(); $t->string('shopee_sku')->nullable(); $t->string('shopee_seller_sku')->nullable(); $t->timestamp('updated_at')->nullable(); });
        DB::table('shopee_product_model')->whereIn('model_id', ['1', '91'])->update(['model_sku' => '']);
        Http::fake(function ($r) {
            $this->assertSame('POST', $r->method());
            $body = json_decode($r->body(), true);
            $this->assertSame(42, $body['item_id']);
            $this->assertSame('INT-42-AMERICANO', $body['model'][0]['model_sku']);
            return Http::response(['error' => '', 'response' => []]);
        });
        $controller = \Mockery::mock(OmnichannelController::class)->makePartial();
        $controller->shouldReceive('autoRefreshMarketplaceTokens')->andReturn(['status' => 'ok']);
        $result = $controller->bulkUpdateShopeeEmptyVariantSkus(Request::create('/api/sku-mapping/bulk-update-empty-shopee-variant-skus', 'POST'))->getData(true);
        $this->assertSame(1, $result['total_candidates']); $this->assertSame(1, $result['updated']);
        $this->assertSame('', DB::table('shopee_product_model')->where('item_id', '900')->value('model_sku'));
        Http::assertSentCount(1);
    }
}
