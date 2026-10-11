<?php

namespace Tests\Feature;

use App\Services\StockHubTiktokProductRejected;
use App\Services\StockHubTiktokVariantGateway;
use App\Services\StockHubTiktokVariantPayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockHubTiktokVariantGatewayTest extends TestCase
{
    use RefreshDatabase;

    private array $live;
    private array $review;
    private array $models;
    private array $tiers;
    private array $editResponse = ['code' => 0, 'data' => []];
    private int $editHttp = 200;
    private bool $connectionFailure = false;
    private int $edits = 0;
    private array $submitted = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['marketplace_accounts.accounts.shopee-agnishopbjm' => ['enabled' => true, 'channel' => 'shopee', 'credentials' => ['partner_id' => 123, 'partner_key' => 'test-secret', 'host' => 'https://shop.test', 'redirect_url' => 'https://local.test']], 'marketplace_accounts.accounts.tiktok-agnishopbjm' => ['enabled' => true, 'channel' => 'tiktok', 'credentials' => ['app_key' => 'app', 'app_secret' => 'secret', 'api_host' => 'https://tt.test', 'auth_host' => 'https://tt.test', 'redirect_url' => 'https://local.test', 'warehouse_id' => 'wh']]]);
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-agnishopbjm', 'shop_id' => 11, 'access_token' => 'source-token', 'is_active' => true, 'access_token_expire_at' => now()->addHour()]);
        Schema::create('tiktok_tokens', function (Blueprint $t) { $t->id(); $t->string('account_key'); $t->string('shop_id'); $t->string('access_token'); $t->boolean('is_active'); $t->timestamp('access_token_expire_at'); });
        DB::table('tiktok_tokens')->insert(['account_key' => 'tiktok-agnishopbjm', 'shop_id' => '33', 'access_token' => 'target-token', 'is_active' => true, 'access_token_expire_at' => now()->addHour()]);
        Schema::create('tiktok_shops', function (Blueprint $t) { $t->id(); $t->string('shop_id'); $t->string('cipher'); });
        DB::table('tiktok_shops')->insert(['shop_id' => '33', 'cipher' => 'target-cipher']);
        Schema::create('stock_master', function (Blueprint $t) { $t->id(); $t->integer('stock'); });
        DB::table('stock_master')->insert(['stock' => 19]);
        Schema::create('tiktok_products', function (Blueprint $t) {
            $t->id(); foreach (['account_key','product_id','sku_id','product_name','sku_name','seller_sku','image_url','warehouse_id','product_status','audit_status'] as $c) { $t->string($c)->nullable(); }
            foreach (['stock_qty','price','subtotal'] as $c) { $t->integer($c)->nullable(); } $t->boolean('is_active')->default(true); $t->timestamps();
        });
        $this->live = ['id' => '90', 'shop_id' => '33', 'title' => 'Colors', 'status' => 'ACTIVATE', 'audit' => ['status' => 'APPROVED'], 'package_weight' => ['value' => '100', 'unit' => 'GRAM'], 'package_dimensions' => ['length' => '10', 'width' => '20', 'height' => '3', 'unit' => 'CENTIMETER'], 'skus' => [$this->sku('901', 'RED', 'Red', 7, '25000')]];
        $this->review = $this->live;
        $this->models = [];
        foreach ([['101','RED','Red',6], ['102','BLUE','Blue',0], ['103','GREEN','Green',4]] as $i => [$id,$sku,$name,$stock]) {
            $this->models[] = ['model_id' => $id, 'model_sku' => 'INT-10-'.$sku, 'model_name' => $name, 'tier_index' => [$i], 'price_info' => [['currency' => 'IDR', 'current_price' => 9000]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => $stock]]];
        }
        $this->tiers = [['name' => 'Color', 'option_list' => [['option' => 'Red'], ['option' => 'Blue', 'image' => ['image_url' => 'https://img.test/blue.jpg']], ['option' => 'Green']]]];
        Http::fake(function ($r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            parse_str(parse_url($r->url(), PHP_URL_QUERY) ?? '', $q);
            if ($path === '/api/v2/product/get_item_base_info') { return Http::response(['error' => '', 'response' => ['item_list' => [['item_id' => 10, 'shop_id' => 11, 'item_status' => 'NORMAL', 'item_name' => 'Colors', 'description' => 'Original', 'image' => ['image_url_list' => ['https://img.test/main.jpg']]]]]]); }
            if ($path === '/api/v2/product/get_model_list') { return Http::response(['error' => '', 'response' => ['model' => $this->models, 'tier_variation' => $this->tiers]]); }
            if ($path === '/product/202309/products/90') { return Http::response(['code' => 0, 'data' => isset($q['return_under_review_version']) ? $this->review : $this->live]); }
            if ($path === '/product/202509/products/90/partial_edit') {
                $this->edits++; $this->submitted = $r->data();
                if ($this->connectionFailure) { return Http::failedConnection('private signed URL'); }
                return Http::response(json_encode($this->editResponse, JSON_PRESERVE_ZERO_FRACTION), $this->editHttp);
            }
            if ($path === '/product/202309/images/upload') {
                $this->assertSame('ATTRIBUTE_IMAGE', array_column($r->data(), 'contents', 'name')['use_case']);
                return Http::response(['code' => 0, 'data' => ['uri' => 'uploaded-uri']]);
            }
            if (str_starts_with($r->url(), 'https://img.test/')) { return Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']); }
            throw new \RuntimeException('Unexpected fake path '.$path);
        });
    }

    private function sku(string $id, string $code, string $value, int $stock, string $price): array
    {
        return ['id' => $id, 'seller_sku' => 'INT-10-'.$code, 'price' => ['currency' => 'IDR', 'sale_price' => $price], 'inventory' => [['warehouse_id' => 'wh', 'quantity' => $stock]], 'sales_attributes' => [['id' => '100', 'name' => 'Color', 'value_id' => '200'.$id, 'value_name' => $value, 'sku_img' => ['uri' => 'retained-uri']]], 'pre_sale' => ['type' => 'NONE']];
    }

    public function test_one_combined_edit_preserves_red_and_adds_blue_zero_and_green_fresh_stock(): void
    {
        $gateway = app(StockHubTiktokVariantGateway::class);
        $source = $gateway->source('10');
        $target = $gateway->target('90');
        $prepared = app(StockHubTiktokVariantPayload::class)->prepare($source, $target, null);
        $this->assertSame(25000, $prepared['price']);
        $this->assertSame(['INT-10-BLUE','INT-10-GREEN'], array_column($prepared['variants'], 'seller_sku'));
        $this->assertSame(['https://img.test/blue.jpg','https://img.test/main.jpg'], array_column($prepared['variants'], 'image_url'));
        foreach ($prepared['variants'] as &$variant) { $variant['uri'] = $gateway->upload($variant); } unset($variant);
        $body = app(StockHubTiktokVariantPayload::class)->build($target, $prepared);
        $send = $gateway->prepareEdit('90', '33');
        // Credentials frozen before a durable caller attempt marker; closure must not resolve changed credentials.
        DB::table('tiktok_tokens')->update(['access_token' => 'changed-token']);
        $send($body);
        $this->assertSame(1, $this->edits);
        $this->assertSame('LISTING', $this->submitted['save_mode']);
        $this->assertSame('901', $this->submitted['skus'][0]['id']);
        $this->assertSame(['warehouse_id' => 'wh', 'quantity' => 7], $this->submitted['skus'][0]['inventory'][0]);
        $this->assertSame('25000', $this->submitted['skus'][0]['price']['sale_price']);
        $this->assertEquals($target['skus'][0]['sales_attributes'], $this->submitted['skus'][0]['sales_attributes']);
        $this->assertSame([0,4], array_map(fn ($s) => $s['inventory'][0]['quantity'], array_slice($this->submitted['skus'], 1)));
        $this->assertSame(['amount' => '25000', 'currency' => 'IDR'], $this->submitted['skus'][1]['price']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'partial_edit') && $r->header('x-tts-access-token') === ['target-token']);
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_price_tie_requires_manual_price(): void
    {
        $this->live['skus'][] = $this->sku('904', 'WHITE', 'White', 9, '30000'); $this->review = $this->live;
        $g = app(StockHubTiktokVariantGateway::class); $p = app(StockHubTiktokVariantPayload::class);
        $prepared = $p->prepare($g->source('10'), $g->target('90'), null);
        $this->assertNull($prepared['price']);
        $this->assertSame('manual_price', $prepared['required_fields'][0]['key']);
        $this->assertSame(27000, $p->prepare($g->source('10'), $g->target('90'), 27000)['price']);
    }

    public function test_pending_edit_blocks_preparation_even_when_live_is_active(): void
    {
        $this->review['audit']['status'] = 'AUDITING';
        $this->expectException(\DomainException::class);
        app(StockHubTiktokVariantGateway::class)->target('90');
    }

    public function test_conflicting_live_review_contract_blocks_preparation(): void
    {
        $this->review['skus'][0]['inventory'][0]['quantity'] = 8;
        $this->expectException(\DomainException::class);
        app(StockHubTiktokVariantGateway::class)->target('90');
    }

    public function test_duplicate_value_with_different_source_sku_blocks_before_upload(): void
    {
        $this->tiers[0]['option_list'][1]['option'] = 'Red';
        $this->expectException(\DomainException::class);
        $g = app(StockHubTiktokVariantGateway::class);
        app(StockHubTiktokVariantPayload::class)->prepare($g->source('10'), $g->target('90'), null);
    }

    public function test_incomplete_existing_inventory_blocks_before_upload(): void
    {
        unset($this->live['skus'][0]['inventory']); $this->review = $this->live;
        $this->expectException(\DomainException::class);
        $g = app(StockHubTiktokVariantGateway::class);
        app(StockHubTiktokVariantPayload::class)->prepare($g->source('10'), $g->target('90'), null);
    }

    public function test_explicit_rejection_is_typed_and_never_retried(): void
    {
        $this->editResponse = ['code' => 120001, 'message' => 'private remote text']; $this->editHttp = 400;
        try { (app(StockHubTiktokVariantGateway::class)->prepareEdit('90', '33'))(['skus' => []]); $this->fail('Expected rejection'); }
        catch (StockHubTiktokProductRejected $e) { $this->assertStringNotContainsString('private', $e->getMessage()); }
        $this->assertSame(1, $this->edits);
    }

    public function test_connection_failure_remains_uncertain_and_never_retried(): void
    {
        $this->connectionFailure = true;
        try { (app(StockHubTiktokVariantGateway::class)->prepareEdit('90', '33'))(['skus' => []]); $this->fail('Expected uncertain outcome'); }
        catch (\RuntimeException $e) { $this->assertNotInstanceOf(StockHubTiktokProductRejected::class, $e); $this->assertStringNotContainsString('private', $e->getMessage()); }
        $this->assertSame(1, $this->edits);
    }

    private function expectedEdit(): array
    {
        $g = app(StockHubTiktokVariantGateway::class); $p = app(StockHubTiktokVariantPayload::class);
        $source = $g->source('10'); $target = $g->target('90'); $prepared = $p->prepare($source, $target, null);
        foreach ($prepared['variants'] as &$v) { $v['uri'] = 'uploaded-uri'; } unset($v);
        return [$source, [...$prepared, 'payload' => $p->build($target, $prepared)]];
    }

    private function readbackAdditions(): void
    {
        $this->review['skus'][] = $this->sku('902', 'BLUE', 'Blue', 0, '25000');
        $this->review['skus'][] = $this->sku('903', 'GREEN', 'Green', 4, '25000');
    }

    public function test_pending_readback_verifies_all_additions_and_caches_only_account_scoped_additions(): void
    {
        [$source, $expected] = $this->expectedEdit();
        DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'channel' => 'shopee', 'remote_product_id' => '10', 'remote_variant_id' => '102', 'remote_identity_hash' => hash('sha256', 'source-blue'), 'seller_sku' => 'INT-10-BLUE']);
        DB::table('tiktok_products')->insert(['account_key' => 'other-account', 'product_id' => '90', 'sku_id' => '902', 'seller_sku' => 'OTHER', 'stock_qty' => 77]);
        $this->readbackAdditions(); $this->review['audit']['status'] = 'AUDITING';
        $result = app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90');
        $this->assertFalse($result['published']);
        $this->assertSame(['under_review','under_review'], array_column($result['variants'], 'status'));
        $this->assertSame(['102','103'], array_column($result['variants'], 'source_variant_id'));
        $this->assertSame(['INT-10-BLUE','INT-10-GREEN'], array_column($result['variants'], 'seller_sku'));
        $this->assertSame(2, DB::table('tiktok_products')->where('account_key', 'tiktok-agnishopbjm')->count());
        $this->assertSame(77, DB::table('tiktok_products')->where('account_key', 'other-account')->value('stock_qty'));
        $this->assertSame(0, DB::table('tiktok_products')->where('account_key', 'tiktok-agnishopbjm')->where('sku_id', '902')->value('stock_qty'));
        $this->assertSame(0, DB::table('tiktok_products')->where('account_key', 'tiktok-agnishopbjm')->where('sku_id', '901')->count());
        $this->assertSame('902', DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->value('remote_variant_id'));
        $this->assertSame(1, DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->count());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        $this->assertSame(0, $this->edits);
    }

    public function test_missing_readback_attribute_blocks_all_local_updates(): void
    {
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        $this->review['skus'][2]['sales_attributes'][0]['value_name'] = 'Wrong Green';
        try { app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90'); $this->fail('Expected incomplete verification'); }
        catch (\DomainException) { $this->assertSame(0, DB::table('tiktok_products')->count()); }
    }

    public function test_replaced_existing_sku_id_blocks_verification(): void
    {
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        $this->review['skus'][0]['id'] = '999';
        $this->expectException(\DomainException::class);
        app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90');
    }

    public function test_two_tier_source_maps_unique_names_without_flattening(): void
    {
        foreach ($this->models as &$model) { $model['tier_index'][] = 0; } unset($model);
        $this->tiers[] = ['name' => 'Size', 'option_list' => [['option' => 'M']]];
        $this->live['skus'][0]['sales_attributes'][] = ['id' => '101', 'name' => 'Size', 'value_id' => '300', 'value_name' => 'M']; $this->review = $this->live;
        $g = app(StockHubTiktokVariantGateway::class); $p = app(StockHubTiktokVariantPayload::class);
        $prepared = $p->prepare($g->source('10'), $g->target('90'), null);
        foreach ($prepared['variants'] as &$v) { $v['uri'] = 'uploaded-uri'; } unset($v);
        $body = $p->build($g->target('90'), $prepared);
        $this->assertSame(['Blue','M'], array_column($body['skus'][1]['sales_attributes'], 'value_name'));
        $this->assertSame(['100','101'], array_column($body['skus'][1]['sales_attributes'], 'id'));
    }

    public function test_ambiguous_two_tier_mapping_fails_explicitly(): void
    {
        foreach ($this->models as &$model) { $model['tier_index'][] = 0; } unset($model);
        $this->tiers[] = ['name' => 'Size', 'option_list' => [['option' => 'M']]];
        $this->live['skus'][0]['sales_attributes'][] = ['id' => '101', 'name' => 'Style', 'value_name' => 'M']; $this->review = $this->live;
        $g = app(StockHubTiktokVariantGateway::class);
        $this->expectException(\DomainException::class);
        app(StockHubTiktokVariantPayload::class)->prepare($g->source('10'), $g->target('90'), null);
    }

    public function test_unknown_server_error_is_not_safe_rejection(): void
    {
        $this->editResponse = ['code' => 120001]; $this->editHttp = 500;
        try { (app(StockHubTiktokVariantGateway::class)->prepareEdit('90', '33'))(['skus' => []]); $this->fail('Expected uncertain outcome'); }
        catch (\RuntimeException $e) { $this->assertNotInstanceOf(StockHubTiktokProductRejected::class, $e); }
        $this->assertSame(1, $this->edits);
    }

    public static function uncertainEditResponses(): array
    {
        return [
            'boolean code' => [['code' => true], 400],
            'float code' => [['code' => 120001.0], 400],
            'contradictory sku evidence' => [['code' => 120001, 'data' => ['skus' => [['id' => '902']]]], 400],
            'contradictory product evidence' => [['code' => 120001, 'data' => ['product_id' => '90']], 400],
            'malformed acceptance' => [['code' => '0.0', 'data' => []], 200],
            'redirect' => [['code' => 120001], 302],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uncertainEditResponses')]
    public function test_malformed_or_contradictory_response_stays_uncertain(array $response, int $http): void
    {
        $this->editResponse = $response; $this->editHttp = $http;
        try { (app(StockHubTiktokVariantGateway::class)->prepareEdit('90', '33'))(['skus' => []]); $this->fail('Expected uncertain outcome'); }
        catch (\RuntimeException $e) { $this->assertNotInstanceOf(StockHubTiktokProductRejected::class, $e); }
        $this->assertSame(1, $this->edits);
    }

    public function test_missing_or_invalid_package_contract_blocks_before_upload(): void
    {
        $this->live['package_weight']['value'] = '-1'; $this->review = $this->live;
        $this->expectException(\DomainException::class);
        app(StockHubTiktokVariantGateway::class)->target('90');
    }

    public function test_equal_zero_packages_can_be_read_for_template_repair(): void
    {
        $this->live['package_dimensions']['length'] = '0'; $this->review = $this->live;
        $this->assertSame('0', app(StockHubTiktokVariantGateway::class)->target('90')['package_dimensions']['length']);
        $this->assertSame(0, $this->edits);
    }

    public function test_unitless_zero_only_dimensions_can_reach_shared_template_repair(): void
    {
        $this->live['package_dimensions'] = ['length' => '0', 'width' => '0', 'height' => '0'];
        $this->review = $this->live;
        $target = app(StockHubTiktokVariantGateway::class)->target('90');
        $p = app(\App\Services\StockHubTiktokPackageTemplate::class);
        $r = $p->apply($target, [], $p->snapshot());
        $this->assertSame('CENTIMETER', $r['target']['package_dimensions']['unit']);
        $this->assertSame('25', $r['target']['package_dimensions']['length']);
        $this->assertSame(0, $this->edits);
    }

    public function test_unitless_positive_measurement_is_never_assumed_to_be_centimeters(): void
    {
        unset($this->live['package_dimensions']['unit']); $this->review = $this->live;
        $this->expectException(\DomainException::class);
        app(StockHubTiktokVariantGateway::class)->target('90');
    }

    public function test_json_decimal_package_numbers_are_usable_without_relaxing_price_validation(): void
    {
        $this->live['package_weight'] = ['value' => 0.2, 'unit' => 'KILOGRAM'];
        $this->live['package_dimensions']['height'] = 3.5; $this->review = $this->live;
        [$source, $expected] = $this->expectedEdit();
        $this->assertSame('0.2', $expected['payload']['skus'][0]['sku_weight']['value']);
        $this->assertSame('3.5', $expected['payload']['skus'][0]['sku_dimensions']['height']);
        $this->readbackAdditions();
        $result = app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90');
        $this->assertTrue($result['published']);
    }

    public function test_live_review_zero_and_positive_packages_are_not_hidden_by_defaults(): void
    {
        $this->live['package_dimensions']['length'] = '0';
        $this->expectException(\DomainException::class);
        app(StockHubTiktokVariantGateway::class)->target('90');
    }

    public function test_wrong_readback_package_prevents_cache_updates(): void
    {
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        $this->review['skus'][2]['sku_dimensions'] = ['length' => '99', 'width' => '20', 'height' => '3', 'unit' => 'CENTIMETER'];
        try { app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90'); $this->fail('Expected package mismatch'); }
        catch (\DomainException) { $this->assertSame(0, DB::table('tiktok_products')->count()); }
    }

    public function test_stock_drift_has_specific_diagnostic_and_never_caches_unverified_results(): void
    {
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        $this->review['skus'][0]['inventory'][0]['quantity'] = 2;
        try { app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90'); $this->fail('Expected stock drift'); }
        catch (\DomainException $e) {
            $this->assertSame(\App\Services\StockHubTiktokVariantStockMismatch::class, get_class($e));
            $this->assertSame(0, DB::table('tiktok_products')->count());
            $this->assertSame(0, $this->edits);
        }
    }

    public function test_repaired_product_dimensions_must_be_observed_even_when_sku_dimensions_match(): void
    {
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        $expected['payload']['package_dimensions'] = ['length' => '25', 'width' => '25', 'height' => '10', 'unit' => 'CENTIMETER'];
        // SKU values remain correct; product-level repair was not observed.
        try { app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90'); $this->fail('Expected product package mismatch'); }
        catch (\DomainException) { $this->assertSame(0, DB::table('tiktok_products')->count()); }
    }

    public function test_actual_zero_measurements_are_never_repaired_during_readback(): void
    {
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        $this->review['package_dimensions']['height'] = '0';
        try { app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90'); $this->fail('Expected unverified zero measurement'); }
        catch (\DomainException) { $this->assertSame(0, DB::table('tiktok_products')->count()); }
    }

    public function test_product_zero_readback_blocks_even_with_positive_explicit_sku_packages(): void
    {
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        foreach ($this->review['skus'] as &$sku) {
            $sku['sku_weight'] = ['value' => '100', 'unit' => 'GRAM'];
            $sku['sku_dimensions'] = ['length' => '10', 'width' => '20', 'height' => '3', 'unit' => 'CENTIMETER'];
        } unset($sku);
        $this->review['package_dimensions']['height'] = '0';
        try { app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90'); $this->fail('Expected product zero to remain unverified'); }
        catch (\DomainException) { $this->assertSame(0, DB::table('tiktok_products')->count()); }
    }

    public function test_no_usable_integer_majority_requires_manual_price_and_preserves_fractional_retained_price(): void
    {
        $this->live['skus'][0]['price']['sale_price'] = '25000.5'; $this->review = $this->live;
        $g = app(StockHubTiktokVariantGateway::class); $p = app(StockHubTiktokVariantPayload::class);
        $prepared = $p->prepare($g->source('10'), $g->target('90'), null);
        $this->assertNull($prepared['price']);
        $this->assertSame('manual_price', $prepared['required_fields'][0]['key']);
        $this->assertSame('25000.5', $p->retained($g->target('90'))[0]['price']['sale_price']);
    }

    public function test_price_unique_mode_uses_existing_target_idr_not_source_price(): void
    {
        $this->live['skus'][] = $this->sku('904', 'WHITE', 'White', 9, '30000');
        $this->live['skus'][] = $this->sku('905', 'BLACK', 'Black', 9, '25000'); $this->review = $this->live;
        $g = app(StockHubTiktokVariantGateway::class);
        $prepared = app(StockHubTiktokVariantPayload::class)->prepare($g->source('10'), $g->target('90'), null);
        $this->assertSame(25000, $prepared['price']);
    }

    public function test_fractional_majority_requires_manual_price_instead_of_integer_minority(): void
    {
        $this->live['skus'][0]['price']['sale_price'] = '25000.5';
        $this->live['skus'][] = $this->sku('904', 'WHITE', 'White', 9, '25000.50');
        $this->live['skus'][] = $this->sku('905', 'BLACK', 'Black', 9, '100000');
        $this->review = $this->live;
        $g = app(StockHubTiktokVariantGateway::class); $p = app(StockHubTiktokVariantPayload::class);
        $source = $g->source('10'); $target = $g->target('90');
        $prepared = $p->prepare($source, $target, null);
        $this->assertNull($prepared['price']);
        $this->assertSame('manual_price', $prepared['required_fields'][0]['key']);
        $prepared = $p->prepare($source, $target, 27000);
        $this->assertSame(27000, $prepared['price']);
        foreach ($prepared['variants'] as &$variant) { $variant['uri'] = 'uploaded-uri'; } unset($variant);
        $body = $p->build($target, $prepared);
        $this->assertSame(['25000.5','25000.5','100000'], array_column(array_column(array_slice($body['skus'], 0, 3), 'price'), 'sale_price'));
        $this->assertSame(['amount' => '27000', 'currency' => 'IDR'], $body['skus'][3]['price']);
    }

    public function test_legacy_fixed_primary_cache_receives_verified_additions_without_overwriting_conflicting_identity(): void
    {
        Schema::table('tiktok_products', fn (Blueprint $t) => $t->dropColumn('account_key'));
        DB::table('tiktok_products')->insert(['product_id' => '90', 'sku_id' => '902', 'seller_sku' => 'CONFLICT', 'stock_qty' => 77]);
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        $result = app(StockHubTiktokVariantGateway::class)->verify($source, $expected, '90');
        $this->assertTrue($result['published']);
        $this->assertSame(['updated','updated'], array_column($result['variants'], 'status'));
        $this->assertSame(2, DB::table('tiktok_products')->count());
        $this->assertSame(77, DB::table('tiktok_products')->where('sku_id', '902')->value('stock_qty'));
        $this->assertSame('INT-10-GREEN', DB::table('tiktok_products')->where('sku_id', '903')->value('seller_sku'));
        $this->assertSame(4, DB::table('tiktok_products')->where('sku_id', '903')->value('stock_qty'));
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_verified_pending_mapping_can_activate_only_its_exact_owned_identity(): void
    {
        [$source, $expected] = $this->expectedEdit(); $this->readbackAdditions();
        DB::table('marketplace_listings')->insert(['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'channel' => 'shopee', 'remote_product_id' => '10', 'remote_variant_id' => '102', 'remote_identity_hash' => hash('sha256', 'source-blue'), 'seller_sku' => 'INT-10-BLUE']);
        $this->review['audit']['status'] = 'AUDITING';
        $g = app(StockHubTiktokVariantGateway::class);
        $g->verify($source, $expected, '90');
        $this->assertSame(0, DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->value('is_active'));
        $this->review['audit']['status'] = 'APPROVED';
        $g->verify($source, $expected, '90');
        $this->assertSame(1, DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->value('is_active'));
        $this->assertSame(1, DB::table('marketplace_listings')->where('account_key', 'tiktok-agnishopbjm')->count());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_two_tier_images_follow_the_mapped_attribute_when_target_order_differs(): void
    {
        foreach ($this->models as &$model) { $model['tier_index'][] = 0; } unset($model);
        $this->tiers[] = ['name' => 'Size', 'option_list' => [['option' => 'M']]];
        array_unshift($this->live['skus'][0]['sales_attributes'], ['id' => '101', 'name' => 'Size', 'value_name' => 'M']); $this->review = $this->live;
        $g = app(StockHubTiktokVariantGateway::class); $p = app(StockHubTiktokVariantPayload::class);
        $prepared = $p->prepare($g->source('10'), $g->target('90'), null);
        foreach ($prepared['variants'] as &$v) { $v['uri'] = 'uploaded-uri'; } unset($v);
        $body = $p->build($g->target('90'), $prepared);
        $this->assertSame(['M','Blue'], array_column($body['skus'][1]['sales_attributes'], 'value_name'));
        $this->assertArrayNotHasKey('sku_img', $body['skus'][1]['sales_attributes'][0]);
        $this->assertSame(['uri' => 'uploaded-uri'], $body['skus'][1]['sales_attributes'][1]['sku_img']);
    }

    public function test_retained_images_emit_only_writable_uri_fields(): void
    {
        $this->live['skus'][0]['sales_attributes'][0]['sku_img']['urls'] = ['https://img.test/red.jpg'];
        $this->live['skus'][0]['sales_attributes'][0]['supplementary_sku_images'] = [['uri' => 'extra-uri', 'urls' => ['https://img.test/extra.jpg']]];
        $this->review = $this->live;
        $rows = app(StockHubTiktokVariantPayload::class)->retained(app(StockHubTiktokVariantGateway::class)->target('90'));
        $this->assertSame(['uri' => 'retained-uri'], $rows[0]['sales_attributes'][0]['sku_img']);
        $this->assertSame([['uri' => 'extra-uri']], $rows[0]['sales_attributes'][0]['supplementary_sku_images']);
    }

    public static function distinctSellerSkus(): array
    {
        return [
            'internal spaces' => ['INT-10-BLUE DARK', 'INT-10-BLUE  DARK', 'INT-10-BLUE   DARK'],
            'letter case' => ['INT-10-BLUE', 'int-10-blue', 'Int-10-Blue'],
            'unicode codepoints' => ["INT-10-CAF\u{00C9}", "INT-10-CAFE\u{0301}", "INT-10-caf\u{00E9}"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('distinctSellerSkus')]
    public function test_exact_seller_sku_keys_preserve_case_unicode_and_internal_spaces(string $existingFirst, string $existingSecond, string $newSku): void
    {
        $this->live['skus'][] = $this->sku('904', 'WHITE', 'White', 9, '25000');
        $this->live['skus'][1]['seller_sku'] = $existingFirst;
        $this->live['skus'][] = $this->sku('905', 'BLACK', 'Black', 9, '25000');
        $this->live['skus'][2]['seller_sku'] = $existingSecond;
        $this->review = $this->live;
        $this->models[1]['model_sku'] = $newSku;
        $g = app(StockHubTiktokVariantGateway::class); $p = app(StockHubTiktokVariantPayload::class);
        $source = $g->source('10'); $target = $g->target('90');
        $prepared = $p->prepare($source, $target, null);
        $this->assertSame([$newSku,'INT-10-GREEN'], array_column($prepared['variants'], 'seller_sku'));
        foreach ($prepared['variants'] as &$variant) { $variant['uri'] = 'uploaded-uri'; } unset($variant);
        $body = $p->build($target, $prepared);
        $this->assertSame(['INT-10-RED',$existingFirst,$existingSecond,$newSku,'INT-10-GREEN'], array_column($body['skus'], 'seller_sku'));
        $this->assertSame(['901','904','905'], array_column(array_slice($body['skus'], 0, 3), 'id'));
    }

    public function test_surrounding_seller_sku_whitespace_is_ignored_for_matching_but_retained_in_body(): void
    {
        $this->live['skus'][0]['seller_sku'] = ' INT-10-RED '; $this->review = $this->live;
        $g = app(StockHubTiktokVariantGateway::class); $p = app(StockHubTiktokVariantPayload::class);
        $prepared = $p->prepare($g->source('10'), $g->target('90'), null);
        $this->assertSame(['INT-10-BLUE','INT-10-GREEN'], array_column($prepared['variants'], 'seller_sku'));
        foreach ($prepared['variants'] as &$variant) { $variant['uri'] = 'uploaded-uri'; } unset($variant);
        $this->assertSame(' INT-10-RED ', $p->build($g->target('90'), $prepared)['skus'][0]['seller_sku']);
    }
    public function test_variant_source_preserves_distinct_case_sensitive_skus(): void
    {
        $this->models[1]['model_sku'] = 'INT-10-blue';
        $this->models[2]['model_sku'] = 'INT-10-BLUE';
        $source = app(StockHubTiktokVariantGateway::class)->source('10');
        $this->assertSame(['INT-10-RED', 'INT-10-blue', 'INT-10-BLUE'], array_column($source['variants'], 'seller_sku'));
    }

}
