<?php

namespace Tests\Feature;

use App\Services\StockHubTiktokVariantBatchSelection;
use App\Services\StockHubTiktokVariantBatchService;
use App\Services\StockHubTiktokVariantService;
use App\Services\MarketplaceVariantCoverageService;
use App\Services\MarketplaceStockMirrorTransport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class StockHubTiktokVariantBatchTest extends TestCase
{
    use RefreshDatabase;

    private string $base = '/api/stock-hub/tiktok-variant-batches';
    private array $starts = [];
    private array $steps = [];
    private array $children = [];
    private string $childStatus = 'success';
    private bool $ready = true;
    private bool $crash = false;
    private ?string $failureKind = null;
    private $duringStep = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        $selection = Mockery::mock(StockHubTiktokVariantBatchSelection::class);
        $selection->shouldReceive('assertReady')->andReturnUsing(function () { if (! $this->ready) { throw new \RuntimeException('private credential diagnostic'); } });
        $selection->shouldReceive('forProducts')->andReturn(array_map(fn ($id, $state) => [
            'source_product_id' => (string) $id, 'name' => 'Product '.$id,
            'coverage' => ['state' => $state, 'missing_count' => $state === 'missing_variants' ? 2 : null],
        ], [10, 20, 30, 40, 50], ['missing_variants', 'missing_variants', 'complete', 'missing_product', 'unknown']));
        $this->app->instance(StockHubTiktokVariantBatchSelection::class, $selection);
        $child = Mockery::mock(StockHubTiktokVariantService::class);
        $child->shouldReceive('source')->andReturnNull();
        $child->shouldReceive('start')->andReturnUsing(function ($source, $key) {
            $this->starts[] = [$source, $key];
            $run = $this->children[$key] ??= ['run_id' => (string) Str::uuid(), 'source_product_id' => $source, 'status' => 'preparing', 'can_continue' => true, 'next_step_after_ms' => 0, 'message' => 'Preparing', 'result' => null];
            if ($this->crash) { $this->crash = false; throw new \RuntimeException('crash after child creation'); }
            return $run;
        });
        $child->shouldReceive('show')->andReturnUsing(fn ($id) => collect($this->children)->firstWhere('run_id', $id));
        $child->shouldReceive('step')->andReturnUsing(function ($id) {
            $this->steps[] = $id;
            if ($this->duringStep) { ($this->duringStep)(); }
            foreach ($this->children as &$run) {
                if ($run['run_id'] === $id) {
                    $run['status'] = $this->childStatus; $run['can_continue'] = $this->childStatus === 'scanning';
                    $run['next_step_after_ms'] = $run['can_continue'] ? 5000 : 0;
                    $run['failure_kind'] = $this->failureKind;
                    $run['result'] = ['variants' => [['seller_sku' => 'A', 'status' => 'updated'], ['seller_sku' => 'B', 'status' => 'under_review']]];
                    return $run;
                }
            }
        });
        $this->app->instance(StockHubTiktokVariantService::class, $child);
    }

    private function start(?string $key = null): array
    {
        return $this->postJson($this->base.'/runs', ['request_key' => $key ?? (string) Str::uuid()])->assertOk()->json('data');
    }

    private function action(array $run, string $action = 'step'): array
    {
        return $this->postJson($this->base.'/runs/'.$run['run_id'].'/'.$action)->assertOk()->json('data');
    }

    public function test_catalog_lifecycle_is_sequential_and_records_non_candidates_without_mutation(): void
    {
        $run = $this->start();
        $this->assertSame(5, $run['summary']['products_total']);
        $this->assertSame(['10', '20', '30', '40', '50'], array_column($run['items'], 'source_product_id'));
        $this->assertSame([], $this->starts);
        $run = $this->action($run);
        $this->assertSame('10', $run['current_source_product_id']);
        $this->assertCount(1, $this->starts); $this->assertCount(0, $this->steps);
        for ($i = 0; $i < 10 && $run['can_continue']; $i++) { $run = $this->action($run); }
        $this->assertSame('completed', $run['status']);
        $this->assertSame(['10', '20'], array_column($this->starts, 0));
        $this->assertSame(['success', 'success', 'skipped_complete', 'missing_product', 'unknown'], array_column($run['items'], 'status'));
        $this->assertSame(5, $run['summary']['products_processed']);
        $this->assertSame(2, $run['summary']['variants_verified']);
        Http::assertNothingSent();
    }

    public function test_repeated_and_competing_starts_restore_global_batch_and_gets_do_no_child_work(): void
    {
        $key = (string) Str::uuid(); $run = $this->start($key);
        $this->assertSame($run['run_id'], $this->start($key)['run_id']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->getJson($this->base.'/active')->assertOk()->assertJsonPath('data.run_id', $run['run_id']);
        $this->getJson($this->base.'/runs/'.$run['run_id'])->assertOk();
        $this->assertSame([], $this->starts); $this->assertSame([], $this->steps);
        $run = $this->action($run, 'pause');
        $this->assertSame('paused', $run['status']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $run = $this->action($run, 'resume');
        while ($run['can_continue']) { $run = $this->action($run); }
        $this->getJson($this->base.'/active')->assertOk()->assertJsonPath('data', null);
        $this->assertNotSame($run['run_id'], $this->start()['run_id']);
    }

    public function test_start_rejects_any_client_selection_or_account_input(): void
    {
        foreach (['account_key', 'source_product_id', 'products', 'filters', 'target_account', 'page', 'unexpected'] as $field) {
            $this->postJson($this->base.'/runs', ['request_key' => (string) Str::uuid(), $field => 'injected'])->assertStatus(422);
        }
        $this->postJson($this->base.'/runs', ['request_key' => 'invalid'])->assertStatus(422);
    }

    public function test_uncertain_saved_children_are_never_automatically_restarted_or_checked_again(): void
    {
        $this->childStatus = 'submitted_unverified'; $run = $this->start();
        for ($i = 0; $i < 10 && $run['can_continue']; $i++) { $run = $this->action($run); }
        $this->assertSame('completed', $run['status']);
        $this->assertSame('submitted_unverified', $run['items'][0]['status']);
        $this->assertCount(2, $this->steps); $this->assertCount(2, $this->starts);
        $this->action($run); $this->action($run, 'resume');
        $this->assertCount(2, $this->steps); $this->assertCount(2, $this->starts);
    }

    public function test_get_reflects_saved_child_recovery_without_http_steps_or_batch_writes(): void
    {
        $this->childStatus = 'submitted_unverified'; $run = $this->start();
        while ($run['can_continue']) { $run = $this->action($run); }
        $childId = $run['items'][0]['child_run_id'];
        DB::table('stock_hub_tiktok_variant_runs')->insert(['id' => $childId, 'request_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'fixture'), 'source_product_id' => '10', 'target_product_id' => '900',
            'status' => 'success', 'attempted_at' => now(), 'accepted_at' => now(),
            'state' => json_encode(['message' => 'Hasil terverifikasi setelah Periksa Status.', 'result' => ['variants' => [
                ['source_variant_id' => '101', 'seller_sku' => 'A', 'status' => 'updated'],
                ['source_variant_id' => '102', 'seller_sku' => 'B', 'status' => 'updated'],
            ]]], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('stock_hub_tiktok_variant_batch_items')->get()->toJson();
        $starts = count($this->starts); $steps = count($this->steps);
        $recovered = $this->getJson($this->base.'/runs/'.$run['run_id'])->assertOk()->json('data');
        $this->assertSame('success', $recovered['items'][0]['status']);
        $this->assertSame('Hasil terverifikasi setelah Periksa Status.', $recovered['items'][0]['message']);
        $this->assertSame(3, $recovered['summary']['variants_verified']); // recovered two + other child's one
        $this->assertSame($before, DB::table('stock_hub_tiktok_variant_batch_items')->get()->toJson());
        $this->assertCount($starts, $this->starts); $this->assertCount($steps, $this->steps);
        Http::assertNothingSent();
    }

    public function test_crash_reuses_child_identity_and_pauses_with_sanitized_message(): void
    {
        $run = $this->start(); $this->crash = true;
        $run = $this->action($run);
        $this->assertSame('paused_error', $run['status']);
        $this->assertStringNotContainsString('crash', $run['message']);
        $key = DB::table('stock_hub_tiktok_variant_batch_items')->where('source_product_id', '10')->value('request_key');
        $this->assertSame($key, $this->starts[0][1]);
        $run = $this->action($run, 'resume'); $run = $this->action($run);
        $this->assertSame($key, $this->starts[1][1]); $this->assertCount(1, $this->children);
    }

    public function test_readiness_failure_pauses_before_any_child_write(): void
    {
        $run = $this->start(); $this->ready = false;
        $run = $this->action($run);
        $this->assertSame('paused_error', $run['status']);
        $this->assertSame([], $this->starts); $this->assertSame([], $this->steps);
        $this->assertStringNotContainsString('credential', $run['message']);
    }

    public function test_cooldown_and_competing_owner_prevent_advancement(): void
    {
        $this->childStatus = 'scanning'; $run = $this->action($this->start()); $run = $this->action($run);
        $this->assertSame(5000, $run['next_step_after_ms']);
        $this->action($run); $this->assertCount(1, $this->steps);
        $this->travel(6)->seconds();
        DB::table('stock_hub_tiktok_variant_batch_claims')->where('key', 'catalog')->update(['owner' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(5)]);
        $run = $this->action($run);
        $this->assertGreaterThan(0, $run['next_step_after_ms']); $this->assertCount(1, $this->steps);
    }

    public function test_pause_during_inflight_child_is_honored_before_next_product(): void
    {
        $run = $this->action($this->start());
        $this->duringStep = function () use ($run) {
            $paused = $this->action($run, 'pause'); $this->assertSame('pausing', $paused['status']);
            $contended = $this->action($run); $this->assertCount(1, $this->steps);
            $this->assertFalse($contended['can_continue']);
        };
        $run = $this->action($run);
        $this->assertSame('paused', $run['status']); $this->assertCount(1, $this->starts);
        $this->duringStep = null;
        $run = $this->action($run, 'resume'); $this->assertCount(1, $this->starts);
        $this->action($run); $this->assertCount(2, $this->starts);
    }

    public function test_existing_uncertain_child_is_linked_without_start_or_step(): void
    {
        $child = Mockery::mock(StockHubTiktokVariantService::class);
        $child->shouldReceive('source')->andReturn(['run_id' => (string) Str::uuid(), 'status' => 'under_review', 'message' => 'Review', 'can_continue' => false, 'result' => null]);
        $child->shouldNotReceive('start'); $child->shouldNotReceive('step');
        $this->app->instance(StockHubTiktokVariantService::class, $child);
        $run = $this->action($this->start());
        $this->assertSame('under_review', $run['items'][0]['status']);
        $this->assertNotNull($run['items'][0]['child_run_id']);
        $this->assertSame('20', $run['current_source_product_id']);
    }

    public function test_child_coordination_outage_pauses_but_never_retries_saved_blocked_child(): void
    {
        $this->childStatus = 'blocked'; $this->failureKind = 'coordination_unavailable';
        $run = $this->action($this->action($this->start()));
        $this->assertSame('paused_error', $run['status']);
        $this->assertSame('blocked', $run['items'][0]['status']);
        $this->assertSame('20', $run['current_source_product_id']);
        $run = $this->action($run, 'resume'); $this->action($run);
        $this->assertSame(['10', '20'], array_column($this->starts, 0));
        $this->assertCount(1, $this->steps);
    }

    public function test_request_that_joined_active_batch_stays_idempotent_after_completion(): void
    {
        $run = $this->start(); $joinedKey = (string) Str::uuid();
        $this->assertSame($run['run_id'], $this->start($joinedKey)['run_id']);
        while ($run['can_continue']) { $run = $this->action($run); }
        $this->assertSame($run['run_id'], $this->start($joinedKey)['run_id']);
        $this->assertSame(1, DB::table('stock_hub_tiktok_variant_batches')->count());
    }

    public function test_expired_step_owner_cannot_overwrite_new_owner_or_advance_next_product(): void
    {
        $run = $this->action($this->start());
        $this->duringStep = function () {
            DB::table('stock_hub_tiktok_variant_batch_claims')->where('key', 'catalog')->update(['owner' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(10)]);
        };
        $run = $this->action($run);
        $this->assertSame('preparing', $run['items'][0]['status']);
        $this->assertSame('10', $run['current_source_product_id']);
        $this->assertGreaterThan(0, $run['next_step_after_ms']);
        $this->assertCount(1, $this->starts);
        $this->duringStep = null; $this->travel(11)->minutes();
        $run = $this->action($run);
        $this->assertSame('success', $run['items'][0]['status']);
        $this->assertCount(1, $this->steps);
    }

    public function test_saved_child_key_recovers_terminal_run_without_starting_or_stepping(): void
    {
        $run = $this->start();
        $item = DB::table('stock_hub_tiktok_variant_batch_items')->where('batch_id', $run['run_id'])->where('source_product_id', '10')->first();
        $id = (string) Str::uuid();
        DB::table('stock_hub_tiktok_variant_runs')->insert(['id' => $id, 'request_key' => $item->request_key, 'request_hash' => str_repeat('a', 64), 'source_product_id' => '10', 'status' => 'submitted_unverified', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $child = Mockery::mock(StockHubTiktokVariantService::class);
        $child->shouldReceive('show')->with($id)->andReturn(['run_id' => $id, 'status' => 'submitted_unverified', 'can_continue' => false, 'message' => 'Uncertain', 'result' => null]);
        $child->shouldNotReceive('start'); $child->shouldNotReceive('step'); $child->shouldNotReceive('source');
        $this->app->instance(StockHubTiktokVariantService::class, $child);
        $run = $this->action($run);
        $this->assertSame($id, $run['items'][0]['child_run_id']);
        $this->assertSame('submitted_unverified', $run['items'][0]['status']);
        $this->assertSame('20', $run['current_source_product_id']);
    }

    public function test_independent_blocked_product_does_not_prevent_later_success(): void
    {
        $this->childStatus = 'blocked';
        $run = $this->action($this->action($this->start()));
        $this->assertSame('running', $run['status']);
        $this->assertSame('blocked', $run['items'][0]['status']);
        $this->childStatus = 'success';
        while ($run['can_continue']) { $run = $this->action($run); }
        $this->assertSame('success', $run['items'][1]['status']);
        $this->assertSame('completed', $run['status']);
        $this->assertSame(3, $run['summary']['products_need_attention']);
        $this->assertCount(2, $this->starts);
    }

    public function test_real_readiness_checks_both_fixed_contexts_locally_and_never_refreshes(): void
    {
        Schema::create('tiktok_tokens', function (Blueprint $t) { $t->id(); $t->string('account_key'); $t->string('shop_id')->nullable(); $t->boolean('is_active'); });
        DB::table('tiktok_tokens')->insert(['account_key' => 'tiktok-agnishopbjm', 'shop_id' => '33', 'is_active' => true]);
        $transport = Mockery::mock(MarketplaceStockMirrorTransport::class);
        $transport->shouldReceive('context')->once()->with('shopee-agnishopbjm', false, 6)->andReturn([]);
        $transport->shouldReceive('context')->once()->with('tiktok-agnishopbjm', false, 6)->andReturn([]);
        $selection = new StockHubTiktokVariantBatchSelection(app(MarketplaceVariantCoverageService::class), $transport);
        $selection->assertReady();
        Http::assertNothingSent();
    }

    private function legacyReadinessFixture(): StockHubTiktokVariantBatchSelection
    {
        config(['marketplace_accounts.accounts.shopee-agnishopbjm' => ['enabled' => true, 'channel' => 'shopee', 'credentials' => ['partner_id' => 123, 'partner_key' => 'test-secret', 'host' => 'https://source.test', 'redirect_url' => 'https://local.test']],
            'marketplace_accounts.accounts.tiktok-agnishopbjm' => ['enabled' => true, 'channel' => 'tiktok', 'credentials' => ['app_key' => 'app', 'app_secret' => 'secret', 'api_host' => 'https://target.test', 'auth_host' => 'https://target.test', 'redirect_url' => 'https://local.test']]]);
        DB::table('shopee_config')->delete();
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-agnishopbjm', 'shop_id' => 11, 'access_token' => 'source-token', 'access_token_expire_at' => now()->addHour(), 'is_active' => true]);
        Schema::create('tiktok_tokens', function (Blueprint $t) { $t->id(); $t->string('account_key'); $t->string('shop_id')->nullable(); $t->string('access_token'); $t->boolean('is_active'); $t->timestamp('access_token_expire_at'); });
        DB::table('tiktok_tokens')->insert(['account_key' => 'tiktok-agnishopbjm', 'shop_id' => null, 'access_token' => 'target-token', 'access_token_expire_at' => now()->addHour(), 'is_active' => true]);
        Schema::create('tiktok_shops', function (Blueprint $t) { $t->id(); $t->string('account_key'); $t->string('shop_id'); $t->string('cipher'); });
        DB::table('tiktok_shops')->insert(['account_key' => 'tiktok-agnishopbjm', 'shop_id' => '33', 'cipher' => 'target-cipher']);
        return new StockHubTiktokVariantBatchSelection(app(MarketplaceVariantCoverageService::class), app(MarketplaceStockMirrorTransport::class));
    }

    public function test_legacy_token_readiness_accepts_only_authorized_matching_shop_without_token_mutation(): void
    {
        $selection = $this->legacyReadinessFixture();
        Http::fake(['https://target.test/authorization/202309/shops*' => Http::response(['code' => 0, 'data' => ['shops' => [['id' => '33', 'cipher' => 'target-cipher']]]])]);
        $run = $this->start();
        Http::assertNothingSent();
        $service = new StockHubTiktokVariantBatchService($selection, app(StockHubTiktokVariantService::class));
        $run = $service->step($run['run_id']);
        $this->assertSame('running', $run['status']);
        $this->assertSame('preparing', $run['items'][0]['status']);
        $this->assertCount(1, $this->starts);
        $this->assertNull(DB::table('tiktok_tokens')->value('shop_id'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/authorization/202309/shops'));
    }

    public function test_legacy_token_readiness_still_blocks_mismatched_authorized_shop(): void
    {
        $selection = $this->legacyReadinessFixture();
        Http::fake(['https://target.test/authorization/202309/shops*' => Http::response(['code' => 0, 'data' => ['shops' => [['id' => '33', 'cipher' => 'wrong-cipher']]]])]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Identitas toko TikTok tidak cocok dengan otorisasi.');
        $selection->assertReady();
    }

    public function test_database_prevents_duplicate_product_and_request_ownership(): void
    {
        $run = $this->start();
        $row = (array) DB::table('stock_hub_tiktok_variant_batch_items')->where('batch_id', $run['run_id'])->first();
        unset($row['id']); $row['request_key'] = (string) Str::uuid(); $row['position'] = 99;
        try { DB::table('stock_hub_tiktok_variant_batch_items')->insert($row); $this->fail('Duplicate source must fail'); }
        catch (\Illuminate\Database\QueryException) { $this->assertSame(5, DB::table('stock_hub_tiktok_variant_batch_items')->count()); }
        $first = DB::table('stock_hub_tiktok_variant_batch_requests')->first();
        try { DB::table('stock_hub_tiktok_variant_batch_requests')->insert((array) $first); $this->fail('Duplicate request must fail'); }
        catch (\Illuminate\Database\QueryException) { $this->assertSame(1, DB::table('stock_hub_tiktok_variant_batch_requests')->count()); }
    }

    private function catalogFixture(): void
    {
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-agnishopbjm', 'shop_id' => 11, 'is_active' => true]);
        Schema::create('shopee_product', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('shop_id'); $t->string('name'); $t->string('status'); $t->boolean('is_active')->nullable(); });
        Schema::create('shopee_product_model', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('model_id'); $t->string('model_sku'); $t->integer('stock'); });
        Schema::create('tiktok_products', function (Blueprint $t) { $t->id(); $t->string('product_id'); $t->string('seller_sku'); $t->string('account_key'); $t->boolean('is_active')->default(true); });
        foreach ([['10','11',null,'UNLIST',0], ['20','11',true,'NORMAL',4], ['30','11',false,'DELETED',9], ['40','22',true,'NORMAL',9]] as [$id,$shop,$active,$status,$stock]) {
            DB::table('shopee_product')->insert(['item_id' => $id, 'shop_id' => $shop, 'name' => 'Source '.$id, 'status' => $status, 'is_active' => $active]);
            foreach (['RED', 'BLUE'] as $i => $sku) { DB::table('shopee_product_model')->insert(['item_id' => $id, 'model_id' => $id.$i, 'model_sku' => $id.'-'.$sku, 'stock' => $stock]); }
        }
        DB::table('tiktok_products')->insert([
            ['product_id' => '90', 'seller_sku' => '10-RED', 'account_key' => 'tiktok-agnishopbjm'],
            ['product_id' => '91', 'seller_sku' => '20-RED', 'account_key' => 'tiktok-agnishopbjm'],
            ['product_id' => '91', 'seller_sku' => '20-BLUE', 'account_key' => 'tiktok-agnishopbjm'],
            ['product_id' => '92', 'seller_sku' => '10-BLUE', 'account_key' => 'tiktok-other'],
        ]);
    }

    public function test_real_selection_is_fixed_account_full_catalog_and_stock_zero_survives(): void
    {
        $this->catalogFixture();
        $before = DB::table('shopee_product_model')->get()->toJson();
        $selection = new StockHubTiktokVariantBatchSelection(app(MarketplaceVariantCoverageService::class), app(MarketplaceStockMirrorTransport::class));
        $products = $selection->forProducts();
        $this->assertSame(['10', '20'], array_column($products, 'source_product_id'));
        $this->assertSame(['missing_variants', 'complete'], array_column(array_column($products, 'coverage'), 'state'));
        $this->assertSame(1, $products[0]['coverage']['missing_count']);
        $this->assertSame('Source 10', $products[0]['name']);
        $this->assertSame($before, DB::table('shopee_product_model')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_real_child_lifecycle_with_http_fake_records_product_block_and_preserves_stock(): void
    {
        $this->catalogFixture();
        $this->app->forgetInstance(StockHubTiktokVariantService::class);
        config(['shopee_mass_upload.stb_control_url' => '', 'marketplace_accounts.accounts.shopee-agnishopbjm' => ['enabled' => true, 'channel' => 'shopee', 'credentials' => ['partner_id' => 123, 'partner_key' => 'test-secret', 'host' => 'https://source.test', 'redirect_url' => 'https://local.test']]]);
        DB::table('shopee_tokens')->update(['access_token' => 'test-token', 'access_token_expire_at' => now()->addHour()]);
        DB::table('shopee_config')->delete();
        Schema::create('stock_master', function (Blueprint $t) { $t->id(); $t->integer('stock'); });
        DB::table('stock_master')->insert(['stock' => 19]);
        $this->assertSame('https://source.test', app(MarketplaceStockMirrorTransport::class)->context('shopee-agnishopbjm')['config']['host']);
        $before = DB::table('shopee_product_model')->get()->toJson();
        Http::fake(['https://source.test/*' => Http::response(['error' => '', 'response' => ['item_list' => [['item_id' => 10, 'shop_id' => 11, 'item_status' => 'UNLIST']]]])]);
        $run = $this->action($this->start());
        $this->assertSame('preparing', $run['items'][0]['status']);
        $run = $this->action($run);
        $this->assertSame('blocked', $run['items'][0]['status']);
        $this->assertSame('20', $run['current_source_product_id']);
        $this->assertSame(1, DB::table('stock_hub_tiktok_variant_runs')->count());
        $this->assertSame($before, DB::table('shopee_product_model')->get()->toJson());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        $this->assertStringContainsString('Produk sumber harus aktif', $run['items'][0]['message']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/api/v2/product/get_item_base_info'));
    }
}
