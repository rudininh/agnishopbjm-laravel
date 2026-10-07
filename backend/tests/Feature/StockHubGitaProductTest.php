<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockHubGitaProductTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/api/marketplace/gita-product-creation';
    private string $mode = '';
    private array $writes = [];
    private array $parent = [];
    private array $models = [];
    private int $uploads = 0;
    private array $scanStatuses = [];
    private array $boundaryErrors = [];
    private array $transferFailures = [];
    private int $transferAttempts = 0;
    private array $downloadOptions = [];
    private array $downloadRequests = [];
    private array $sourceExtras = [];
    private array $sourceModelExtras = [];
    private array $targetModelExtras = [];
    private array $catalogPages = [];
    private ?array $parentRejection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindImageResolver(['93.184.216.34']);
        Http::preventStrayRequests();
        DB::table('shopee_config')->delete();
        config(['shopee_mass_upload.stb_control_url' => '']);
        foreach (['shopee-agnishopbjm' => 11, 'shopee-gitacollectionbjm' => 22] as $account => $shop) {
            config(['marketplace_accounts.accounts.'.$account => ['enabled' => true, 'channel' => 'shopee', 'credentials' => ['partner_id' => $shop + 100, 'partner_key' => 'test-key-'.$shop, 'host' => 'https://shop.test', 'redirect_url' => 'https://local.test']]]);
            DB::table('shopee_tokens')->insert(['account_key' => $account, 'shop_id' => $shop, 'access_token' => 'test-token-'.$shop, 'is_active' => true, 'access_token_expire_at' => now()->addHour()]);
        }
        Schema::create('stock_master', fn (Blueprint $t) => [$t->id(), $t->integer('stock')]);
        DB::table('stock_master')->insert(['stock' => 19]);
        DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'channel' => 'shopee', 'remote_product_id' => '10', 'remote_variant_id' => '101', 'remote_identity_hash' => hash('sha256', 'source'), 'seller_sku' => 'INT-10-GREEN']);
        Http::fake(function ($r, array $options) {
            try {
            $path = parse_url($r->url(), PHP_URL_PATH);
            parse_str(parse_url($r->url(), PHP_URL_QUERY) ?? '', $q);
            if (str_contains($r->url(), 'img.test') || ($r->method() === 'GET' && in_array('img.test', $r->header('Host'), true))) {
                $this->downloadOptions[] = $options;
                $this->downloadRequests[] = $r;
                if ($this->transferFailures !== []) {
                    $this->transferAttempts++; $failure = array_shift($this->transferFailures);
                    if ($failure === 'connection') { return Http::failedConnection('secret'); }
                    if (is_int($failure)) { return Http::response('error', $failure, ['Content-Type' => 'image/jpeg']); }
                    if ($failure === 'html') { return Http::response('<html>', 200, ['Content-Type' => 'text/html']); }
                }
                return Http::response('image-bytes', $this->mode === 'image' ? 404 : 200, ['Content-Type' => 'image/jpeg']);
            }
            if ($path === '/api/v2/media_space/upload_image') {
                $this->assertSame(['partner_id', 'timestamp', 'sign'], array_keys($q));
                $this->assertSame('122', (string) $q['partner_id']);
                $this->assertSame(hash_hmac('sha256', '122'.$path.$q['timestamp'], 'test-key-22'), $q['sign']);
                $this->assertTrue($r->hasFile('image', 'image-bytes', 'product.jpg'));
                if ($this->mode === 'upload_missing') { return Http::response(['error' => '', 'response' => []]); }
                if ($this->mode === 'upload_list') { return Http::response(['error' => '', 'response' => ['image_info_list' => [['id' => 0, 'error' => '', 'image_info' => ['image_id' => 'uploaded-'.++$this->uploads]]]]]); }
                return Http::response(['error' => '', 'response' => ['image_info' => ['image_id' => 'uploaded-'.++$this->uploads]]]);
            }
            $shop = (string) ($q['shop_id'] ?? '');
            $this->assertContains($shop, ['11', '22']);
            $this->assertSame(hash_hmac('sha256', ($shop + 100).$path.$q['timestamp'].'test-token-'.$shop.$shop, 'test-key-'.$shop), $q['sign'], 'Signature configuration '.json_encode(['partner_id' => $q['partner_id'], 'shop_id' => $shop]));
            $data = [];
            if ($path === '/api/v2/product/get_item_base_info') {
                $id = (string) $q['item_id_list'];
                $data = ['item_list' => [$shop === '11' ? $this->sourceParent() : ($id === '200' ? $this->targetParent() : [...$this->sourceParent(), 'item_id' => 90, 'shop_id' => 22, 'item_sku' => $this->mode === 'duplicate' ? 'INT-10-GREEN' : 'OTHER', 'item_name' => $this->mode === 'title' ? 'Full product title' : 'Other product', 'has_model' => false])]];
                if ($id === '90' && $this->mode === 'target_missing_sku') { unset($data['item_list'][0]['item_sku']); }
            } elseif ($path === '/api/v2/product/get_model_list') {
                $data = $shop === '11' ? $this->sourceModels() : ['model' => $this->models, 'tier_variation' => $this->targetTiers()];
                if ($shop === '22' && $data['model'] !== []) { $data['model'][0] = [...$data['model'][0], ...$this->targetModelExtras]; }
            } elseif ($path === '/api/v2/product/get_category') {
                $data = ['category_list' => [['category_id' => 100493, 'display_category_name' => 'Hijab Instan', 'parent_category_id' => 100, 'has_children' => $this->mode === 'category']]];
            } elseif ($path === '/api/v2/product/get_item_limit') {
                $data = $this->limits();
            } elseif ($path === '/api/v2/product/get_attribute_tree') {
                $data = ['list' => [['category_id' => 100493, 'attribute_tree' => [['attribute_id' => 1, 'mandatory' => true, 'attribute_info' => ['input_type' => 1, 'max_value_count' => 1], 'attribute_value_list' => [['value_id' => 2, 'name' => 'Cotton', 'child_attribute_list' => $this->mode === 'attribute' ? [['attribute_id' => 3, 'mandatory' => true, 'attribute_info' => ['input_type' => 1, 'max_value_count' => 1], 'attribute_value_list' => []]] : []]]]]]]];
                if ($this->mode === 'canonical') { $data['list'][0]['attribute_tree'][] = ['attribute_id' => 4, 'mandatory' => false, 'attribute_info' => ['input_type' => 1, 'max_value_count' => 1], 'attribute_value_list' => [['value_id' => 5, 'name' => 'Soft']]]; }
            } elseif ($path === '/api/v2/product/get_variation_tree') { return Http::response(['error' => '', 'data' => ['standardise_variation_list' => []]]);
            } elseif ($path === '/api/v2/product/get_brand_list') {
                $data = ['brand_list' => $this->mode === 'no_brand' ? [['brand_id' => 0, 'original_brand_name' => 'NoBrand']] : [], 'has_next_page' => false, 'is_mandatory' => in_array($this->mode, ['brand','no_brand'], true)];
                if ($this->mode === 'positive_brand_found') {
                    if ((int) $q['offset'] >= 200) { return Http::response(['error' => 'unavailable'], 503); }
                    $data = ['brand_list' => (int) $q['offset'] === 0 ? [['brand_id' => 0, 'original_brand_name' => 'No Brand']] : [['brand_id' => 7, 'original_brand_name' => 'Known Brand']], 'is_mandatory' => true, 'has_next_page' => true, 'next_offset' => (int) $q['offset'] + 100];
                }
                if ($this->mode === 'positive_brand_mismatch') { $data['brand_list'] = [['brand_id' => 7, 'original_brand_name' => 'Other Brand'], ['brand_id' => 8, 'original_brand_name' => 'Known Brand']]; }
            } elseif ($path === '/api/v2/logistics/get_channel_list') {
                $data = ['logistics_channel_list' => [['logistics_channel_id' => 8003, 'logistics_channel_name' => 'Regular', 'enabled' => $this->mode !== 'logistics', 'fee_type' => 'FIXED_DEFAULT', 'seller_logistic_has_configuration' => true]]];
                if ($this->mode === 'canonical') { $data['logistics_channel_list'][] = ['logistics_channel_id' => 8005, 'logistics_channel_name' => 'Express', 'enabled' => true, 'fee_type' => 'SIZE_INPUT']; }
            } elseif ($path === '/api/v2/shop/get_warehouse_detail') {
                if ($this->mode === 'warehouse_error') { return Http::response(['error' => 'other.error', 'message' => 'secret']); }
                if ($this->mode === 'warehouse') { $data = [['warehouse_id' => 1, 'warehouse_type' => 1, 'location_id' => 'target-a', 'holiday_mode_state' => 0], ['warehouse_id' => 2, 'warehouse_type' => 1, 'location_id' => 'target-b', 'holiday_mode_state' => 0]]; }
                else { return Http::response(['error' => 'warehouse.error_not_in_whitelist']); }
            } elseif ($path === '/api/v2/product/get_item_list') {
                $status = $q['item_status']; $this->scanStatuses[] = $status;
                $candidate = in_array($this->mode, ['title','duplicate','target_missing_sku'], true) && $status === 'NORMAL';
                $data = ['item' => $candidate ? [['item_id' => 90]] : [], 'has_next_page' => false, 'total_count' => $candidate ? 1 : 0];
                if ($this->mode === 'pagination') { unset($data['has_next_page']); }
                if ($this->mode === 'page_loop') { $data = ['item' => [['item_id' => 90]], 'has_next_page' => true, 'next_offset' => 0, 'total_count' => 2]; }
                $data = $this->catalogPages[$status] ?? $data;
            } elseif (in_array($path, ['/api/v2/product/add_item', '/api/v2/product/init_tier_variation', '/api/v2/product/unlist_item'])) {
                $this->assertSame('22', $shop);
                $column = match ($path) { '/api/v2/product/add_item' => 'attempted_at', '/api/v2/product/init_tier_variation' => 'variants_attempted_at', default => 'publication_attempted_at' };
                $this->assertTrue(DB::table('stock_hub_gita_product_runs')->whereNotNull($column)->exists());
                $this->writes[] = [$path, $r->data()];
                if ($path === '/api/v2/product/add_item') {
                    if ($this->parentRejection !== null) { return Http::response($this->parentRejection, 400); }
                    if ($this->mode === 'unknown') { return Http::failedConnection('secret'); }
                    if ($this->mode === 'reject') { return Http::response(['error' => 'product.invalid', 'message' => 'secret'], 400); }
                    if ($this->mode === 'server') { return Http::response(['error' => 'product.invalid'], 500); }
                    if ($this->mode === 'contradiction') { return Http::response(['error' => 'product.invalid', 'response' => ['item_id' => 200]], 400); }
                    if ($this->mode === 'no_id') { return Http::response(['error' => '', 'response' => []]); }
                    if ($this->mode === 'wrong_id') { return Http::response(['error' => '', 'response' => ['item_id' => 10]]); }
                    $this->parent = $r->data(); $data = ['item_id' => 200];
                } elseif ($path === '/api/v2/product/init_tier_variation') {
                    $this->models = array_map(fn ($m, $i) => [...$m, 'model_id' => 201 + $i, 'model_status' => 'MODEL_NORMAL', 'price_info' => [['currency' => 'IDR', 'current_price' => $m['original_price']]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => $m['seller_stock'][0]['stock']]]], $r['model'], array_keys($r['model']));
                    $data = ['model' => $this->models];
                    if ($this->mode === 'init_unknown') { return Http::failedConnection('secret'); }
                    if ($this->mode === 'init_partial') { array_pop($this->models); return Http::failedConnection('secret'); }
                } else {
                    $this->parent['item_status'] = $this->mode === 'review' ? 'REVIEWING' : 'NORMAL';
                    $data = ['success_list' => [['item_id' => 200, 'unlist' => false]], 'failure_list' => []];
                    if ($this->mode === 'publish_missing') { unset($data['failure_list']); $this->parent['item_status'] = 'UNLIST'; }
                    if ($this->mode === 'publish_wrong') { $data['success_list'][0]['item_id'] = 999; $this->parent['item_status'] = 'UNLIST'; }
                    if ($this->mode === 'publish_failure') { $data['failure_list'] = [['item_id' => 200]]; $this->parent['item_status'] = 'UNLIST'; }
                }
            } else { throw new \RuntimeException('Unexpected boundary '.$path); }
            return Http::response(['error' => '', 'response' => $data]);
            } catch (\Throwable $e) { $this->boundaryErrors[] = $e->getMessage(); throw $e; }
        });
    }

    private function sourceParent(): array
    {
        $p = ['item_id' => 10, 'shop_id' => 11, 'item_status' => 'NORMAL', 'item_name' => 'Full product title', 'item_sku' => 'INT-10', 'has_model' => true, 'category_id' => 100493, 'description_type' => 'normal', 'description' => 'Complete original product description', 'weight' => '0.5', 'dimension' => ['package_length' => 10, 'package_width' => 20, 'package_height' => 3], 'image' => ['image_id_list' => ['source-main'], 'image_url_list' => ['https://img.test/main.jpg']], 'size_chart' => 'https://img.test/chart.jpg', 'brand' => ['brand_id' => 0, 'original_brand_name' => 'No Brand'], 'attribute_list' => [['attribute_id' => 1, 'attribute_value_list' => [['value_id' => 2, 'original_value_name' => 'Cotton']]]], 'logistic_info' => [['logistic_id' => 8003, 'enabled' => true, 'is_free' => false]], 'pre_order' => ['is_pre_order' => false, 'days_to_ship' => 2], 'condition' => 'NEW', 'item_dangerous' => 0, 'price_info' => [['current_price' => 150000, 'currency' => 'IDR']], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 0]]];
        if ($this->mode === 'wrong_shop') { $p['shop_id'] = 22; }
        if ($this->mode === 'disabled') { $p['item_status'] = 'UNLIST'; }
        if ($this->mode === 'chart') { unset($p['size_chart']); }
        if ($this->mode === 'template') { unset($p['size_chart']); $p['size_chart_id'] = 999; }
        if ($this->mode === 'chart_bad') { $p['size_chart'] = 'unsupported://secret'; }
        if ($this->mode === 'chart_private') { $p['size_chart'] = 'https://localhost/private.jpg'; }
        if ($this->mode === 'variantless') { $p['has_model'] = false; }
        if ($this->mode === 'drift' && $this->uploads) { $p['item_name'] = 'Changed title'; }
        if ($this->mode === 'canonical') {
            $p['attribute_list'][] = ['attribute_id' => 4, 'attribute_value_list' => [['value_id' => 5, 'original_value_name' => 'Soft']]];
            $p['logistic_info'][] = ['logistic_id' => 8005, 'enabled' => true, 'is_free' => false];
            if ($this->uploads) { $p['attribute_list'] = array_reverse($p['attribute_list']); $p['logistic_info'] = array_reverse($p['logistic_info']); $p['dimension'] = array_reverse($p['dimension'], true); $p['pre_order'] = array_reverse($p['pre_order'], true); }
        }
        if ($this->mode === 'extended') { $p['description_type'] = 'extended'; $p['description_info'] = ['extended_description' => ['field_list' => [['field_type' => 'text', 'text' => 'Complete original product description'], ['field_type' => 'image', 'image_info' => ['image_url' => 'https://img.test/description.jpg']]]]]; }
        return [...$p, ...$this->sourceExtras];
    }

    private function sourceModels(): array
    {
        if ($this->mode === 'variantless') { return ['model' => [], 'tier_variation' => []]; }
        $models = [['model_id' => 101, 'model_sku' => 'INT-10-GREEN', 'tier_index' => [0], 'model_status' => 'MODEL_NORMAL', 'price_info' => [['current_price' => 150000, 'currency' => 'IDR']], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 0]]], ['model_id' => 102, 'model_sku' => 'INT-10-BLUE', 'tier_index' => [1], 'model_status' => 'MODEL_NORMAL', 'price_info' => [['current_price' => 175000, 'currency' => 'IDR']], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 7]]]];
        if ($this->mode === 'stock') { unset($models[0]['stock_info_v2']); }
        if ($this->mode === 'price') { $models[0]['price_info'][0]['currency'] = 'USD'; }
        if ($this->mode === 'sku') { $models[1]['model_sku'] = 'INT-10-GREEN'; }
        if ($this->mode === 'prefix') { $models[1]['model_sku'] = 'INT-11-BLUE'; }
        if ($this->mode === 'negative_stock') { $models[0]['stock_info_v2']['summary_info']['total_available_stock'] = -1; }
        if ($this->mode === 'float_stock') { $models[0]['stock_info_v2']['summary_info']['total_available_stock'] = 0.5; }
        if ($this->mode === 'missing_price') { unset($models[0]['price_info']); }
        if ($this->mode === 'empty_sku') { $models[0]['model_sku'] = ''; }
        if ($this->mode === 'duplicate_index') { $models[1]['tier_index'] = [0]; }
        if ($this->mode === 'long_sku') { $models[0]['model_sku'] = 'INT-10-'.str_repeat('X', 80); }
        if ($this->mode === 'model_dts') { $models[0]['pre_order'] = ['is_pre_order' => false, 'days_to_ship' => 3]; }
        if ($this->mode === 'model_bad_weight') { $models[0]['weight'] = -1; }
        if ($this->mode === 'model_overrides') { $models[0]['weight'] = '0.7'; $models[0]['dimension'] = ['package_length' => 12, 'package_width' => 21, 'package_height' => 4]; $models[0]['pre_order'] = ['is_pre_order' => true, 'days_to_ship' => 4]; $models[0]['gtin_code'] = '00'; }
        $models[0] = [...$models[0], ...$this->sourceModelExtras];
        $tiers = [['name' => 'Color', 'option_list' => [['option' => 'Green', 'image' => ['image_id' => 'source-green', 'image_url' => 'https://img.test/green.jpg']], ['option' => 'Blue', 'image' => ['image_id' => 'source-blue', 'image_url' => 'https://img.test/blue.jpg']]]]];
        if ($this->mode === 'option_missing_url') { unset($tiers[0]['option_list'][0]['image']['image_url']); }
        if ($this->mode === 'two_tiers') {
            $tiers[] = ['name' => 'Size', 'option_list' => [['option' => 'Small'], ['option' => 'Large']]];
            $models[0]['tier_index'] = [0,0]; $models[1]['tier_index'] = [1,0];
            $models[] = [...$models[0], 'model_id' => 103, 'model_sku' => 'INT-10-GREEN-L', 'tier_index' => [0,1]];
            $models[] = [...$models[1], 'model_id' => 104, 'model_sku' => 'INT-10-BLUE-L', 'tier_index' => [1,1]];
        }
        if ($this->mode === 'too_many') { $models = array_fill(0, 51, $models[0]); }
        return ['model' => $this->mode === 'order' && $this->uploads ? array_reverse($models) : $models, 'tier_variation' => $tiers];
    }

    private function limits(): array
    {
        return ['price_limit' => ['min_limit' => 99, 'max_limit' => 1000000000], 'stock_limit' => ['min_limit' => 0, 'max_limit' => 10000000], 'item_name_length_limit' => ['min_limit' => 5, 'max_limit' => 255], 'item_description_length_limit' => ['min_limit' => 20, 'max_limit' => 3000], 'item_image_count_limit' => ['min_limit' => 1, 'max_limit' => 9], 'tier_variation_name_length_limit' => ['min_limit' => 1, 'max_limit' => 14], 'tier_variation_option_length_limit' => ['min_limit' => 1, 'max_limit' => 20], 'dts_limit' => ['non_pre_order_days_to_ship' => 2, 'days_to_ship_limit' => ['min_limit' => 3, 'max_limit' => 10]], 'size_chart_limit' => ['size_chart_mandatory' => $this->mode !== 'template', 'support_image_size_chart' => true], 'gtin_limit' => ['gtin_validation_rule' => $this->mode === 'gtin' ? 'Mandatory' : 'Optional']];
    }

    private function targetParent(): array
    {
        $p = [...$this->sourceParent(), ...$this->parent, 'item_id' => 200, 'shop_id' => 22, 'has_model' => count($this->models) > 0, 'image' => [...($this->parent['image'] ?? []), 'image_url_list' => array_map(fn ($id) => 'https://cf.shopee.co.id/file/'.$id, $this->parent['image']['image_id_list'] ?? [])], 'size_chart' => $this->parent['size_chart_info']['size_chart'] ?? '', 'price_info' => [['current_price' => $this->parent['original_price'] ?? 150000, 'currency' => 'IDR']], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => $this->parent['seller_stock'][0]['stock'] ?? 0]]];
        if ($this->mode === 'readback_stock' && $this->models) { $this->models[0]['stock_info_v2']['summary_info']['total_available_stock'] = 1; }
        if ($this->mode === 'readback_gallery') { $p['image']['image_id_list'] = ['wrong-image']; }
        return $p;
    }

    private function targetTiers(): array
    {
        if (!$this->models) { return []; }
        $init = array_values(array_filter($this->writes, fn ($w) => str_ends_with($w[0], 'init_tier_variation')))[0][1];
        return array_map(fn ($t) => ['name' => $t['variation_name'], 'option_list' => array_map(fn ($o) => ['option' => $o['variation_option_name'], 'image' => ['image_id' => $o['image_id'] ?? '', 'image_url' => isset($o['image_id']) ? 'https://cf.shopee.co.id/file/'.$o['image_id'] : '']], $t['variation_option_list'])], $init['standardise_tier_variation']);
    }

    private function start(array $context = [], ?string $key = null): array
    {
        return $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => $key ?? (string) Str::uuid(), 'context' => $context])->assertSuccessful()->json('data');
    }

    private function finish(array $r): array
    {
        for ($i = 0; $i < 65 && $r['can_continue']; $i++) {
            if (($r['next_step_after_ms'] ?? 0) > 0) { $this->travel(6)->seconds(); }
            $r = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertSuccessful()->json('data');
        }
        $this->assertFalse($r['can_continue']);
        return $r;
    }

    public function test_start_and_recovery_are_database_only_and_context_is_bound_to_key(): void
    {
        $key = (string) Str::uuid(); $r = $this->start([], $key);
        Http::assertNothingSent();
        $this->assertSame($r, $this->getJson($this->base.'/source/10')->assertOk()->json('data'));
        $this->assertSame($r, $this->getJson($this->base.'/runs/'.$r['run_id'])->assertOk()->json('data'));
        $this->assertSame($r['run_id'], $this->start()['run_id']);
        $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => $key, 'context' => ['category_id' => '100493']])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_full_copy_preserves_prices_zero_stock_images_and_publishes_only_after_models(): void
    {
        $r = $this->finish($this->start());
        $this->assertSame('success', $r['status'], json_encode([$r,$this->boundaryErrors]));
        $this->assertSame('200', $r['remote_product_id']);
        $this->assertSame(['NORMAL', 'UNLIST', 'BANNED', 'REVIEWING', 'SELLER_DELETE', 'SHOPEE_DELETE'], $this->scanStatuses);
        [$create, $init, $publish] = array_column($this->writes, 1);
        $this->assertSame('UNLIST', $create['item_status']);
        $this->assertSame([['stock' => 0]], $create['seller_stock']);
        $this->assertArrayHasKey('size_chart_info', $create);
        $this->assertArrayNotHasKey('scheduled_publish_time', $create);
        $this->assertArrayNotHasKey('tier_variation', $init);
        $this->assertSame('INT-10-GREEN', $init['model'][0]['model_sku']);
        $this->assertSame([0], $init['model'][0]['tier_index']);
        $this->assertSame(150000, $init['model'][0]['original_price']);
        $this->assertSame(175000, $init['model'][1]['original_price']);
        $this->assertSame([['stock' => 0]], $init['model'][0]['seller_stock']);
        $this->assertSame([['stock' => 7]], $init['model'][1]['seller_stock']);
        $this->assertSame(['item_list' => [['item_id' => 200, 'unlist' => false]]], $publish);
        $this->assertSame(4, $this->uploads);
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        $this->assertSame(1, DB::table('stock_master')->count());
        $this->assertSame('101', DB::table('marketplace_listings')->where('account_key', 'shopee-agnishopbjm')->value('remote_variant_id'));
        $this->assertSame('201', DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->value('remote_variant_id'));
    }

    public function test_variantless_skips_init_and_preserves_parent_zero(): void
    {
        $this->mode = 'variantless'; $r = $this->finish($this->start());
        $this->assertSame('success', $r['status']); $this->assertCount(2, $this->writes);
        $this->assertSame('0', $r['result']['skus'][0]['id']);
    }

    public function test_empty_statuses_without_item_list_do_not_block_full_catalog_scan(): void
    {
        foreach (['NORMAL', 'UNLIST', 'BANNED', 'REVIEWING', 'SHOPEE_DELETE'] as $status) {
            $this->catalogPages[$status] = ['total_count' => 0, 'has_next_page' => false, 'next' => null];
        }
        $this->catalogPages['SELLER_DELETE'] = ['item' => [['item_id' => 90]], 'total_count' => 1, 'has_next_page' => false, 'next' => null];
        $r = $this->finish($this->start());
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertTrue($r['result']['published']);
        $this->assertSame(['NORMAL', 'UNLIST', 'BANNED', 'REVIEWING', 'SELLER_DELETE', 'SHOPEE_DELETE'], $this->scanStatuses);
        $this->assertSame(1, $r['progress']['scanned_products']);
        $this->assertCount(3, $this->writes);
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_empty_statuses_still_check_deleted_catalog_for_duplicate_skus(): void
    {
        $this->mode = 'duplicate';
        foreach (['NORMAL', 'UNLIST', 'BANNED', 'REVIEWING'] as $status) {
            $this->catalogPages[$status] = ['total_count' => 0, 'has_next_page' => false, 'next' => null];
        }
        $this->catalogPages['SELLER_DELETE'] = ['item' => [['item_id' => 90]], 'total_count' => 1, 'has_next_page' => false, 'next' => null];
        $r = $this->finish($this->start());
        $this->assertSame('exists', $r['status']);
        $this->assertSame('90', $r['result']['product_id']);
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->downloadRequests);
        $this->assertSame(0, DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->count());
    }

    public static function incompleteEmptyCatalogPages(): array
    {
        return [
            'positive total without list' => [['total_count' => 1, 'has_next_page' => false]],
            'unknown total' => [['has_next_page' => false]],
            'another page' => [['total_count' => 0, 'has_next_page' => true, 'next_offset' => 100]],
            'unknown pagination' => [['total_count' => 0]],
            'boolean total' => [['total_count' => false, 'has_next_page' => false]],
            'null list' => [['item' => null, 'total_count' => 0, 'has_next_page' => false]],
            'malformed list' => [['item' => false, 'total_count' => 0, 'has_next_page' => false]],
            'count contradicts list' => [['item' => [], 'total_count' => 1, 'has_next_page' => false]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteEmptyCatalogPages')]
    public function test_incomplete_empty_catalog_evidence_blocks_before_upload_or_creation(array $page): void
    {
        $this->catalogPages['NORMAL'] = $page;
        $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']);
        $this->assertTrue($r['can_retry']);
        $this->assertNull($r['remote_product_id']);
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->downloadRequests);
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_seller_fulfilled_catalog_bound_models_copy_without_transferring_catalog_ids(): void
    {
        $this->sourceExtras = ['is_fulfillment_by_shopee' => false, 'ssp_id' => 500];
        $this->sourceModelExtras = ['is_fulfillment_by_shopee' => false, 'ssp_id' => 501, 'cssp_id' => 601];
        $this->targetModelExtras = ['is_fulfillment_by_shopee' => false, 'ssp_id' => 900, 'cssp_id' => 901];
        $this->legacyCache();
        $sourceParent = DB::table('shopee_product')->where('item_id', 10)->first();
        $sourceModel = DB::table('shopee_product_model')->where('model_id', '101')->first();
        $r = $this->finish($this->start());
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertTrue($r['result']['published']);
        $this->assertCount(3, $this->writes);
        $init = $this->writes[1][1];
        $this->assertSame(['INT-10-GREEN', 'INT-10-BLUE'], array_column($init['model'], 'model_sku'));
        $this->assertSame([150000, 175000], array_column($init['model'], 'original_price'));
        $this->assertSame([0, 7], array_map(fn ($m) => $m['seller_stock'][0]['stock'], $init['model']));
        foreach (['ssp_id', 'cssp_id'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'":', json_encode($this->writes));
            $this->assertStringNotContainsString('"'.$field.'":', DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'));
        }
        $this->assertEquals($sourceParent, DB::table('shopee_product')->where('item_id', 10)->first());
        $this->assertEquals($sourceModel, DB::table('shopee_product_model')->where('model_id', '101')->first());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        $this->assertSame('101', DB::table('marketplace_listings')->where('account_key', 'shopee-agnishopbjm')->value('remote_variant_id'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && str_contains($request->url(), 'shop_id=11'));
    }

    public static function unsupportedShopeeFulfillment(): array
    {
        return ['parent' => [true], 'variant' => [false]];
    }

    public function test_verified_positive_brand_does_not_depend_on_remaining_brand_pages(): void
    {
        $this->mode = 'positive_brand_found';
        $this->sourceExtras = ['brand' => ['brand_id' => 7, 'original_brand_name' => 'Known Brand']];
        $r = $this->finish($this->start());
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertTrue($r['result']['published']);
        $this->assertSame(['brand_id' => 7, 'original_brand_name' => 'Known Brand'], $this->writes[0][1]['brand']);
        Http::assertNotSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            return parse_url($request->url(), PHP_URL_PATH) === '/api/v2/product/get_brand_list' && (int) ($query['offset'] ?? 0) >= 200;
        });
    }

    public function test_positive_brand_requires_both_the_source_id_and_name(): void
    {
        $this->mode = 'positive_brand_mismatch';
        $this->sourceExtras = ['brand' => ['brand_id' => 7, 'original_brand_name' => 'Known Brand']];
        $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']);
        $this->assertTrue($r['can_retry']);
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->downloadRequests);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedShopeeFulfillment')]
    public function test_shopee_fulfillment_remains_blocked_before_upload_or_creation(bool $parent): void
    {
        if ($parent) { $this->sourceExtras = ['is_fulfillment_by_shopee' => true]; }
        else { $this->sourceModelExtras = ['is_fulfillment_by_shopee' => true, 'ssp_id' => 501, 'cssp_id' => 601]; }
        $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']);
        $this->assertTrue($r['can_retry']);
        $this->assertNull($r['remote_product_id']);
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->downloadRequests);
        $this->assertSame(0, $this->uploads);
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_invalid_source_or_metadata_blocks_before_any_parent_mutation(): void
    {
        foreach (['wrong_shop', 'disabled', 'stock', 'negative_stock', 'float_stock', 'price', 'missing_price', 'sku', 'empty_sku', 'prefix', 'duplicate_index', 'too_many', 'category', 'chart', 'attribute', 'brand', 'gtin', 'logistics', 'warehouse_error', 'pagination', 'page_loop', 'target_missing_sku', 'title', 'image', 'drift', 'extended'] as $mode) {
            $this->mode = $mode; $this->uploads = 0;
            $r = $this->finish($this->start());
            $this->assertSame('blocked', $r['status'], $mode);
            $this->assertTrue($r['can_retry'], $mode); $this->assertSame([], $this->writes, $mode);
            $this->assertStringNotContainsString('secret', json_encode($r));
        }
    }

    public function test_multiple_warehouses_require_verified_choice(): void
    {
        $this->mode = 'warehouse'; $r = $this->finish($this->start());
        $this->assertSame('location_id', $r['required_fields'][0]['key']);
        $this->assertSame([['id' => 'target-a', 'name' => 'target-a'], ['id' => 'target-b', 'name' => 'target-b']], $r['required_fields'][0]['options']);
        $r = $this->finish($this->start(['location_id' => 'target-b']));
        $this->assertSame('success', $r['status']);
        $this->assertSame('target-b', $this->writes[0][1]['seller_stock'][0]['location_id']);
    }

    public function test_uncertain_parent_never_replays_but_explicit_rejection_can_retry(): void
    {
        foreach (['unknown', 'server', 'contradiction', 'no_id', 'wrong_id', 'reject'] as $mode) {
            $this->mode = $mode; $this->writes = [];
            // independent source guard for each case
            if (Schema::hasTable('stock_hub_gita_product_guards')) { DB::table('stock_hub_gita_product_guards')->delete(); }
            $r = $this->finish($this->start());
            $this->assertSame($mode === 'reject' ? 'rejected' : 'submitted_unverified', $r['status']);
            $this->assertSame($mode === 'reject', $r['can_retry']);
            $r = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data');
            $this->assertCount(1, $this->writes); $this->assertStringNotContainsString('secret', json_encode($r));
        }
    }

    public static function identifiedParentRejections(): array
    {
        return [
            'package dimension' => ['package_height must be greater than zero', 'dimension', 'Dimensi paket'],
            'chart address' => ['size_chart_image_url is invalid', 'size_chart_image_url', 'Tabel ukuran'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('identifiedParentRejections')]
    public function test_parent_rejection_retains_safe_code_and_identifies_the_invalid_field(string $reason, string $field, string $guidance): void
    {
        $this->parentRejection = ['error' => 'product.error_param', 'message' => $reason.'; access_token=secret', 'request_id' => 'private-request'];
        $r = $this->finish($this->start());
        $this->assertSame('rejected', $r['status']);
        $this->assertTrue($r['can_retry']);
        $this->assertNull($r['remote_product_id']);
        $this->assertStringContainsString('product.error_param', $r['message']);
        $this->assertStringContainsString($guidance, $r['message']);
        $saved = json_decode(DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'), true);
        $this->assertSame(['code' => 'product.error_param', 'field' => $field], $saved['rejection']);
        if ($field === 'dimension') {
            $this->assertSame([['key' => 'dimension', 'label' => 'Dimensi paket', 'type' => 'number', 'unit' => 'cm']], $r['required_fields']);
        }
        $this->assertStringNotContainsString('secret', json_encode($saved));
        $this->assertStringNotContainsString('private-request', json_encode($saved));
        $this->assertStringNotContainsString($reason, json_encode($saved));
        $this->assertSame($r, $this->getJson($this->base.'/runs/'.$r['run_id'])->assertOk()->json('data'));
        $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk();
        $this->assertCount(1, $this->writes);
    }

    public static function untrustedParentRejections(): array
    {
        return [
            'unknown code and secret message' => [['error' => 'access_token=secret', 'message' => 'secret'], null],
            'structured message' => [['error' => 'product.error_param', 'message' => ['access_token' => 'secret']], 'product.error_param'],
            'ambiguous fields' => [['error' => 'product.error_param', 'message' => 'image and category_id invalid; secret'], 'product.error_param'],
        ];
    }

    public static function invalidPackageDimensions(): array
    {
        return [
            'all zero' => [['package_length' => 0, 'package_width' => 0, 'package_height' => 0]],
            'one zero' => [['package_length' => 10, 'package_width' => 20, 'package_height' => 0]],
            'negative' => [['package_length' => 10, 'package_width' => -1, 'package_height' => 3]],
            'fractional' => [['package_length' => 10, 'package_width' => 20, 'package_height' => 0.5]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPackageDimensions')]
    public function test_invalid_package_dimensions_require_correction_before_images_or_parent_submission(array $dimension): void
    {
        $this->sourceExtras = ['dimension' => $dimension];
        $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']);
        $this->assertSame(['dimension'], array_column($r['required_fields'], 'key'));
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->downloadRequests);
        $this->assertNull(DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('attempted_at'));
        $r = $this->finish($this->start(['dimension' => ['package_length' => 12, 'package_width' => 21, 'package_height' => 4]]));
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertSame(['package_length' => 12, 'package_width' => 21, 'package_height' => 4], $this->writes[0][1]['dimension']);
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && str_contains($request->url(), 'shop_id=11'));
    }

    public function test_operator_cannot_retry_zero_dimensions_past_metadata_validation(): void
    {
        $r = $this->finish($this->start(['dimension' => ['package_length' => 0, 'package_width' => 0, 'package_height' => 0]]));
        $this->assertSame('blocked', $r['status']);
        $this->assertSame(['dimension'], array_column($r['required_fields'], 'key'));
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->downloadRequests);
    }

    public function test_saved_dimension_rejection_recovers_editable_fields_without_remote_calls_or_state_writes(): void
    {
        $r = $this->start();
        $state = json_decode(DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'), true);
        $state['rejection'] = ['code' => 'product.error_param', 'field' => 'dimension'];
        $state['context']['dimension'] = ['package_length' => 0, 'package_width' => 0, 'package_height' => 0];
        $encoded = json_encode($state);
        DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->update(['status' => 'rejected', 'attempted_at' => now(), 'state' => $encoded]);
        $recovered = $this->getJson($this->base.'/source/10')->assertOk()->json('data');
        $this->assertTrue($recovered['can_retry']);
        $this->assertSame(['dimension'], array_column($recovered['required_fields'], 'key'));
        $this->assertSame($encoded, DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'));
        Http::assertNothingSent();

        DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->update(['status' => 'submitted_unverified']);
        $uncertain = $this->getJson($this->base.'/source/10')->assertOk()->json('data');
        $this->assertFalse($uncertain['can_retry']);
        $this->assertSame([], $uncertain['required_fields']);
        $this->assertSame($encoded, DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'));
        Http::assertNothingSent();
    }

    public function test_saved_weight_rejection_exposes_shipping_corrections_without_remote_reads(): void
    {
        $r = $this->start();
        $state = json_decode(DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'), true);
        $state['rejection'] = ['code' => 'product.error_busi', 'field' => 'weight'];
        $state['context'] = ['weight' => 0.2, 'dimension' => ['package_length' => 100, 'package_width' => 100, 'package_height' => 5], 'logistic_ids' => ['8003']];
        $encoded = json_encode($state);
        DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->update(['status' => 'rejected', 'attempted_at' => now(), 'state' => $encoded]);
        $recovered = $this->getJson($this->base.'/source/10')->assertOk()->json('data');
        $this->assertTrue($recovered['can_retry']);
        $this->assertSame(['weight', 'dimension', 'logistic_ids'], array_column($recovered['required_fields'], 'key'));
        $this->assertSame($state['context'], $recovered['context']);
        $this->assertSame($encoded, DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'));
        Http::assertNothingSent();
        DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->update(['status' => 'submitted_unverified']);
        $this->assertFalse($this->getJson($this->base.'/source/10')->assertOk()->json('data.can_retry'));
        Http::assertNothingSent();
    }

    public function test_shipping_options_are_read_only_and_target_account_scoped(): void
    {
        $this->getJson($this->base.'/shipping-channels')->assertOk()->assertExactJson(['data' => [['id' => '8003', 'name' => 'Regular']]]);
        $this->assertSame([], $this->writes);
        $this->assertSame(0, DB::table('stock_hub_gita_product_runs')->count());
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/api/v2/logistics/get_channel_list') && str_contains($r->url(), 'shop_id=22'));
        $this->mode = 'logistics';
        $this->getJson($this->base.'/shipping-channels')->assertOk()->assertExactJson(['data' => []]);
    }

    public static function legacyDimensionStages(): array
    {
        return [
            'scanning' => ['scanning', true],
            'uploading' => ['uploading', true],
            'submitting' => ['submitting', true],
            'invalid submission payload' => ['submitting', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyDimensionStages')]
    public function test_legacy_staged_zero_dimensions_block_before_any_remote_request(string $stage, bool $invalidContext): void
    {
        $r = $this->start();
        for ($i = 0; $i < 40 && $r['status'] !== $stage; $i++) {
            $r = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data');
        }
        $this->assertSame($stage, $r['status']);
        $state = json_decode(DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'), true);
        $zero = ['package_length' => 0, 'package_width' => 0, 'package_height' => 0];
        if ($invalidContext) { $state['context']['dimension'] = $zero; }
        if ($stage === 'submitting') { $state['payload']['dimension'] = $zero; }
        DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->update(['state' => json_encode($state)]);
        $requests = count(Http::recorded());
        $r = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('blocked', $r['status']);
        $this->assertTrue($r['can_retry']);
        $this->assertSame(['dimension'], array_column($r['required_fields'], 'key'));
        $this->assertSame($requests, count(Http::recorded()));
        $this->assertSame([], $this->writes);
        $this->assertNull(DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('attempted_at'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untrustedParentRejections')]
    public function test_parent_rejection_never_exposes_untrusted_marketplace_text(array $response, ?string $code): void
    {
        $this->parentRejection = $response;
        $r = $this->finish($this->start());
        $this->assertSame('rejected', $r['status']);
        $saved = json_decode(DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'), true);
        $this->assertSame(['code' => $code, 'field' => null], $saved['rejection'] ?? null);
        $this->assertStringNotContainsString('secret', json_encode($saved));
        $this->assertStringNotContainsString('access_token', $r['message']);
        $this->assertCount(1, $this->writes);
    }

    public function test_init_unknown_is_read_back_and_partial_never_replayed(): void
    {
        foreach (['init_unknown', 'init_partial'] as $mode) {
            $this->mode = $mode; $this->writes = []; $this->models = [];
            DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->delete();
            if (Schema::hasTable('stock_hub_gita_product_guards')) { DB::table('stock_hub_gita_product_guards')->delete(); }
            $r = $this->finish($this->start());
            if ($mode === 'init_unknown') { $r = $this->finish($this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data')); }
            $this->assertSame($mode === 'init_unknown' ? 'success' : 'partial_unverified', $r['status']);
            $this->assertFalse($r['can_retry']);
            $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk();
            $this->assertCount($mode === 'init_unknown' ? 3 : 2, $this->writes);
        }
    }

    public function test_publication_requires_exact_lists_and_review_is_separate(): void
    {
        foreach (['publish_missing', 'publish_wrong', 'publish_failure', 'review'] as $mode) {
            $this->mode = $mode; $this->writes = []; $this->models = [];
            DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->delete();
            if (Schema::hasTable('stock_hub_gita_product_guards')) { DB::table('stock_hub_gita_product_guards')->delete(); }
            $r = $this->finish($this->start());
            $this->assertSame($mode === 'review' ? 'under_review' : 'partial_unverified', $r['status']);
            $this->assertFalse($r['result']['published']); $this->assertFalse($r['can_retry']);
            $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk(); $this->assertCount(3, $this->writes);
        }
    }

    public function test_parent_delay_and_per_step_mutation_bound(): void
    {
        $r = $this->start();
        for ($i = 0; $i < 40 && !$r['remote_product_id']; $i++) { $before = count($this->writes); $r = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data'); $this->assertLessThanOrEqual(1, count($this->writes) - $before); }
        $this->assertSame('200', $r['remote_product_id']);
        $this->assertGreaterThan(0, $r['next_step_after_ms']);
        $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk(); $this->assertCount(1, $this->writes);
        $r = $this->finish($r); $this->assertSame('success', $r['status']);
    }

    public function test_model_order_changes_do_not_fake_source_drift(): void
    {
        $this->mode = 'order'; $this->assertSame('success', $this->finish($this->start())['status']);
    }

    public function test_mandatory_brand_accepts_explicit_no_brand_metadata_option(): void
    {
        $this->mode = 'no_brand'; $r = $this->finish($this->start());
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertSame(['brand_id' => 0, 'original_brand_name' => 'No Brand'], $this->writes[0][1]['brand']);
    }

    public function test_unordered_attributes_channels_and_object_keys_do_not_fake_source_drift(): void
    {
        $this->mode = 'canonical'; $r = $this->finish($this->start()); $this->assertSame('success', $r['status'], json_encode($r));
    }

    public function test_two_tiers_preserve_all_four_models(): void
    {
        $this->mode = 'two_tiers'; $r = $this->finish($this->start()); $this->assertSame('success', $r['status']);
        $this->assertCount(4, $r['result']['skus']); $this->assertSame([0,1], $this->writes[1][1]['model'][2]['tier_index']);
    }

    public function test_shopee_accepts_source_skus_longer_than_tiktok_limit(): void
    {
        $this->mode = 'long_sku'; $r = $this->finish($this->start()); $this->assertSame('success', $r['status']);
        $this->assertSame('INT-10-'.str_repeat('X', 80), $r['result']['skus'][0]['seller_sku']);
    }

    public function test_readback_mismatch_never_publishes_or_replays_initialization(): void
    {
        foreach (['readback_stock','readback_gallery'] as $mode) {
            $this->mode = $mode; $this->writes = []; $this->models = [];
            DB::table('stock_hub_gita_product_guards')->delete(); DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->delete();
            $r = $this->finish($this->start()); $this->assertSame('partial_unverified', $r['status']);
            $this->assertFalse($r['can_retry']); $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk();
            $this->assertLessThanOrEqual(2, count($this->writes));
        }
    }

    private function legacyCache(): void
    {
        Schema::create('shopee_product', function (Blueprint $t): void { $t->bigInteger('item_id')->primary(); $t->bigInteger('shop_id')->nullable(); foreach (['name','description','status','currency'] as $c) { $t->text($c)->nullable(); } foreach (['category_id','stock','price_min','price_max','price_before_discount'] as $c) { $t->bigInteger($c)->nullable(); } $t->boolean('is_active')->nullable(); $t->timestamps(); });
        Schema::create('shopee_product_model', function (Blueprint $t): void { $t->string('model_id')->primary(); $t->bigInteger('item_id'); foreach (['name','model_sku'] as $c) { $t->text($c)->nullable(); } foreach (['price','original_price','stock'] as $c) { $t->bigInteger($c)->nullable(); } $t->timestamps(); });
        Schema::create('shopee_product_image', function (Blueprint $t): void { $t->id(); $t->bigInteger('item_id'); $t->string('model_id')->nullable(); $t->text('image_url'); $t->timestamps(); });
        DB::table('shopee_product')->insert(['item_id' => 10, 'shop_id' => 11, 'name' => 'Source cached title', 'stock' => 19]);
        DB::table('shopee_product_model')->insert(['model_id' => '101', 'item_id' => 10, 'model_sku' => 'INT-10-GREEN', 'stock' => 19]);
    }

    public function test_verified_cache_uses_actual_schema_and_never_changes_source_stock(): void
    {
        $this->legacyCache(); $r = $this->finish($this->start());
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertSame(19, DB::table('shopee_product')->where('item_id', 10)->value('stock'));
        $this->assertSame(19, DB::table('shopee_product_model')->where('model_id', '101')->value('stock'));
        $this->assertSame(7, DB::table('shopee_product')->where('item_id', 200)->value('stock'));
        $this->assertSame(0, DB::table('shopee_product_model')->where('model_id', '201')->value('stock'));
        $this->assertSame(3, DB::table('shopee_product_image')->where('item_id', 200)->count());
    }

    public function test_cache_conflicting_shop_cannot_be_overwritten_or_published(): void
    {
        $this->legacyCache(); DB::table('shopee_product')->insert(['item_id' => 200, 'shop_id' => 11, 'name' => 'Other shop product', 'stock' => 99]);
        $r = $this->finish($this->start()); $this->assertSame('partial_unverified', $r['status']);
        $this->assertSame(99, DB::table('shopee_product')->where('item_id', 200)->value('stock')); $this->assertCount(2, $this->writes);
    }

    public function test_catalog_revision_change_after_scan_blocks_parent(): void
    {
        $r = $this->start();
        for ($i = 0; $i < 40 && $r['status'] !== 'submitting'; $i++) { $r = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data'); }
        DB::table('marketplace_catalog_mutation_revision')->where('id', 1)->increment('revision');
        $r = $this->finish($r); $this->assertSame('blocked', $r['status']); $this->assertSame([], $this->writes);
    }

    public function test_attempt_markers_survive_crash_recovery_and_prevent_another_parent(): void
    {
        $r = $this->start();
        DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->update(['attempted_at' => now(), 'status' => 'submitting']);
        $read = $this->getJson($this->base.'/runs/'.$r['run_id'])->assertOk()->json('data');
        $this->assertSame('submitted_unverified', $read['status']); $this->assertFalse($read['can_continue']);
        $this->assertSame($r['run_id'], $this->start()['run_id']);
        $read = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('submitted_unverified', $read['status']); Http::assertNothingSent();
    }

    public function test_claimed_source_step_is_read_only_until_claim_expires(): void
    {
        $r = $this->start(); DB::table('stock_hub_gita_product_guards')->where('run_id', $r['run_id'])->update(['owner' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(5)]);
        $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->assertJsonPath('data.status', 'preparing');
        Http::assertNothingSent();
    }

    public function test_missing_option_image_url_cannot_silently_drop_source_image(): void
    {
        $this->mode = 'option_missing_url'; $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']); $this->assertSame([], $this->writes);
    }

    public function test_missing_required_chart_returns_only_chart_correction(): void
    {
        $this->mode = 'chart'; $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']);
        $this->assertSame(['size_chart_image_url'], array_column($r['required_fields'], 'key'));
        $this->assertSame(['8003'], $r['context']['logistic_ids']);
        $this->assertSame([], $this->writes); $this->assertSame([], $this->downloadRequests);
    }

    public function test_unresolvable_chart_returns_only_chart_correction_and_accepts_explicit_image(): void
    {
        $this->mode = 'chart_bad'; $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']); $this->assertSame(['size_chart_image_url'], array_column($r['required_fields'], 'key'));
        $r = $this->finish($this->start(['size_chart_image_url' => 'https://img.test/fixed-chart.jpg']));
        $this->assertSame('success', $r['status']);
    }

    public function test_private_source_chart_is_a_correction_and_never_downloaded(): void
    {
        $this->mode = 'chart_private'; $r = $this->finish($this->start());
        $this->assertSame(['size_chart_image_url'], array_column($r['required_fields'], 'key'));
        $r = $this->finish($this->start(['size_chart_image_url' => 'https://img.test/fixed-chart.jpg']));
        $this->assertSame('success', $r['status']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'localhost'));
    }

    public static function excludedOptionalContent(): array
    {
        $fields = ['video_info' => [['video_id' => 'source-video']], 'promotion_images' => ['image_id_list' => ['source-promotion']], 'wholesales' => [['min_count' => 10, 'max_count' => 20, 'unit_price' => 125000]]];
        return ['video' => [array_intersect_key($fields, ['video_info' => true])], 'promotion' => [array_intersect_key($fields, ['promotion_images' => true])], 'wholesale' => [array_intersect_key($fields, ['wholesales' => true])], 'combined' => [$fields]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excludedOptionalContent')]
    public function test_optional_excluded_source_content_does_not_block_or_transfer(array $extras): void
    {
        $this->sourceExtras = $extras; $this->legacyCache();
        $source = $this->sourceParent(); $models = $this->sourceModels();
        $cachedParent = DB::table('shopee_product')->where('item_id', 10)->first();
        $cachedModel = DB::table('shopee_product_model')->where('model_id', '101')->first();
        $sourceListing = DB::table('marketplace_listings')->where('account_key', 'shopee-agnishopbjm')->first();
        $r = $this->finish($this->start());
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertSame([], $r['required_fields']); $this->assertCount(3, $this->writes);
        $create = $this->writes[0][1]; $init = $this->writes[1][1];
        $this->assertSame('Full product title', $create['item_name']);
        $this->assertSame('Complete original product description', $create['description']);
        $this->assertSame(['uploaded-1'], $create['image']['image_id_list']);
        $this->assertArrayHasKey('size_chart_info', $create);
        $this->assertSame(['INT-10-GREEN', 'INT-10-BLUE'], array_column($init['model'], 'model_sku'));
        $this->assertSame([150000, 175000], array_column($init['model'], 'original_price'));
        $this->assertSame([0, 7], array_map(fn ($m) => $m['seller_stock'][0]['stock'], $init['model']));
        foreach (['video_info', 'promotion_images', 'wholesales'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'":', json_encode($this->writes));
            $this->assertStringNotContainsString('"'.$field.'":', DB::table('stock_hub_gita_product_runs')->where('id', $r['run_id'])->value('state'));
        }
        $this->assertEquals($cachedParent, DB::table('shopee_product')->where('item_id', 10)->first());
        $this->assertEquals($cachedModel, DB::table('shopee_product_model')->where('model_id', '101')->first());
        $this->assertEquals($sourceListing, DB::table('marketplace_listings')->where('account_key', 'shopee-agnishopbjm')->first());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        $transport = $this->app->make(\App\Services\StockHubGitaProductTransport::class);
        $this->assertSame(['item_list' => [$source]], $transport->read($transport::SOURCE, '/api/v2/product/get_item_base_info', ['item_id_list' => '10']));
        $this->assertSame($models, $transport->read($transport::SOURCE, '/api/v2/product/get_model_list', ['item_id' => '10']));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && str_contains($request->url(), 'shop_id=11'));
    }

    public static function unsupportedRegionalContent(): array
    {
        return ['complaint' => ['complaint_policy'], 'tax' => ['tax_info'], 'certification' => ['certification_info']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedRegionalContent')]
    public function test_unsupported_regional_source_metadata_remains_blocked(string $field): void
    {
        $this->sourceExtras = [$field => ['required' => true]];
        $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']); $this->assertTrue($r['can_retry']);
        $this->assertSame([], $this->writes); $this->assertSame([], $this->downloadRequests);
        $this->assertSame(0, $this->uploads);
    }

    public static function unsafeOperatorCharts(): array
    {
        return ['localhost' => ['https://localhost/chart.jpg'], 'private_ip' => ['https://127.0.0.1/chart.jpg'], 'unresolved_host' => ['https://unresolved.example/chart.jpg']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeOperatorCharts')]
    public function test_rejected_operator_chart_remains_editable_with_complete_context_for_retry(string $url): void
    {
        $resolver = new class extends \App\Services\StockHubGitaImageAddressResolver {
            public function resolve(string $host): array { return $host === 'unresolved.example' ? [] : ['93.184.216.34']; }
        };
        $this->app->instance(\App\Services\StockHubGitaImageAddressResolver::class, $resolver);
        $this->mode = 'warehouse';
        $context = ['category_id' => '100493', 'weight' => 0.7, 'dimension' => ['package_length' => 12, 'package_width' => 21, 'package_height' => 4], 'logistic_ids' => ['8003'], 'location_id' => 'target-b', 'size_chart_image_url' => $url];
        $r = $this->finish($this->start($context));
        $this->assertSame('blocked', $r['status']); $this->assertTrue($r['can_retry']);
        $this->assertSame([['key' => 'size_chart_image_url', 'label' => 'Gambar tabel ukuran', 'type' => 'url']], $r['required_fields']);
        $this->assertSame($context, $r['context']);
        $this->assertSame([], $this->writes); $this->assertSame([], $this->downloadRequests); $this->assertSame(0, $this->uploads);
        $this->assertSame($r, $this->getJson($this->base.'/source/10')->assertOk()->json('data'));
        $context = [...$r['context'], 'size_chart_image_url' => 'https://img.test/fixed-chart.jpg'];
        $retry = $this->finish($this->start($context));
        $this->assertNotSame($r['run_id'], $retry['run_id']);
        $this->assertSame('success', $retry['status'], json_encode($retry)); $this->assertSame($context, $retry['context']);
        $this->assertSame('target-b', $this->writes[0][1]['seller_stock'][0]['location_id']);
        $this->assertSame(0.7, $this->writes[0][1]['weight']); $this->assertSame($context['dimension'], $this->writes[0][1]['dimension']);
        $this->assertArrayHasKey('size_chart_info', $this->writes[0][1]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), parse_url($url, PHP_URL_HOST)) || in_array(parse_url($url, PHP_URL_HOST), $request->header('Host'), true));
    }

    public function test_image_transient_transfer_retries_are_bounded_and_permanent_failures_stop(): void
    {
        $this->transferFailures = ['connection',429]; $r = $this->finish($this->start());
        $this->assertSame('success', $r['status']); $this->assertSame(2, $this->transferAttempts); $this->assertCount(3, $this->writes);
    }

    public function test_image_permanent_or_malformed_upload_failure_never_creates_parent(): void
    {
        foreach (['image','upload_missing'] as $mode) {
            $this->mode = $mode; $this->uploads = 0; $r = $this->finish($this->start());
            $this->assertSame('blocked', $r['status']); $this->assertSame([], $this->writes);
            $this->assertSame(0, $r['progress']['uploaded_images']);
        }
    }

    public function test_shared_catalog_lease_refusal_blocks_before_remote_reads(): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('stock-catalog-mutation', 900); $this->assertTrue($lock->get());
        try { $r = $this->finish($this->start()); $this->assertSame('blocked', $r['status']); Http::assertNothingSent(); }
        finally { $lock->release(); }
    }

    public function test_database_only_snapshots_never_expose_payload_images_or_attempt_evidence(): void
    {
        $r = $this->finish($this->start()); Http::fake();
        $read = $this->getJson($this->base.'/runs/'.$r['run_id'])->assertOk()->json('data');
        foreach (['payload','images','source','identities','attempted_at','variants_attempted_at','publication_attempted_at','scan_attempts'] as $key) { $this->assertArrayNotHasKey($key, $read); }
        Http::assertNothingSent();
    }

    public function test_template_chart_is_never_silently_discarded_even_if_optional(): void
    {
        $this->mode = 'template'; $r = $this->finish($this->start());
        $this->assertSame('blocked', $r['status']); $this->assertSame(['size_chart_image_url'], array_column($r['required_fields'], 'key'));
        $this->assertSame([], $this->writes);
    }

    public function test_explicit_sku_duplicate_stops_create_and_does_not_attach_mapping(): void
    {
        $this->mode = 'duplicate'; $r = $this->finish($this->start());
        $this->assertSame('exists', $r['status']); $this->assertSame('90', $r['result']['product_id']);
        $this->assertSame([], $this->writes); $this->assertSame(0, DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->count());
    }

    public function test_account_change_after_parent_acceptance_blocks_known_id_recovery(): void
    {
        $r = $this->start();
        for ($i = 0; $i < 40 && !$r['remote_product_id']; $i++) { $r = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data'); }
        DB::table('shopee_tokens')->where('account_key', 'shopee-gitacollectionbjm')->update(['shop_id' => 33]);
        $r = $this->postJson($this->base.'/runs/'.$r['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('partial_unverified', $r['status']); $this->assertSame('200', $r['remote_product_id']); $this->assertCount(1, $this->writes);
        $this->assertSame($r['run_id'], $this->start()['run_id']);
    }

    public function test_upload_list_acceptance_preserves_each_image_identity(): void
    {
        $this->mode = 'upload_list'; $r = $this->finish($this->start());
        $this->assertSame('success', $r['status']); $this->assertSame(4, $this->uploads);
        $this->assertSame(['uploaded-1'], $this->writes[0][1]['image']['image_id_list']);
    }

    public function test_each_catalog_mutation_advances_shared_revision(): void
    {
        $this->finish($this->start()); $this->assertSame(3, DB::table('marketplace_catalog_mutation_revision')->where('id', 1)->value('revision'));
    }

    public function test_nondefault_model_shipping_is_preserved_or_blocked_before_parent(): void
    {
        foreach (['model_dts','model_bad_weight'] as $mode) {
            $this->mode = $mode; $r = $this->finish($this->start()); $this->assertSame('blocked', $r['status']); $this->assertSame([], $this->writes);
        }
    }

    public function test_valid_model_overrides_are_sent_and_verified(): void
    {
        $this->mode = 'model_overrides'; $r = $this->finish($this->start()); $this->assertSame('success', $r['status']);
        $m = $this->writes[1][1]['model'][0];
        $this->assertSame(0.7, $m['weight']); $this->assertSame(12, $m['dimension']['package_length']);
        $this->assertSame(['days_to_ship' => 4, 'is_pre_order' => true], $m['pre_order']); $this->assertSame('00', $m['gtin_code']);
    }

    public function test_unknown_input_keys_are_rejected_without_remote_work(): void
    {
        foreach (['account_key' => 'x', 'remote_product_id' => '200', 'unknown' => true] as $key => $value) {
            $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => (string) Str::uuid(), $key => $value])->assertStatus(422);
            $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => (string) Str::uuid(), 'context' => [$key => $value]])->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_variantless_sentinel_never_creates_or_conflicts_with_a_real_model_cache_row(): void
    {
        $this->legacyCache();
        DB::table('shopee_product_model')->insert(['model_id' => '0', 'item_id' => 10, 'model_sku' => 'Existing sentinel', 'stock' => 99]);
        DB::table('marketplace_listings')->where('account_key', 'shopee-agnishopbjm')->update(['remote_variant_id' => '0', 'seller_sku' => 'INT-10']);
        $this->mode = 'variantless'; $r = $this->finish($this->start());
        $this->assertSame('success', $r['status'], json_encode($r));
        $this->assertSame('0', $r['result']['skus'][0]['id']);
        $this->assertSame(0, DB::table('shopee_product_model')->where('item_id', 200)->count());
        $this->assertSame(99, DB::table('shopee_product_model')->where('model_id', '0')->value('stock'));
        $this->assertSame('0', DB::table('marketplace_listings')->where('account_key', 'shopee-gitacollectionbjm')->value('remote_variant_id'));
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_bracketed_private_ipv6_urls_never_reach_image_downloads(): void
    {
        $this->assertImageDownloadRefused('https://[::1]/private.jpg');
        $this->assertImageDownloadRefused('https://[fc00::1]/private.jpg');
        $this->assertImageDownloadRefused('https://[fe80::1]/private.jpg');
        $this->assertImageDownloadRefused('https://[::ffff:127.0.0.1]/private.jpg');
    }

    public function test_private_dns_addresses_never_reach_image_downloads(): void
    {
        foreach (['127.0.0.1','10.0.0.1','100.127.0.1','169.254.0.1','192.0.2.1','198.18.0.1','203.0.113.1','224.0.0.1','::1','2001:db8::1','2002:7f00:1::1'] as $private) {
            $this->bindImageResolver(['93.184.216.34', $private]);
            $this->assertImageDownloadRefused('https://private.example/private.jpg');
        }
    }

    public function test_public_image_downloads_pin_checked_addresses_and_disable_proxy_and_redirects(): void
    {
        $this->bindImageResolver(['93.184.216.34']);
        $r = $this->finish($this->start()); $this->assertSame('success', $r['status']);
        $this->assertCount(4, $this->downloadOptions);
        foreach ($this->downloadOptions as $options) {
            $this->assertSame('img.test', $options['stream_context']['ssl']['peer_name'] ?? null);
            $this->assertTrue($options['stream_context']['ssl']['verify_peer'] ?? false);
            $this->assertTrue($options['stream_context']['ssl']['verify_peer_name'] ?? false);
            $this->assertSame('', $options['proxy'] ?? null); $this->assertFalse($options['allow_redirects']);
        }
        foreach ($this->downloadRequests as $request) { $this->assertSame('93.184.216.34', parse_url($request->url(), PHP_URL_HOST)); $this->assertSame(['img.test'], $request->header('Host')); }
    }

    public function test_pinned_download_retains_original_port_path_query_host_and_tls_peer(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Http::fake(function ($request, array $options) {
            $this->assertSame('https://93.184.216.34/full/image.png?quality=original', $request->url());
            $this->assertSame(443, parse_url($request->url(), PHP_URL_PORT) ?? 443);
            $this->assertSame(['media.example:443'], $request->header('Host'));
            $this->assertSame('media.example', $options['stream_context']['ssl']['peer_name']);
            $this->assertTrue($options['stream_context']['ssl']['SNI_enabled']);
            return Http::response('png-bytes', 200, ['Content-Type' => 'image/png']);
        });
        $asset = $this->app->make(\App\Services\StockHubGitaProductTransport::class)->download('https://media.example:443/full/image.png?quality=original');
        $this->assertSame(['bytes' => 'png-bytes', 'mime' => 'image/png'], $asset);
    }

    public function test_download_retries_retain_pinned_address_without_another_dns_resolution(): void
    {
        $resolver = new class extends \App\Services\StockHubGitaImageAddressResolver {
            private int $calls = 0;
            public function resolve(string $host): array { return ++$this->calls === 1 ? ['93.184.216.34'] : ['127.0.0.1']; }
        };
        $this->app->instance(\App\Services\StockHubGitaImageAddressResolver::class, $resolver);
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests(); $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $this->assertSame('93.184.216.34', parse_url($request->url(), PHP_URL_HOST));
            $this->assertSame(['media.example'], $request->header('Host'));
            return ++$attempts < 3 ? Http::response('transient', 503) : Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']);
        });
        $asset = $this->app->make(\App\Services\StockHubGitaProductTransport::class)->download('https://media.example/image.jpg');
        $this->assertSame('image-bytes', $asset['bytes']); Http::assertSentCount(3);
    }

    private function bindImageResolver(array $addresses): void
    {
        $resolver = new class($addresses) extends \App\Services\StockHubGitaImageAddressResolver {
            public function __construct(private array $addresses) {}
            public function resolve(string $host): array { return $this->addresses; }
        };
        $this->app->instance(\App\Services\StockHubGitaImageAddressResolver::class, $resolver);
    }

    private function assertImageDownloadRefused(string $url): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($r) => $r->method() === 'GET' ? Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']) : Http::response(['error' => '', 'response' => ['image_info' => ['image_id' => 'unsafe-image']]]));
        $transport = $this->app->make(\App\Services\StockHubGitaProductTransport::class);
        $refused = false;
        try { $transport->upload(['url' => $url, 'scene' => 'desc'], $transport->identities()); } catch (\DomainException) { $refused = true; }
        $this->assertTrue($refused, 'Private image address must be refused.');
        Http::assertNothingSent();
    }
}
