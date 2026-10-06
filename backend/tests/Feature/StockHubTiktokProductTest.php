<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockHubTiktokProductTest extends TestCase
{
    use RefreshDatabase;

    private string $mode = '';
    private int $creates = 0;
    private array $payload = [];
    private string $base = '/api/marketplace/tiktok-product-creation';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['shopee_mass_upload.stb_control_url' => '', 'marketplace_accounts.accounts.shopee-agnishopbjm' => ['enabled' => true, 'channel' => 'shopee', 'credentials' => ['partner_id' => 123, 'partner_key' => 'test-secret', 'host' => 'https://shop.test', 'redirect_url' => 'https://local.test']], 'marketplace_accounts.accounts.tiktok-agnishopbjm' => ['enabled' => true, 'channel' => 'tiktok', 'credentials' => ['app_key' => 'app', 'app_secret' => 'secret', 'api_host' => 'https://tt.test', 'auth_host' => 'https://tt.test', 'redirect_url' => 'https://local.test', 'warehouse_id' => 'wh']]]);
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-agnishopbjm', 'shop_id' => 11, 'access_token' => 'shopee-secret', 'is_active' => true, 'access_token_expire_at' => now()->addHour()]);
        Schema::create('tiktok_tokens', function (Blueprint $t) { $t->id(); $t->string('account_key'); $t->string('shop_id'); $t->string('access_token'); $t->boolean('is_active'); $t->timestamp('access_token_expire_at'); $t->timestamps(); });
        DB::table('tiktok_tokens')->insert(['account_key' => 'tiktok-agnishopbjm', 'shop_id' => '33', 'access_token' => 'tiktok-secret', 'is_active' => true, 'access_token_expire_at' => now()->addHour()]);
        Schema::create('tiktok_shops', function (Blueprint $t) { $t->id(); $t->string('shop_id'); $t->string('cipher'); $t->timestamps(); });
        DB::table('tiktok_shops')->insert(['shop_id' => '33', 'cipher' => 'cipher-secret']);
        Schema::create('stock_master', function (Blueprint $t) { $t->id(); $t->integer('stock'); });
        DB::table('stock_master')->insert(['stock' => 19]);
        Schema::create('tiktok_products', function (Blueprint $t) {
            $t->id(); foreach (['product_id','sku_id','product_name','sku_name','seller_sku','image_url','warehouse_id','product_status','audit_status'] as $c) { $t->string($c)->nullable(); }
            foreach (['stock_qty','price','subtotal'] as $c) { $t->integer($c)->nullable(); } $t->boolean('is_active')->default(true); $t->timestamps();
        });
        DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'channel' => 'shopee', 'remote_product_id' => '10', 'remote_variant_id' => '101', 'remote_identity_hash' => hash('sha256', 'source'), 'seller_sku' => 'INT-10-GREEN']);
        Http::fake(function ($r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/product/202509/products/90/partial_edit') {
                $this->assertSame('INT-10-GREEN', $r['skus'][0]['seller_sku']);
                throw new \Illuminate\Http\Client\ConnectionException('Outcome unknown after remote SKU edit');
            }
            if (str_contains($r->url(), 'img.test')) { return Http::response($this->mode === 'image' ? '' : 'image-bytes', $this->mode === 'image' ? 404 : 200, ['Content-Type' => 'image/jpeg']); }
            if (str_contains($path, 'get_item_base_info')) {
                if ($this->mode === 'relink_during_submit') {
                    DB::table('tiktok_tokens')->update(['shop_id' => '44']);
                    DB::table('tiktok_shops')->update(['shop_id' => '44', 'cipher' => 'changed-shop-cipher']);
                }
                $item = ['item_id' => 10, 'shop_id' => $this->mode === 'wrong_shop' ? 22 : 11, 'item_status' => 'NORMAL', 'has_model' => true, 'item_name' => 'Full product', 'description' => 'Original description', 'weight' => '0.5', 'dimension' => ['package_length' => 10, 'package_width' => 20, 'package_height' => 3], 'image' => ['image_url_list' => ['https://img.test/main.jpg']]];
                if ($this->mode === 'dimensions') { $item['weight'] = '0.08'; $item['dimension'] = ['package_length' => 0, 'package_width' => 0, 'package_height' => 0]; }
                if ($this->mode === 'description') { unset($item['description']); }
                return Http::response(['error' => '', 'response' => ['item_list' => [$item]]]);
            }
            if (str_contains($path, 'get_model_list')) {
                $models = [['model_id' => 101, 'model_name' => 'Green', 'model_sku' => 'INT-10-GREEN', 'tier_index' => [0], 'price_info' => [['current_price' => 150000, 'currency' => 'IDR']], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 0]]], ['model_id' => 102, 'model_name' => 'Blue', 'model_sku' => 'INT-10-BLUE', 'tier_index' => [1], 'price_info' => [['current_price' => $this->mode === 'drift' ? 180000 : 175000, 'currency' => 'IDR']], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 7]]]];
                if ($this->mode === 'missing_stock') { unset($models[0]['stock_info_v2']); }
                if ($this->mode === 'duplicate_sku') { $models[1]['model_sku'] = 'INT-10-GREEN'; }
                if ($this->mode === 'missing_sku') { $models[1]['model_sku'] = ''; }
                if ($this->mode === 'conflicting_prefix') { $models[1]['model_sku'] = 'INT-11-BLUE'; }
                if ($this->mode === 'missing_price') { unset($models[1]['price_info']); }
                if ($this->mode === 'matching_sku') { $models[0]['model_sku'] = 'CUSTOM-GREEN'; }
                return Http::response(['error' => '', 'response' => ['model' => $models, 'tier_variation' => [['name' => 'Color', 'option_list' => [['option' => 'Green', 'image' => ['image_url' => 'https://img.test/green.jpg']], ['option' => 'Blue', 'image' => ['image_url' => 'https://img.test/blue.jpg']]]]]]]);
            }
            $data = [];
            if ($path === '/product/202309/categories') { $data = ['categories' => [['id' => '600', 'parent_id' => '0', 'local_name' => 'Clothing', 'is_leaf' => $this->mode !== 'parent_category', 'permission_statuses' => [$this->mode === 'category_denied' ? 'INVITE_ONLY' : 'AVAILABLE']]]]; }
            elseif (str_ends_with($path, '/attributes')) { $data = ['attributes' => in_array($this->mode, ['attribute','attribute_typo']) ? [['id' => 'a', $this->mode === 'attribute_typo' ? 'is_requried' : 'is_required' => true, 'type' => 'PRODUCT_PROPERTY']] : []]; }
            elseif (str_ends_with($path, '/warehouses')) { $data = ['warehouses' => [['id' => 'wh', 'type' => $this->mode === 'return_warehouse' ? 'RETURN_WAREHOUSE' : 'SALES_WAREHOUSE', 'effect_status' => $this->mode === 'disabled_warehouse' ? 'DISABLED' : 'ENABLED']]]; }
            elseif (str_ends_with($path, '/products/search')) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);
                $data = ['products' => isset($query['page_token']) ? [] : [['id' => '90']], 'next_page_token' => isset($query['page_token']) ? '' : 'second', 'total_count' => 1];
                if ($this->mode === 'pagination') { unset($data['next_page_token']); }
                if ($this->mode === 'loop' && isset($query['page_token'])) { $data = ['products' => [['id' => '91']], 'next_page_token' => 'second', 'total_count' => 2]; }
                if ($this->mode === 'repeated_product' && isset($query['page_token'])) { $data = ['products' => [['id' => '90']], 'next_page_token' => '', 'total_count' => 1]; }
                if ($this->mode === 'missing_page') { $data['total_count'] = 2; }
            }
            elseif (str_ends_with($path, '/products/90')) { $data = ['id' => '90', 'title' => in_array($this->mode, ['same_title','matching_sku']) ? 'Full product' : 'Other product', 'skus' => [['id' => '901', 'seller_sku' => match ($this->mode) { 'duplicate' => 'INT-10-OTHER', 'matching_sku' => 'CUSTOM-GREEN', default => 'OTHER' }]]]; }
            elseif (str_ends_with($path, '/images/upload')) { $data = ['uri' => 'tiktok-uri-'.count(Http::recorded())]; }
            elseif ($path === '/product/202309/products' && $r->method() === 'POST') {
                $this->creates++; $this->payload = $r->data();
                $this->assertNotNull(DB::table('stock_hub_tiktok_product_runs')->where('status', 'submitting')->value('attempted_at'));
                if ($this->mode === 'unknown') { throw new \Illuminate\Http\Client\ConnectionException('secret signed URL'); }
                if ($this->mode === 'reject') { return Http::response(['code' => 120001, 'message' => 'secret rejection'], 400); }
                if ($this->mode === 'server_error') { return Http::response(['code' => 120001, 'message' => 'secret server failure'], 500); }
                if ($this->mode === 'contradictory_rejection') { return Http::response(['code' => 120001, 'data' => ['product_id' => '200']], 400); }
                if ($this->mode === 'malformed_code') { return Http::response(['code' => '0.0', 'data' => ['product_id' => '200']]); }
                if ($this->mode === 'no_id') { return Http::response(['code' => 0, 'data' => ['skus' => []]]); }
                $data = ['product_id' => '200', 'skus' => [['id' => '201'], ['id' => '202']]];
            }
            elseif (str_ends_with($path, '/products/200')) {
                if ($this->mode === 'readback') { return Http::response([], 500); }
                $data = ['id' => '200', 'shop_id' => '33', 'title' => 'Full product', 'status' => $this->mode === 'review' ? 'SELLER_DRAFT' : 'ACTIVATE', 'skus' => [['id' => '201', 'seller_sku' => 'INT-10-GREEN', 'price' => ['sale_price' => '150000'], 'inventory' => [['warehouse_id' => 'wh', 'quantity' => 0]]], ['id' => '202', 'seller_sku' => 'INT-10-BLUE', 'price' => ['sale_price' => '175000'], 'inventory' => [['warehouse_id' => 'wh', 'quantity' => 7]]]]];
                if ($this->mode === 'audit') { $data['audit'] = ['status' => 'AUDITING']; }
                if ($this->mode === 'wrong_target_shop') { $data['shop_id'] = '44'; }
                if ($this->mode === 'missing_read_sku') { array_pop($data['skus']); }
                if ($this->mode === 'wrong_read_sku') { $data['skus'][1]['seller_sku'] = 'OTHER'; }
                if ($this->mode === 'wrong_read_price') { $data['skus'][1]['price']['sale_price'] = '175001'; }
                if ($this->mode === 'wrong_read_stock') { $data['skus'][0]['inventory'][0]['quantity'] = 1; }
            }
            else { throw new \RuntimeException('Unexpected fake path '.$path); }
            if ($this->mode === 'corrected_category' && isset($data['categories'])) { $data['categories'][] = ['id' => '601', 'parent_id' => '0', 'local_name' => 'Bags', 'is_leaf' => true, 'permission_statuses' => ['AVAILABLE']]; }
            if ($this->mode === 'category_unknown' && isset($data['categories'])) { unset($data['categories'][0]['permission_statuses']); }
            if ($this->mode === 'warehouse_unknown' && isset($data['warehouses'])) { unset($data['warehouses'][0]['effect_status']); }
            return Http::response(['code' => 0, 'data' => $data]);
        });
    }

    private function start(array $context = ['category_id' => '600'], ?string $key = null): array
    {
        return $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => $key ?? (string) Str::uuid(), 'context' => $context])->assertSuccessful()->json('data');
    }

    private function finish(array $run): array
    {
        for ($i = 0; $i < 40 && $run['can_continue']; $i++) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertSuccessful()->json('data'); }
        $this->assertFalse($run['can_continue']);
        return $run;
    }

    public function test_complete_product_copies_all_variants_images_prices_and_zero_stock_without_changing_stock_master(): void
    {
        $run = $this->finish($this->start());
        $this->assertSame('success', $run['status'], json_encode([$run, Http::recorded()->map(fn ($x) => parse_url($x[0]->url(), PHP_URL_PATH)), json_decode(DB::table('stock_hub_tiktok_product_runs')->value('state'), true)['prepare'] ?? null]));
        $this->assertSame('200', $run['remote_product_id']);
        $this->assertSame(['150000','175000'], array_column(array_column($this->payload['skus'], 'price'), 'amount'));
        $this->assertSame([0,7], array_map(fn ($s) => $s['inventory'][0]['quantity'], $this->payload['skus']));
        $this->assertSame(['INT-10-GREEN','INT-10-BLUE'], array_column($this->payload['skus'], 'seller_sku'));
        $this->assertSame('Original description', $this->payload['description']);
        $this->assertCount(1, $this->payload['main_images']);
        $this->assertStringStartsWith('tiktok-uri-', $this->payload['skus'][1]['sales_attributes'][0]['sku_img']['uri']);
        $this->assertSame(2, DB::table('tiktok_products')->count());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        $this->assertSame('201', DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->value('remote_variant_id'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'update_stock'));
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertSuccessful();
        $this->assertSame(1, $this->creates);
    }

    public function test_missing_context_is_actionable_and_safe_to_retry(): void
    {
        $run = $this->finish($this->start([]));
        $this->assertSame('blocked', $run['status']); $this->assertTrue($run['can_retry']);
        $this->assertContains('category_id', array_column($run['required_fields'], 'key'));
        $this->assertSame('0.5', $run['context']['package_weight']['value']);
        $this->assertSame('success', $this->finish($this->start())['status']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidContext')]
    public function test_filled_invalid_context_exposes_correction_and_retains_other_values(string $mode, string $field, string $invalid, string $corrected): void
    {
        $this->mode = $mode;
        $run = $this->finish($this->start([$field => $invalid, ...($field === 'warehouse_id' ? ['category_id' => '600'] : [])]));
        $this->assertSame('blocked', $run['status']);
        $this->assertTrue($run['can_retry']);
        $this->assertSame(0, $this->creates);
        $saved = $this->getJson($this->base.'/source/10')->assertOk()->json('data');
        $this->assertSame([$field], array_column($saved['required_fields'], 'key'));
        $this->assertSame($invalid, $saved['context'][$field]);
        $context = $saved['context'];
        $context[$field] = $corrected;
        $this->mode = 'corrected_category';
        $next = $this->finish($this->start($context));
        $this->assertNotSame($run['run_id'], $next['run_id']);
        $this->assertSame('success', $next['status']);
        $this->assertSame($context, $next['context']);
        $this->assertSame($context['category_id'], $this->payload['category_id']);
        $this->assertSame('wh', $this->payload['skus'][0]['inventory'][0]['warehouse_id']);
        $this->assertSame(['value' => '0.5', 'unit' => 'KILOGRAM'], $this->payload['package_weight']);
        $this->assertSame(['unit' => 'CENTIMETER', 'length' => '10', 'width' => '20', 'height' => '3'], $this->payload['package_dimensions']);
        $this->assertSame([], $next['required_fields']);
        $this->assertFalse($next['can_retry']);
        $this->assertSame(1, $this->creates);
    }

    public static function invalidContext(): array
    {
        return [
            'warehouse' => ['', 'warehouse_id', 'invalid-wh', 'wh'],
            'category' => ['', 'category_id', '999', '600'],
            'unsupported attributes' => ['attribute', 'category_id', '600', '601'],
            'unsupported legacy attributes' => ['attribute_typo', 'category_id', '600', '601'],
        ];
    }

    public function test_explicit_rejection_exposes_context_corrections_without_allowing_uncertain_replay(): void
    {
        $this->mode = 'reject';
        $run = $this->finish($this->start());
        $this->assertSame('rejected', $run['status']);
        $saved = $this->getJson($this->base.'/source/10')->assertOk()->json('data');
        $this->assertTrue($saved['can_retry']);
        $this->assertSame(['category_id','warehouse_id','package_weight.value','package_dimensions.length','package_dimensions.width','package_dimensions.height'], array_column($saved['required_fields'], 'key'));
        $context = $saved['context'];
        $context['package_weight']['value'] = '0.75';
        $this->mode = 'unknown';
        $next = $this->finish($this->start($context));
        $this->assertNotSame($run['run_id'], $next['run_id']);
        $this->assertSame('0.75', $this->payload['package_weight']['value']);
        $this->assertSame('600', $this->payload['category_id']);
        $this->assertSame('wh', $this->payload['skus'][0]['inventory'][0]['warehouse_id']);
        $this->assertSame('submitted_unverified', $next['status']);
        $this->assertSame([], $next['required_fields']);
        $this->assertFalse($next['can_retry']);
        $this->assertSame($next['run_id'], $this->start()['run_id']);
        $this->assertSame(2, $this->creates);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedModes')]
    public function test_invalid_source_duplicates_incomplete_catalog_and_images_block_before_submission(string $mode): void
    {
        $this->mode = $mode;
        $run = $this->finish($this->start());
        $this->assertSame(in_array($mode, ['duplicate','matching_sku']) ? 'exists' : 'blocked', $run['status'], $mode);
        if ($mode === 'same_title') {
            $this->assertNull($run['result']);
            $this->assertNull($run['remote_product_id']);
            $this->assertStringContainsString('judul', $run['message']);
            $this->getJson($this->base.'/source/10')->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.result', null);
        }
        $this->assertSame(0, $this->creates);
        $this->assertSame(0, DB::table('tiktok_products')->count());
    }

    public static function blockedModes(): array
    {
        return array_map(fn ($m) => [$m], ['missing_stock','duplicate_sku','missing_sku','missing_price','conflicting_prefix','description','wrong_shop','pagination','image','attribute','attribute_typo','duplicate','matching_sku','same_title','loop','repeated_product','missing_page','category_denied','category_unknown','parent_category','return_warehouse','disabled_warehouse','warehouse_unknown']);
    }

    public function test_existing_source_link_remains_confirmed_without_creation(): void
    {
        DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => 'tiktok-agnishopbjm', 'channel' => 'tiktok', 'remote_product_id' => '90', 'remote_variant_id' => '901', 'remote_identity_hash' => hash('sha256', 'linked'), 'seller_sku' => 'INT-10-GREEN']);
        $run = $this->finish($this->start());
        $this->assertSame('exists', $run['status']);
        $this->assertFalse($run['can_retry']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->assertSame(0, $this->creates);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uncertainModes')]
    public function test_unknown_or_unverified_outcomes_preserve_identity_and_never_replay(string $mode): void
    {
        $this->mode = $mode;
        $run = $this->finish($this->start());
        $this->assertSame('submitted_unverified', $run['status']);
        $this->assertFalse($run['can_retry']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk();
        $this->assertSame(1, $this->creates);
        $this->assertSame(0, DB::table('tiktok_products')->count());
        $this->assertSame(0, DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->count());
        $this->assertStringNotContainsString('secret', json_encode($run));
    }

    public static function uncertainModes(): array
    {
        return array_map(fn ($m) => [$m], ['no_id','server_error','contradictory_rejection','malformed_code','wrong_target_shop','missing_read_sku','wrong_read_sku','wrong_read_price','wrong_read_stock']);
    }

    public function test_auditing_active_product_is_under_review_and_readback_requests_review_version(): void
    {
        $this->mode = 'audit';
        $run = $this->finish($this->start());
        $this->assertSame('under_review', $run['status']);
        $this->assertFalse($run['result']['published']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/products/200') && str_contains($r->url(), 'return_under_review_version=true'));
    }

    public function test_expired_owner_after_attempt_cannot_replay_even_without_saved_http_result(): void
    {
        $run = $this->start();
        DB::table('stock_hub_tiktok_product_runs')->where('id', $run['run_id'])->update(['status' => 'submitting', 'attempted_at' => now()]);
        DB::table('stock_hub_tiktok_product_guards')->update(['owner' => (string) Str::uuid(), 'expires_at' => now()->subMinute()]);
        $this->getJson($this->base.'/runs/'.$run['run_id'])->assertJsonPath('data.status', 'submitted_unverified')->assertJsonPath('data.can_continue', false);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertJsonPath('data.status', 'submitted_unverified');
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->assertSame(0, $this->creates);
        Http::assertNothingSent();
    }

    public function test_busy_guard_and_marketplace_lease_prevent_mutation(): void
    {
        $run = $this->start();
        DB::table('stock_hub_tiktok_product_guards')->update(['owner' => (string) Str::uuid(), 'expires_at' => now()->addMinute()]);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertJsonPath('data.status', 'preparing');
        Http::assertNothingSent();
        DB::table('stock_hub_tiktok_product_guards')->update(['owner' => null]);
        $lock = Cache::lock('stock-catalog-mutation', 60); $this->assertTrue($lock->get());
        try { $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertJsonPath('data.status', 'blocked'); }
        finally { $lock->release(); }
        Http::assertNothingSent();
    }

    public function test_request_identity_account_controls_and_category_response(): void
    {
        $this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data', null);
        Http::assertNothingSent();
        $key = (string) Str::uuid(); $run = $this->start(key: $key);
        $this->assertSame($run['run_id'], $this->start(key: $key)['run_id']);
        $this->postJson($this->base.'/runs', ['source_product_id' => '11', 'request_key' => $key, 'context' => ['category_id' => '600']])->assertStatus(409);
        $response = $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => (string) Str::uuid(), 'source_account' => 'shopee-gitacollectionbjm']);
        $this->assertSame(422, $response->status(), $response->getContent());
        $this->getJson($this->base.'/categories')->assertOk()->assertJsonPath('data.0.id', '600')->assertJsonPath('data.0.name', 'Clothing');
        $this->mode = 'category_denied';
        $this->getJson($this->base.'/categories')->assertOk()->assertJsonPath('data', []);
    }

    public function test_missing_dimensions_are_requested_without_inventing_values_and_operator_context_completes_product(): void
    {
        $this->mode = 'dimensions';
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']);
        $this->assertSame('0.08', $run['context']['package_weight']['value']);
        $this->assertSame(['package_dimensions.length','package_dimensions.width','package_dimensions.height'], array_column($run['required_fields'], 'key'));
        $context = $run['context'];
        $context['package_dimensions'] = ['length' => '10', 'width' => '20', 'height' => '3', 'unit' => 'CENTIMETER'];
        $this->assertSame('success', $this->finish($this->start($context))['status']);
        $this->assertSame('0.08', $this->payload['package_weight']['value']);
    }

    public function test_operator_warehouse_works_without_configured_warehouse_and_default_registry_remains_strict(): void
    {
        config(['marketplace_accounts.accounts.tiktok-agnishopbjm.credentials.warehouse_id' => '']);
        try { app(\App\Services\MarketplaceAccountRegistry::class)->tiktokContext('tiktok-agnishopbjm'); $this->fail('Default context must require warehouse'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('Konfigurasi', $e->getMessage()); }
        $run = $this->finish($this->start());
        $this->assertContains('warehouse_id', array_column($run['required_fields'], 'key'));
        $run = $this->finish($this->start(['category_id' => '600', 'warehouse_id' => 'wh']));
        $this->assertSame('success', $run['status'], $run['message']);
        $this->assertSame('wh', DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->value('warehouse_id'));
        $readiness = collect(app(\App\Services\MarketplaceAccountReadinessService::class)->all())->firstWhere('key', 'tiktok-agnishopbjm');
        $this->assertSame('ready', $readiness['state']);
        $this->assertTrue($readiness['checks']['warehouse']);
    }

    public function test_all_catalog_statuses_are_scanned_with_primary_account_signed_tokens(): void
    {
        $this->finish($this->start());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/products/search') && $r['status'] === 'ALL' && $r->hasHeader('x-tts-access-token', 'tiktok-secret'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'get_item_base_info') && str_contains($r->url(), 'shop_id=11'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'shop_id=22'));
    }

    public function test_target_shop_change_between_steps_blocks_before_create(): void
    {
        $run = $this->start();
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        DB::table('tiktok_tokens')->update(['shop_id' => '44']);
        DB::table('tiktok_shops')->update(['shop_id' => '44']);
        $run = $this->finish($run);
        $this->assertSame('blocked', $run['status']);
        $this->assertSame(0, $this->creates);
    }

    public function test_accepted_sku_ids_are_persisted_even_when_readback_fails(): void
    {
        $this->mode = 'readback';
        $run = $this->finish($this->start());
        $state = json_decode(DB::table('stock_hub_tiktok_product_runs')->where('id', $run['run_id'])->value('state'), true);
        $this->assertSame(['201','202'], $state['accepted_sku_ids'] ?? []);
    }

    public function test_intervening_locked_remote_sku_edit_invalidates_scan_even_when_ambiguous_and_cache_unchanged(): void
    {
        $run = $this->start();
        while ($run['stage'] !== 'submitting' && $run['can_continue']) {
            $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        }
        DB::table('tiktok_products')->insert(['product_id' => '90', 'sku_id' => '901', 'seller_sku' => 'OTHER']);
        Route::post('/test-legacy-sku-edit', function () {
            try {
                Http::post('https://legacy-edit.test/product/202509/products/90/partial_edit', ['skus' => [['id' => '901', 'seller_sku' => 'INT-10-GREEN']]]);
            } catch (\Illuminate\Http\Client\ConnectionException) {
                return response()->json(['status' => 'submitted_unverified']);
            }
        })->middleware(\App\Http\Middleware\StockCatalogMutationLock::class);
        $this->postJson('/test-legacy-sku-edit')->assertOk()->assertJsonPath('status', 'submitted_unverified');
        Cache::flush();
        $this->assertSame('OTHER', DB::table('tiktok_products')->where('product_id', '90')->value('seller_sku'));
        $run = $this->finish($run);
        $this->assertSame('blocked', $run['status']);
        $this->assertStringContainsString('Katalog', $run['message']);
        $this->assertSame(0, $this->creates);
        $this->assertNull(DB::table('stock_hub_tiktok_product_runs')->value('attempted_at'));
    }

    public function test_verification_does_not_overwrite_a_conflicting_listing_warehouse(): void
    {
        $run = $this->start();
        while ($run['stage'] !== 'verifying' && $run['can_continue']) {
            $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        }
        DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => 'tiktok-agnishopbjm', 'channel' => 'tiktok', 'remote_product_id' => '999', 'remote_variant_id' => '998', 'remote_identity_hash' => hash('sha256', 'conflicting'), 'seller_sku' => 'CURATED', 'warehouse_id' => 'curated-warehouse']);
        $run = $this->finish($run);
        $this->assertSame('success', $run['status']);
        $listing = DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->sole();
        $this->assertSame('999', $listing->remote_product_id);
        $this->assertSame('curated-warehouse', $listing->warehouse_id);
    }

    public function test_creation_fails_closed_when_durable_scan_revision_is_lost(): void
    {
        $run = $this->start();
        while ($run['stage'] !== 'submitting' && $run['can_continue']) {
            $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        }
        DB::table('marketplace_catalog_mutation_revision')->delete();
        $run = $this->finish($run);
        $this->assertSame('blocked', $run['status']);
        $this->assertSame(0, $this->creates);
        $this->assertNull(DB::table('stock_hub_tiktok_product_runs')->value('attempted_at'));
    }

    public function test_target_relink_during_source_revalidation_blocks_before_attempt_marker_or_create(): void
    {
        $run = $this->start();
        while ($run['stage'] !== 'submitting' && $run['can_continue']) {
            $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        }
        $this->mode = 'relink_during_submit';
        $run = $this->finish($run);
        $this->assertSame('blocked', $run['status']);
        $this->assertSame(0, $this->creates);
        $this->assertNull(DB::table('stock_hub_tiktok_product_runs')->value('attempted_at'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'changed-shop-cipher'));
    }

    public function test_uncertain_submission_never_replays_and_get_is_read_only(): void
    {
        $this->mode = 'unknown'; $key = (string) Str::uuid();
        $run = $this->finish($this->start(key: $key));
        $this->assertSame('submitted_unverified', $run['status']); $this->assertFalse($run['can_retry']);
        $calls = count(Http::recorded());
        $this->getJson($this->base.'/runs/'.$run['run_id'])->assertOk();
        $this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data.run_id', $run['run_id']);
        $this->assertSame($calls, count(Http::recorded()));
        $this->assertSame($run['run_id'], $this->start(key: $key)['run_id']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertSuccessful();
        $this->assertSame(1, $this->creates);
        $this->assertStringNotContainsString('secret', json_encode($run));
    }

    public function test_explicit_rejection_allows_new_attempt_and_readback_failure_only_reverifies(): void
    {
        $this->mode = 'reject'; $run = $this->finish($this->start());
        $this->assertSame('rejected', $run['status']); $this->assertTrue($run['can_retry']);
        $this->mode = 'readback'; $run = $this->finish($this->start());
        $this->assertSame('submitted_unverified', $run['status']); $this->assertSame('200', $run['remote_product_id']);
        $this->mode = 'review';
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->assertSame(2, $this->creates);
    }

    public function test_running_guard_and_source_drift_prevent_new_creation(): void
    {
        $run = $this->start(); $this->assertSame($run['run_id'], $this->start()['run_id']);
        while ($run['stage'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        $this->mode = 'drift';
        $this->assertSame('blocked', $this->finish($run)['status']);
        $this->assertSame(0, $this->creates);
    }
}
