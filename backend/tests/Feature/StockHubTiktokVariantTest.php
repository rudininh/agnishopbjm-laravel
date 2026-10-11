<?php

namespace Tests\Feature;

use App\Services\MarketplaceStockMirrorLease;
use App\Services\MarketplaceOperationBusy;
use App\Services\StockHubTiktokProductRejected;
use App\Services\StockHubTiktokVariantGateway;
use App\Services\StockHubTiktokVariantPayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class StockHubTiktokVariantTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/api/stock-hub/tiktok-variants';
    private int $edits = 0;
    private int $uploads = 0;
    private string $mode = '';
    private bool $busy = false;
    private array $sent = [];
    private array $sourceData;
    private array $targetData;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['shopee_mass_upload.stb_control_url' => '']);
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-agnishopbjm', 'shop_id' => 11, 'is_active' => true]);
        Schema::create('shopee_product', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('shop_id'); $t->boolean('is_active')->default(true); });
        Schema::create('shopee_product_model', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('model_id'); $t->string('model_sku'); $t->integer('stock')->default(9); });
        Schema::create('tiktok_products', function (Blueprint $t) { $t->id(); $t->string('product_id'); $t->string('sku_id'); $t->string('seller_sku'); $t->boolean('is_active')->default(true); });
        Schema::create('stock_master', function (Blueprint $t) { $t->id(); $t->integer('stock'); });
        DB::table('stock_master')->insert(['stock' => 19]);
        DB::table('shopee_product')->insert(['item_id' => '10', 'shop_id' => '11']);
        $this->sourceData = ['id' => '10', 'shop_id' => '11', 'title' => 'Agni product', 'main_images' => ['https://img.test/main.jpg'], 'variants' => []];
        foreach (['RED' => 7, 'BLUE' => 0, 'GREEN' => 4] as $name => $stock) {
            $id = (string) (101 + count($this->sourceData['variants']));
            $this->sourceData['variants'][] = ['id' => $id, 'name' => $name, 'seller_sku' => 'INT-10-'.$name, 'stock' => $stock,
                'price' => '25000', 'attributes' => [['name' => 'Warna', 'value_name' => $name, 'image_url' => 'https://img.test/'.$name.'.jpg']]];
            DB::table('shopee_product_model')->insert(['item_id' => '10', 'model_id' => $id, 'model_sku' => 'INT-10-'.$name, 'stock' => $stock]);
        }
        DB::table('tiktok_products')->insert(['product_id' => '900', 'sku_id' => '901', 'seller_sku' => 'INT-10-RED']);
        $this->targetData = ['id' => '900', 'title' => 'Agni product', 'status' => 'ACTIVATE', 'audit' => ['status' => 'APPROVED'],
            'package_weight' => ['value' => '0.2', 'unit' => 'KILOGRAM'],
            'package_dimensions' => ['length' => '20', 'width' => '15', 'height' => '3', 'unit' => 'CENTIMETER'],
            'skus' => [['id' => '901', 'seller_sku' => 'INT-10-RED', 'price' => ['sale_price' => '25000', 'currency' => 'IDR'],
                'inventory' => [['warehouse_id' => 'wh', 'quantity' => 7]], 'sales_attributes' => [['id' => '100', 'name' => 'Warna', 'value_name' => 'RED']]]]];
        if (class_exists(StockHubTiktokVariantGateway::class)) {
            $gateway = Mockery::mock(StockHubTiktokVariantGateway::class);
            $gateway->shouldReceive('source')->andReturnUsing(fn () => $this->sourceData);
            $gateway->shouldReceive('targetShop')->andReturn('33');
            $gateway->shouldReceive('assertTargetShop')->andReturnNull();
            $gateway->shouldReceive('target')->andReturnUsing(fn () => $this->targetData);
            $gateway->shouldReceive('page')->andReturn(['ids' => ['900', '910'], 'next' => null, 'total' => 2]);
            $gateway->shouldReceive('catalogTarget')->andReturnUsing(fn ($id) => $id === '900' ? $this->targetData : ['id' => '910', 'skus' => [['id' => '911', 'seller_sku' => $this->mode === 'conflict' ? 'INT-10-BLUE' : 'OTHER']]]);
            $gateway->shouldReceive('upload')->andReturnUsing(function ($variant) { $this->uploads++; if ($this->mode === 'image' && $variant['id'] === '103') { throw new \DomainException('Gambar varian gagal.'); } return 'tt-uri-'.$variant['id']; });
            $gateway->shouldReceive('prepareEdit')->andReturnUsing(fn () => function ($payload) {
                $this->edits++; $this->sent = $payload;
                $this->assertNotNull(DB::table('stock_hub_tiktok_variant_runs')->where('status', 'submitting')->value('attempted_at'));
                if ($this->mode === 'unknown') { throw new \RuntimeException('signed secret'); }
                if ($this->mode === 'rejected') { throw new StockHubTiktokProductRejected('TikTok menolak varian (kode 123).'); }
                return [];
            });
            $gateway->shouldReceive('verify')->andReturnUsing(function ($source, $expected, $id) {
                if ($this->mode === 'unknown') { throw new \RuntimeException('signed secret'); }
                if ($this->mode === 'stock_drift') { throw new \App\Services\StockHubTiktokVariantStockMismatch(); }
                return ['product_id' => $id, 'published' => $this->mode !== 'review', 'price' => $expected['price'],
                    'variants' => array_map(fn ($v) => ['source_variant_id' => $v['id'], 'seller_sku' => $v['seller_sku'], 'name' => $v['name'], 'status' => $this->mode === 'review' ? 'under_review' : 'updated', 'reason' => null], $expected['variants'])];
            });
            $this->app->instance(StockHubTiktokVariantGateway::class, $gateway);
        }
        $lease = Mockery::mock(MarketplaceStockMirrorLease::class);
        $lease->shouldReceive('acquire')->andReturnUsing(function () { if ($this->busy) { throw new MarketplaceOperationBusy(); } });
        $lease->shouldReceive('renew')->andReturnNull(); $lease->shouldReceive('release')->andReturnNull();
        $this->app->instance(MarketplaceStockMirrorLease::class, $lease);
    }

    private function start(array $extra = []): array
    {
        return $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => (string) Str::uuid(), ...$extra])->assertOk()->json('data');
    }

    private function finish(array $run): array
    {
        for ($i = 0; $i < 25 && $run['can_continue']; $i++) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        return $run;
    }

    public function test_one_product_batch_adds_all_missing_variants_without_source_quantity_writes(): void
    {
        $before = DB::table('shopee_product_model')->get()->toJson();
        $run = $this->finish($this->start());
        $this->assertSame('success', $run['status']);
        $this->assertSame(1, $this->edits); $this->assertSame(2, $this->uploads);
        $this->assertSame(['INT-10-RED', 'INT-10-BLUE', 'INT-10-GREEN'], array_column($this->sent['skus'], 'seller_sku'));
        $this->assertSame([7, 0, 4], array_map(fn ($s) => $s['inventory'][0]['quantity'], $this->sent['skus']));
        $this->assertSame($before, DB::table('shopee_product_model')->get()->toJson());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        $this->assertSame(2, count($run['result']['variants']));
        Http::assertNothingSent();
    }

    public function test_shared_template_repairs_all_skus_and_freezes_values_before_upload(): void
    {
        foreach (['length','width','height'] as $key) { $this->targetData['package_dimensions'][$key] = '0'; }
        $this->sourceData['weight'] = 0.2;
        $this->sourceData['dimension'] = ['package_length' => 0, 'package_width' => 0, 'package_height' => 0];
        $before = DB::table('shopee_product_model')->get()->toJson();
        $run = $this->start();
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('scanning', $run['status']);
        config(['stock_hub_package.dimensions.length' => '99']);
        $run = $this->finish($run);
        $this->assertSame('success', $run['status']);
        $this->assertSame(1, $this->edits);
        $this->assertSame(['length' => '25', 'width' => '25', 'height' => '10', 'unit' => 'CENTIMETER'], $this->sent['package_dimensions']);
        $this->assertArrayNotHasKey('package_weight', $this->sent);
        foreach ($this->sent['skus'] as $sku) {
            $this->assertSame('25', $sku['sku_dimensions']['length']);
            $this->assertSame('0.2', $sku['sku_weight']['value']);
        }
        $this->assertTrue($run['package_template_applied']);
        $this->assertSame($before, DB::table('shopee_product_model')->get()->toJson());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_uncertain_template_edit_is_not_replayed_after_config_changes_or_retries(): void
    {
        foreach (['length','width','height'] as $key) { $this->targetData['package_dimensions'][$key] = '0'; }
        $this->mode = 'unknown';
        $run = $this->finish($this->start());
        $this->assertSame('submitted_unverified', $run['status']);
        config(['stock_hub_package.dimensions.length' => '99']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk();
        $this->assertSame(1, $this->edits);
        $this->assertSame('25', $this->sent['package_dimensions']['length']);
    }

    public function test_coordination_infrastructure_failure_has_fixed_marker_and_no_gateway_progress(): void
    {
        $lease = Mockery::mock(MarketplaceStockMirrorLease::class);
        $lease->shouldReceive('acquire')->andThrow(new \RuntimeException('private coordination diagnostic'));
        $lease->shouldReceive('release')->andReturnNull();
        $this->app->instance(MarketplaceStockMirrorLease::class, $lease);
        $gateway = Mockery::mock(StockHubTiktokVariantGateway::class);
        $gateway->shouldNotReceive('source'); $gateway->shouldNotReceive('prepareEdit');
        $this->app->instance(StockHubTiktokVariantGateway::class, $gateway);
        $run = $this->start();
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('blocked', $run['status']);
        $this->assertSame('coordination_unavailable', $run['failure_kind'] ?? null);
        $this->assertStringNotContainsString('private', $run['message']);
        $this->assertNull(DB::table('stock_hub_tiktok_variant_runs')->value('attempted_at'));
        Http::assertNothingSent();
    }

    public function test_repeated_request_and_unknown_attempt_never_replay(): void
    {
        $key = (string) Str::uuid(); $payload = ['source_product_id' => '10', 'request_key' => $key];
        $first = $this->postJson($this->base.'/runs', $payload)->assertOk()->json('data');
        $this->assertSame($first['run_id'], $this->postJson($this->base.'/runs', $payload)->assertOk()->json('data.run_id'));
        $this->mode = 'unknown'; $run = $this->finish($first);
        $this->assertSame('submitted_unverified', $run['status']); $this->assertFalse($run['can_retry']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk();
        $this->assertSame(1, $this->edits);
        $this->assertStringNotContainsString('secret', json_encode($run));
    }

    public function test_stock_drift_recovery_explains_the_blocker_without_replaying_edit(): void
    {
        $this->mode = 'stock_drift'; $run = $this->finish($this->start());
        $this->assertSame('submitted_unverified', $run['status']);
        $this->assertStringContainsString('Stok TikTok berbeda', $run['message']);
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertStringContainsString('Stok TikTok berbeda', $run['message']);
        $this->assertSame(1, $this->edits);
        $this->assertFalse($run['can_retry']);
    }

    public function test_busy_marketplace_keeps_same_run_and_makes_no_gateway_progress_during_cooldown(): void
    {
        $this->freezeTime(); $this->busy = true; $run = $this->start();
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('preparing', $run['status']); $this->assertSame(5000, $run['next_step_after_ms']);
        $this->busy = false;
        $this->assertSame($run, $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'));
        $this->travel(5)->seconds();
        $this->assertSame('success', $this->finish($run)['status']);
    }

    public function test_sku_found_in_another_product_blocks_before_upload_or_edit(): void
    {
        $this->mode = 'conflict'; $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']);
        $this->assertStringContainsString('produk TikTok lain', $run['message']);
        $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
    }

    public function test_partial_image_preparation_is_reported_per_variant_and_submitted_once(): void
    {
        $this->mode = 'image'; $run = $this->finish($this->start());
        $this->assertSame('partial', $run['status']); $this->assertSame(1, $this->edits);
        $this->assertSame(['INT-10-RED', 'INT-10-BLUE'], array_column($this->sent['skus'], 'seller_sku'));
        $this->assertEqualsCanonicalizing(['updated', 'failed'], array_column($run['result']['variants'], 'status'));
    }

    public function test_pending_review_only_reads_status_and_blocks_new_batch_until_published(): void
    {
        $this->mode = 'review'; $run = $this->finish($this->start());
        $this->assertSame('under_review', $run['status']); $this->assertFalse($run['can_retry']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->mode = '';
        $this->assertSame('success', $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data.status'));
        $this->assertSame(1, $this->edits);
    }

    public function test_source_changes_and_catalog_revision_changes_stop_unattempted_submission(): void
    {
        $run = $this->start();
        while ($run['status'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        $this->sourceData['variants'][1]['seller_sku'] = 'INT-10-CHANGED';
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->edits);
    }

    public function test_input_account_switch_and_invalid_manual_price_are_rejected(): void
    {
        foreach ([['source_account' => 'shopee-gitacollectionbjm'], ['target_account' => 'shopee-gitacollectionbjm'], ['context' => ['manual_price' => 0]], ['context' => ['manual_price' => 12.5]]] as $extra) {
            $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => (string) Str::uuid(), ...$extra])->assertStatus(422);
        }
        $this->assertSame(0, $this->edits);
    }

    public function test_legacy_entry_cannot_bypass_an_unresolved_new_batch(): void
    {
        $this->mode = 'unknown'; $run = $this->finish($this->start());
        $this->assertSame('submitted_unverified', $run['status']);
        try { \App\Services\StockHubTiktokVariantInterlock::assertLegacyAvailable('900'); $this->fail('Unresolved target must stay guarded.'); }
        catch (\DomainException) { $this->assertSame(1, $this->edits); }
    }

    public function test_legacy_uncertain_attempt_blocks_both_entry_points_until_skus_are_observed(): void
    {
        DB::table('stock_hub_tiktok_variant_legacy_attempts')->insert(['id' => (string) Str::uuid(), 'target_product_id' => '900', 'status' => 'attempted', 'seller_skus' => json_encode(['INT-10-BLUE']), 'created_at' => now(), 'updated_at' => now()]);
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
        $this->targetData['skus'][] = ['id' => '902', 'seller_sku' => 'INT-10-BLUE', 'price' => ['sale_price' => '25000', 'currency' => 'IDR'],
            'inventory' => [['warehouse_id' => 'wh', 'quantity' => 0]], 'sales_attributes' => [['id' => '100', 'name' => 'Warna', 'value_name' => 'BLUE']]];
        $run = $this->finish($this->start());
        $this->assertSame('success', $run['status']); $this->assertSame(1, $this->edits);
        $this->assertSame('resolved', DB::table('stock_hub_tiktok_variant_legacy_attempts')->value('status'));
    }

    public function test_cached_product_id_cannot_authorize_edits_to_a_fresh_unrelated_product(): void
    {
        $this->targetData['skus'][0]['seller_sku'] = 'INT-20-OTHER';
        $this->targetData['skus'][0]['sales_attributes'][0]['value_name'] = 'OTHER';
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
    }

    public function test_explicit_remote_rejection_allows_only_a_deliberate_new_run(): void
    {
        $this->mode = 'rejected'; $run = $this->finish($this->start());
        $this->assertSame('rejected', $run['status']); $this->assertTrue($run['can_retry']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk();
        $this->assertSame(1, $this->edits);
        $this->mode = ''; $next = $this->start(); $this->assertNotSame($run['run_id'], $next['run_id']);
        $this->assertSame('success', $this->finish($next)['status']); $this->assertSame(2, $this->edits);
    }

    public function test_legacy_mutation_revision_invalidates_the_completed_scan(): void
    {
        $run = $this->start();
        while ($run['status'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        DB::table('marketplace_catalog_mutation_revision')->where('id', 1)->increment('revision');
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->edits);
    }
    private function appendTarget(int $sourceIndex, string $price = '25000'): void
    {
        $v = $this->sourceData['variants'][$sourceIndex];
        $sku = $this->targetData['skus'][0];
        $sku['id'] = (string) (902 + $sourceIndex);
        $sku['seller_sku'] = $v['seller_sku'];
        $sku['price']['sale_price'] = $price;
        $sku['inventory'][0]['quantity'] = $v['stock'];
        $sku['sales_attributes'][0]['value_name'] = $v['name'];
        $this->targetData['skus'][] = $sku;
    }

    public function test_complete_target_needs_no_manual_price_even_with_tied_prices(): void
    {
        $this->appendTarget(1, '30000'); $this->appendTarget(2, '35000');
        $run = $this->finish($this->start());
        $this->assertSame('exists', $run['status']); $this->assertSame(0, $this->edits);
    }

    public function test_manual_price_correction_is_a_deliberate_idempotent_request(): void
    {
        $this->appendTarget(1, '30000');
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']);
        $this->assertSame('manual_price', $run['required_fields'][0]['key']);
        $key = (string) Str::uuid(); $payload = ['source_product_id' => '10', 'request_key' => $key, 'context' => ['manual_price' => 28000]];
        $next = $this->postJson($this->base.'/runs', $payload)->assertOk()->json('data');
        $this->assertNotSame($run['run_id'], $next['run_id']);
        $this->assertSame($next['run_id'], $this->postJson($this->base.'/runs', $payload)->assertOk()->json('data.run_id'));
        $this->postJson($this->base.'/runs', [...$payload, 'context' => ['manual_price' => 29000]])->assertStatus(409);
        $this->assertSame('success', $this->finish($next)['status']);
        $this->assertSame('28000', $this->sent['skus'][2]['price']['amount']);
    }

    public function test_fresh_target_custom_sku_owned_by_another_agni_product_blocks(): void
    {
        DB::table('shopee_product')->insert(['item_id' => '20', 'shop_id' => '11']);
        DB::table('shopee_product_model')->insert(['item_id' => '20', 'model_id' => '201', 'model_sku' => 'CUSTOM-OTHER']);
        $other = $this->targetData['skus'][0]; $other['id'] = '999'; $other['seller_sku'] = 'CUSTOM-OTHER';
        $other['sales_attributes'][0]['value_name'] = 'OTHER'; $this->targetData['skus'][] = $other;
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
    }

    public function test_external_arrival_of_prepared_variant_does_not_hide_failed_image_variant(): void
    {
        $this->mode = 'image'; $run = $this->start();
        while ($run['status'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        $this->appendTarget(1);
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('partial', $run['status']); $this->assertSame(0, $this->edits);
        $this->assertSame('failed', $run['result']['variants'][0]['status']);
        $this->assertSame('INT-10-GREEN', $run['result']['variants'][0]['seller_sku']);
    }

    public function test_historical_uncertain_bulk_attempt_cannot_replay_through_legacy_entry(): void
    {
        Schema::table('stock_master', fn (Blueprint $t) => $t->string('shopee_product_id')->nullable());
        DB::table('stock_master')->update(['shopee_product_id' => '10']);
        Schema::create('sku_variant_actions', function (Blueprint $t) { $t->id(); $t->integer('stock_master_id'); $t->string('target_channel'); $t->string('action_type'); $t->string('status'); $t->json('payload'); });
        DB::table('sku_variant_actions')->insert(['stock_master_id' => DB::table('stock_master')->value('id'), 'target_channel' => 'tiktok', 'action_type' => 'bulk_create_variant', 'status' => 'submitted_unverified',
            'payload' => json_encode(['mutation' => ['request' => ['path' => '/product/202509/products/900/partial_edit']], 'outcome' => ['seller_sku' => 'INT-10-BLUE']])]);
        $this->assertSame('blocked', $this->finish($this->start())['status']);
        try { \App\Services\StockHubTiktokVariantInterlock::beginLegacy('900', ['INT-10-BLUE'], $this->targetData); $this->fail('Historical uncertain legacy edit must remain guarded.'); }
        catch (\DomainException) { $this->assertSame(0, DB::table('stock_hub_tiktok_variant_legacy_attempts')->count()); }
    }

    public function test_legacy_ambiguous_responses_retain_guard_and_never_replay(): void
    {
        $controller = new class extends \App\Http\Controllers\OmnichannelController {
            public array $reply = []; public int $calls = 0;
            protected function submitTiktokBulkPartialEditPayload(string $path, array $payload, array $context): array { $this->calls++; return $this->reply; }
            protected function verifyBulkTiktokVariantGroup(string $productId, array $sellerSkus): array { return ['sync' => ['status' => 'error'], 'found_seller_skus' => []]; }
        };
        $method = (new \ReflectionClass($controller))->getMethod('submitPreparedBulkTiktokVariantBatch');
        foreach ([['code' => 123, '_http_status' => 200, 'data' => ['skus' => [['id' => '902']]]], ['code' => 123, '_http_status' => 302], ['code' => 123.0, '_http_status' => 400], ['code' => false, '_http_status' => 200], ['code' => 0, '_http_status' => 500]] as $response) {
            DB::table('stock_hub_tiktok_variant_legacy_attempts')->delete();
            $controller->reply = $response;
            $args = ['900', $this->targetData, [['seller_sku' => 'INT-10-BLUE', 'variant' => ['variant_name' => 'BLUE'], 'uploaded_image_uri' => 'tos-alisg-i-aphluv4xwc-sg/blue']], 25000, (object) ['shop_id' => '33', 'cipher' => 'cipher'], 'fake-token'];
            $result = $method->invokeArgs($controller, $args);
            $this->assertSame('attempted', DB::table('stock_hub_tiktok_variant_legacy_attempts')->value('status'));
            $this->assertFalse($result['mutation']['ok']);
            $calls = $controller->calls;
            try { $method->invokeArgs($controller, $args); $this->fail('Uncertain response must not replay.'); }
            catch (\DomainException) { $this->assertSame($calls, $controller->calls); }
        }
    }

    public function test_fresh_target_trimmed_sku_owned_elsewhere_still_blocks(): void
    {
        DB::table('shopee_product')->insert(['item_id' => '20', 'shop_id' => '11']);
        DB::table('shopee_product_model')->insert(['item_id' => '20', 'model_id' => '201', 'model_sku' => ' CUSTOM-OTHER ']);
        $other = $this->targetData['skus'][0]; $other['id'] = '999'; $other['seller_sku'] = 'CUSTOM-OTHER';
        $other['sales_attributes'][0]['value_name'] = 'OTHER'; $this->targetData['skus'][] = $other;
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
    }

    public function test_variants_arriving_during_preparation_are_reported_as_skipped_without_edit(): void
    {
        $run = $this->start();
        while ($run['status'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        $this->appendTarget(1); $this->appendTarget(2);
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('exists', $run['status']); $this->assertSame(0, $this->edits);
        $this->assertSame(['skipped', 'skipped'], array_column($run['result']['variants'], 'status'));
        $this->assertSame(['INT-10-BLUE', 'INT-10-GREEN'], array_column($run['result']['variants'], 'seller_sku'));
    }

}
