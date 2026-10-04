<?php

namespace Tests\Feature;

use App\Services\MarketplaceStockMirrorGateway;
use App\Services\MarketplaceStockMirrorLease;
use App\Services\MarketplaceStockMirrorService;
use App\Services\MarketplaceOperationLeaseService;
use App\Services\MarketplaceTokenRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceStockMirrorTest extends TestCase
{
    use RefreshDatabase;

    private function runner(): array
    {
        Http::preventStrayRequests();
        $gateway = new MirrorFakeGateway;
        $lease = new MirrorFakeLease;
        return [new MarketplaceStockMirrorService($gateway, $lease), $gateway, $lease];
    }

    private function start(MarketplaceStockMirrorService $service, string $type = 'product', ?string $key = null): array
    {
        return $service->start(['type' => $type, 'view_account_key' => MarketplaceStockMirrorGateway::SOURCE,
            ...($type !== 'all' ? ['product_id' => '10'] : []), ...($type === 'variant' ? ['variant_id' => '11'] : [])],
            MarketplaceStockMirrorGateway::TARGETS, $key ?? (string) Str::uuid());
    }

    private function finish(MarketplaceStockMirrorService $service, array $run): array
    {
        for ($i = 0; $i < 25 && $run['can_continue']; $i++) {
            $run = $service->step($run['run_id']);
        }
        $this->assertFalse($run['can_continue']);
        return $run;
    }

    public function test_target_selection_drift_blocks_both_destinations_on_resume(): void
    {
        foreach (['product', 'variant'] as $type) {
            foreach ([['30', '21'], ['20', '22']] as [$product, $variant]) {
                [$service, $gateway] = $this->runner();
                $run = $service->start(['type' => $type, 'view_account_key' => 'shopee-gitacollectionbjm',
                    'product_id' => '20', ...($type === 'variant' ? ['variant_id' => '21'] : [])],
                    MarketplaceStockMirrorGateway::TARGETS, (string) Str::uuid());
                $run = $service->step($run['run_id']);
                $gateway->targetProduct = $product;
                $gateway->targetVariant = $variant;
                $run = $this->finish($service, $run);
                $this->assertSame(0, $gateway->writes);
                $this->assertSame(2, $run['summary']['skipped']);
            }
        }
    }

    public function test_target_selection_is_rechecked_before_second_destination(): void
    {
        [$service, $gateway] = $this->runner();
        $run = $service->start(['type' => 'variant', 'view_account_key' => 'shopee-gitacollectionbjm',
            'product_id' => '20', 'variant_id' => '21'], MarketplaceStockMirrorGateway::TARGETS, (string) Str::uuid());
        $run = $service->step($run['run_id']);
        $run = $service->step($run['run_id']);
        $gateway->targetVariant = '22';
        $run = $this->finish($service, $run);
        $this->assertSame(1, $gateway->writes);
        $this->assertSame(['success', 'skipped'], array_column($run['items'], 'status'));
    }

    public function test_source_view_can_follow_a_valid_current_mapping(): void
    {
        [$service, $gateway] = $this->runner();
        $run = $this->start($service, 'variant');
        $gateway->targetProduct = '30';
        $gateway->targetVariant = '22';
        $run = $this->finish($service, $run);
        $this->assertSame(2, $gateway->writes);
        $this->assertSame(2, $run['summary']['success']);
        $this->assertSame(['30', '30'], array_column($run['items'], 'target_product_id'));
    }

    public function test_scope_relation_is_checked_again_after_destination_resolution(): void
    {
        [$service, $gateway] = $this->runner();
        $run = $service->start(['type' => 'product', 'view_account_key' => 'shopee-gitacollectionbjm',
            'product_id' => '20'], MarketplaceStockMirrorGateway::TARGETS, (string) Str::uuid());
        $gateway->onTarget = function (string $account) use ($gateway): void {
            if ($account === 'tiktok-agnishopbjm') {
                $gateway->targetProduct = '30';
            }
        };
        $run = $this->finish($service, $run);
        $this->assertSame(0, $gateway->writes);
        $this->assertSame(2, $run['summary']['skipped']);
    }

    public function test_three_scopes_two_targets_zero_and_reload_never_replay(): void
    {
        foreach (['all', 'product', 'variant'] as $type) {
            [$service, $gateway, $lease] = $this->runner();
            $run = $this->finish($service, $this->start($service, $type));
            $this->assertSame('completed', $run['status']);
            $this->assertSame(2, $run['summary']['success']);
            $this->assertSame([0, 0], array_column($run['items'], 'after_stock'));
            $this->assertSame(2, $gateway->writes);
            $this->assertSame($run, $service->step($run['run_id']));
            $this->assertSame(2, $gateway->writes);
            $this->assertSame($lease->acquires, $lease->releases);
        }
    }

    public function test_request_key_is_idempotent_and_conflicting_scope_is_rejected(): void
    {
        [$service] = $this->runner();
        $key = (string) Str::uuid();
        $first = $this->start($service, 'product', $key);
        $this->assertSame($first['run_id'], $this->start($service, 'product', $key)['run_id']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->start($service, 'variant', $key);
    }

    public function test_pagination_failure_blocks_all_deliveries(): void
    {
        [$service, $gateway] = $this->runner();
        $gateway->pageFailure = true;
        $run = $this->finish($service, $this->start($service, 'all'));
        $this->assertSame('scan_failed', $run['status']);
        $this->assertSame(0, $gateway->writes);
        $this->assertStringNotContainsString('secret', json_encode($run));
    }

    public function test_source_drift_between_targets_preserves_first_result(): void
    {
        [$service, $gateway] = $this->runner();
        $run = $this->start($service);
        $run = $service->step($run['run_id']); // product scan
        $run = $service->step($run['run_id']); // first delivery
        $gateway->sourceStock = 7;
        $run = $this->finish($service, $run);
        $this->assertSame(['success', 'skipped'], array_column($run['items'], 'status'));
        $this->assertSame(1, $gateway->writes);
    }

    public function test_unknown_source_and_failed_target_are_independent(): void
    {
        [$service, $gateway] = $this->runner();
        $gateway->failTarget = 'tiktok-agnishopbjm';
        $run = $this->finish($service, $this->start($service));
        $this->assertSame(['failed', 'success'], array_column($run['items'], 'status'));
        $gateway->sourceStock = null;
        $run = $this->finish($service, $this->start($service));
        $this->assertSame(['skipped', 'skipped'], array_column($run['items'], 'status'));
        $this->assertSame(1, $gateway->writes);
    }

    public function test_attempted_item_is_persisted_before_network_and_reopen_only_verifies(): void
    {
        [$service, $gateway] = $this->runner();
        $run = $this->start($service);
        $service->step($run['run_id']);
        $gateway->beforeWrite = function () use ($run): void {
            $state = json_decode(DB::table('marketplace_stock_mirror_runs')->where('id', $run['run_id'])->value('state'), true);
            $this->assertSame('attempted', $state['items'][0]['status']);
            throw new \RuntimeException('secret timeout');
        };
        $result = $service->step($run['run_id']);
        $this->assertSame('unverified', $result['items'][0]['status']);
        $row = DB::table('marketplace_stock_mirror_runs')->where('id', $run['run_id'])->first();
        $state = json_decode($row->state, true);
        $state['items'][0]['status'] = 'attempted';
        DB::table('marketplace_stock_mirror_runs')->where('id', $row->id)->update(['state' => json_encode($state)]);
        $result = $service->step($run['run_id']);
        $this->assertSame('unverified', $result['items'][0]['status']);
        $this->assertSame(1, $gateway->writes);
    }

    public function test_cancel_serializes_with_active_step_and_competing_runs(): void
    {
        [$service, $gateway] = $this->runner();
        $run = $this->start($service);
        $service->step($run['run_id']);
        $gateway->beforeWrite = function () use ($service, $run): void {
            foreach ([fn () => $service->step($run['run_id']), fn () => $service->cancel($run['run_id']),
                fn () => $this->start($service)] as $competing) {
                try {
                    $competing();
                    $this->fail('Concurrent operation accepted');
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $this->assertContains($e->getStatusCode(), [409, 423]);
                }
            }
        };
        $service->step($run['run_id']);
        $cancelled = $service->cancel($run['run_id']);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertFalse($cancelled['can_continue']);
        $this->assertSame($cancelled, $service->step($run['run_id']));
        $this->assertSame(1, $gateway->writes);
    }

    public function test_lease_unavailable_fails_closed_and_preserves_pending(): void
    {
        [$service, $gateway, $lease] = $this->runner();
        $run = $this->start($service);
        $lease->available = false;
        try {
            $service->step($run['run_id']);
            $this->fail('Missing lease accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
        }
        $this->assertSame(0, $gateway->writes);
        $lease->available = true;
        $this->assertSame(2, $this->finish($service, $service->show($run['run_id']))['summary']['success']);
    }

    public function test_malicious_scope_and_source_target_rejected_before_reads(): void
    {
        [$service, $gateway] = $this->runner();
        foreach ([['type' => 'all', 'view_account_key' => 'evil'],
            ['type' => 'variant', 'view_account_key' => MarketplaceStockMirrorGateway::SOURCE, 'product_id' => '999', 'variant_id' => '11']] as $scope) {
            try {
                $service->start($scope, MarketplaceStockMirrorGateway::TARGETS, (string) Str::uuid());
                $this->fail('Foreign scope accepted');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->start(['type' => 'all', 'view_account_key' => MarketplaceStockMirrorGateway::SOURCE], [MarketplaceStockMirrorGateway::SOURCE], (string) Str::uuid());
    }

    public function test_api_active_recovery_is_read_only_and_reports_original_scope(): void
    {
        [$service, $gateway, $lease] = $this->runner();
        $this->app->instance(MarketplaceStockMirrorService::class, $service);
        $tokens = \Mockery::mock(MarketplaceTokenRefreshService::class);
        $tokens->shouldNotReceive('refreshDueTokens');
        $this->app->instance(MarketplaceTokenRefreshService::class, $tokens);
        $this->getJson('/api/marketplace/stock-mirror/runs/active')->assertOk()->assertExactJson(['run' => null]);
        $run = $service->start(['type' => 'variant', 'view_account_key' => 'shopee-gitacollectionbjm',
            'product_id' => '10', 'variant_id' => '11'], ['tiktok-agnishopbjm'], (string) Str::uuid());
        $before = DB::table('marketplace_stock_mirror_runs')->where('id', $run['run_id'])->first();
        $guard = DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->first();
        $this->getJson('/api/marketplace/stock-mirror/runs/active')->assertOk()->assertJsonPath('run', $run);
        $this->assertEquals($before, DB::table('marketplace_stock_mirror_runs')->where('id', $run['run_id'])->first());
        $this->assertEquals($guard, DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->first());
        $this->assertSame(0, $gateway->writes);
        $this->assertSame(0, $lease->acquires);
        $service->cancel($run['run_id']);
        $this->getJson('/api/marketplace/stock-mirror/runs/active')->assertOk()->assertExactJson(['run' => null]);
    }

    public function test_api_active_recovery_reads_running_attempt_without_claim_or_write(): void
    {
        [$service, $gateway, $lease] = $this->runner();
        $this->app->instance(MarketplaceStockMirrorService::class, $service);
        $tokens = \Mockery::mock(MarketplaceTokenRefreshService::class);
        $tokens->shouldNotReceive('refreshDueTokens');
        $this->app->instance(MarketplaceTokenRefreshService::class, $tokens);
        $run = $service->step($this->start($service)['run_id']);
        DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->update([
            'owner' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(10),
        ]);
        $this->getJson('/api/marketplace/stock-mirror/runs/active')->assertOk()->assertJsonPath('run.run_id', $run['run_id'])
            ->assertJsonPath('run.status', 'running')->assertJsonPath('run.can_continue', true);
        $this->assertSame(0, $gateway->writes);
        $this->assertSame(1, $lease->acquires);
    }

    public function test_api_active_recovery_storage_error_is_sanitized(): void
    {
        $service = \Mockery::mock(MarketplaceStockMirrorService::class);
        $service->shouldReceive('active')->once()->andThrow(new \RuntimeException('secret signed URL'));
        $this->app->instance(MarketplaceStockMirrorService::class, $service);
        $response = $this->getJson('/api/marketplace/stock-mirror/runs/active')->assertStatus(503);
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_api_validates_before_token_refresh_and_returns_sanitized_progress(): void
    {
        [$service] = $this->runner();
        $this->app->instance(MarketplaceStockMirrorService::class, $service);
        $tokens = \Mockery::mock(MarketplaceTokenRefreshService::class);
        $tokens->shouldReceive('refreshDueTokens')->twice()->andReturn([]);
        $this->app->instance(MarketplaceTokenRefreshService::class, $tokens);
        $this->postJson('/api/marketplace/stock-mirror/runs', ['scope' => ['type' => 'all', 'view_account_key' => 'evil'],
            'target_accounts' => MarketplaceStockMirrorGateway::TARGETS, 'request_key' => (string) Str::uuid()])->assertStatus(422);
        $run = $this->postJson('/api/marketplace/stock-mirror/runs', ['scope' => ['type' => 'variant',
            'view_account_key' => MarketplaceStockMirrorGateway::SOURCE, 'product_id' => '10', 'variant_id' => '11'],
            'target_accounts' => MarketplaceStockMirrorGateway::TARGETS, 'request_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonStructure(['run_id', 'status', 'scope', 'target_accounts', 'summary', 'items', 'message', 'can_continue'])->json();
        $this->getJson('/api/marketplace/stock-mirror/runs/'.$run['run_id'])->assertOk()->assertJsonPath('status', 'scanning');
        $this->postJson('/api/marketplace/stock-mirror/runs/'.$run['run_id'].'/step')->assertOk()->assertJsonPath('status', 'running');
        $this->postJson('/api/marketplace/stock-mirror/runs/'.$run['run_id'].'/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_timeout_and_verification_mismatch_are_unverified_and_do_not_stop_other_target(): void
    {
        [$service, $gateway] = $this->runner();
        $gateway->noApply = 'tiktok-agnishopbjm';
        $result = $this->finish($service, $this->start($service));
        $this->assertSame(['unverified', 'success'], array_column($result['items'], 'status'));
        $this->assertSame(2, $gateway->writes);
        $this->assertSame(5, $result['items'][0]['after_stock']);
    }

    public function test_matching_readback_without_write_acceptance_is_unverified(): void
    {
        [$service, $gateway] = $this->runner();
        $gateway->rejectedWrite = 'tiktok-agnishopbjm';
        $result = $this->finish($service, $this->start($service));
        $this->assertSame(['unverified', 'success'], array_column($result['items'], 'status'));
        $this->assertSame(0, $result['items'][0]['after_stock']);
        $this->assertArrayNotHasKey('write_accepted', $result['items'][0]);
    }

    public function test_crash_after_terminal_save_cannot_leave_active_run_guard(): void
    {
        [$service, $gateway, $lease] = $this->runner();
        $run = $this->start($service);
        $lease->beforeRelease = function () use ($run): void {
            if (DB::table('marketplace_stock_mirror_runs')->where('id', $run['run_id'])->value('status') === 'completed') {
                $this->assertNull(DB::table('marketplace_stock_mirror_claims')->value('active_run_id'));
            }
        };
        $this->finish($service, $run);
    }

    public function test_expired_claim_recovery_verifies_accepted_attempt_without_resending(): void
    {
        [$service, $gateway] = $this->runner();
        $run = $this->start($service);
        $service->step($run['run_id']);
        $gateway->noApply = 'tiktok-agnishopbjm';
        $service->step($run['run_id']);
        $state = json_decode(DB::table('marketplace_stock_mirror_runs')->where('id', $run['run_id'])->value('state'), true);
        $this->assertTrue($state['items'][0]['write_accepted']);
        $state['items'][0]['status'] = 'attempted';
        DB::table('marketplace_stock_mirror_runs')->where('id', $run['run_id'])->update(['state' => json_encode($state)]);
        DB::table('marketplace_stock_mirror_claims')->where('id', 'global')->update(['owner' => (string) Str::uuid(), 'expires_at' => now()->subSecond()]);
        $gateway->stocks['tiktok-agnishopbjm'] = 0;
        $result = $service->step($run['run_id']);
        $this->assertSame('success', $result['items'][0]['status']);
        $this->assertSame(1, $gateway->writes);
    }

    public function test_unchanged_and_missing_targets_never_send_writes(): void
    {
        [$service, $gateway] = $this->runner();
        $gateway->stocks['tiktok-agnishopbjm'] = 0;
        $gateway->missingTarget = 'shopee-gitacollectionbjm';
        $result = $this->finish($service, $this->start($service));
        $this->assertSame(['unchanged', 'skipped'], array_column($result['items'], 'status'));
        $this->assertSame(0, $gateway->writes);
    }

    public function test_actual_cache_failure_blocks_before_operation_lease(): void
    {
        Http::preventStrayRequests();
        \Illuminate\Support\Facades\Cache::shouldReceive('lock')->once()->andThrow(new \RuntimeException('secret cache failure'));
        $local = \Mockery::mock(MarketplaceOperationLeaseService::class);
        $local->shouldNotReceive('acquire');
        try {
            (new MarketplaceStockMirrorLease($local))->acquire();
            $this->fail('Unavailable cache accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Koordinasi marketplace tidak tersedia.', $e->getMessage());
        }
    }

    public function test_step_initial_read_failure_is_sanitized(): void
    {
        $service = \Mockery::mock(MarketplaceStockMirrorService::class);
        $service->shouldReceive('show')->once()->andThrow(new \RuntimeException('secret signed URL'));
        $this->app->instance(MarketplaceStockMirrorService::class, $service);
        $response = $this->postJson('/api/marketplace/stock-mirror/runs/'.Str::uuid().'/step');
        $response->assertStatus(503);
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_malformed_remote_acquisition_is_not_protection(): void
    {
        Http::preventStrayRequests();
        config(['shopee_mass_upload.stb_control_url' => 'https://stb.example/api/sync-runtime',
            'shopee_mass_upload.stb_control_token' => 'fake-test-token']);
        Http::fake(['*/acquire' => Http::response(['data' => ['acquired' => 'false', 'token' => 'remote-fake']], 200),
            '*/release' => Http::response(['data' => ['released' => true]], 200)]);
        $local = new MarketplaceOperationLeaseService;
        try {
            (new MarketplaceStockMirrorLease($local))->acquire();
            $this->fail('Malformed STB lease accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Koordinasi marketplace tidak tersedia.', $e->getMessage());
        }
        $this->assertFalse((bool) $local->status()['active']);
    }

    public function test_verified_cache_failure_does_not_change_success_or_stock_master(): void
    {
        [$service, $gateway] = $this->runner();
        $gateway->cacheFailure = true;
        \Illuminate\Support\Facades\Schema::create('stock_master', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('internal_sku');
            $table->integer('stock_qty');
        });
        DB::table('stock_master')->insert(['id' => 1, 'internal_sku' => 'SKU', 'stock_qty' => 93]);
        DB::table('stock_adjustments')->insert(['stock_master_id' => 1, 'delta' => 3, 'before_quantity' => 90, 'after_quantity' => 93, 'reason' => 'correction']);
        $before = DB::table('stock_master')->get()->toJson();
        $ledger = DB::table('stock_adjustments')->get()->toJson();
        $run = $this->finish($service, $this->start($service));
        $this->assertSame(2, $run['summary']['success']);
        $this->assertSame($before, DB::table('stock_master')->get()->toJson());
        $this->assertSame($ledger, DB::table('stock_adjustments')->get()->toJson());
        $this->assertStringNotContainsString('secret', json_encode($run));
    }

    public function test_actual_lease_coordinates_local_and_configured_remote_and_releases_both(): void
    {
        Http::preventStrayRequests();
        config(['shopee_mass_upload.stb_control_url' => 'https://stb.example/api/sync-runtime',
            'shopee_mass_upload.stb_control_token' => 'fake-test-token']);
        Http::fake([
            '*/acquire' => Http::response(['data' => ['acquired' => true, 'token' => 'remote-fake']], 200),
            '*/renew' => Http::response(['data' => ['renewed' => true]], 200),
            '*/release' => Http::response(['data' => ['released' => true]], 200),
        ]);
        $local = new MarketplaceOperationLeaseService;
        $lease = new MarketplaceStockMirrorLease($local);
        $lease->acquire();
        $this->assertTrue($local->status()['active']);
        $this->assertFalse($local->acquire('other', 60)['acquired']);
        $lease->renew();
        $lease->release();
        $this->assertFalse((bool) $local->status()['active']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/acquire') && $request['operation'] === 'gitashop_mass_upload');
        Http::assertSentCount(3);
    }

    public function test_remote_unsupported_fails_closed_and_releases_local_lease(): void
    {
        Http::preventStrayRequests();
        config(['shopee_mass_upload.stb_control_url' => 'https://stb.example/api/sync-runtime',
            'shopee_mass_upload.stb_control_token' => 'fake-test-token']);
        Http::fake(['*/acquire' => Http::response(['message' => 'secret unsupported'], 422)]);
        $local = new MarketplaceOperationLeaseService;
        $lease = new MarketplaceStockMirrorLease($local);
        try {
            $lease->acquire();
            $this->fail('Unsupported STB accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
        $this->assertFalse((bool) $local->status()['active']);
        $lock = \Illuminate\Support\Facades\Cache::lock('stock-catalog-mutation', 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}

class MirrorFakeGateway extends MarketplaceStockMirrorGateway
{
    public string $targetProduct = '20';
    public string $targetVariant = '21';
    public int $writes = 0;
    public ?int $sourceStock = 0;
    public array $stocks = ['tiktok-agnishopbjm' => 5, 'shopee-gitacollectionbjm' => 5];
    public bool $pageFailure = false;
    public ?string $failTarget = null;
    public mixed $beforeWrite = null;
    public mixed $onTarget = null;
    public ?string $noApply = null;
    public bool $cacheFailure = false;
    public ?string $rejectedWrite = null;
    public ?string $missingTarget = null;

    public function __construct() {}

    public function catalogPage(?string $cursor): array
    {
        if ($cursor !== null && $this->pageFailure) {
            throw new \RuntimeException('secret signed URL');
        }
        return ['products' => $cursor === null ? ['10'] : [], 'next_cursor' => $cursor === null ? '1:0' : null, 'complete' => $cursor !== null];
    }

    public function sourceSelection(string $viewAccountKey, string $productId, ?string $variantId): array
    {
        if ($viewAccountKey !== self::SOURCE) {
            return [['source_product_id' => '10', 'source_variant_id' => '11', 'view_product_id' => $productId, 'view_variant_id' => $variantId ?? '21']];
        }
        if ($productId !== '10' || ($variantId !== null && $variantId !== '11')) {
            throw new \RuntimeException('secret foreign identity');
        }
        return [['source_product_id' => '10', 'source_variant_id' => '11']];
    }

    public function product(string $accountKey, string $productId): array
    {
        if ($accountKey === $this->failTarget) {
            throw new \RuntimeException('secret failed read');
        }
        return ['product_id' => $productId, 'name' => 'Product', 'complete' => true, 'active' => true,
            'variants' => [['id' => $accountKey === self::SOURCE ? '11' : $this->targetVariant, 'name' => 'Red', 'seller_sku' => 'SKU',
                'stock' => $accountKey === self::SOURCE ? $this->sourceStock : $this->stocks[$accountKey]]]];
    }

    public function target(string $sourceProductId, array $sourceVariant, string $targetAccountKey): array
    {
        if ($this->onTarget) {
            ($this->onTarget)($targetAccountKey);
        }
        if ($targetAccountKey === $this->missingTarget) {
            return ['status' => 'skipped', 'product_id' => null, 'variant_id' => null, 'reason' => 'secret error'];
        }
        return ['status' => 'ready', 'product_id' => $this->targetProduct, 'variant_id' => $this->targetVariant, 'reason' => null];
    }

    public function write(string $accountKey, string $productId, string $variantId, int $stock, string $idempotencyKey): array
    {
        $this->writes++;
        if ($this->beforeWrite) {
            ($this->beforeWrite)();
        }
        if ($this->noApply !== $accountKey) {
            $this->stocks[$accountKey] = $stock;
        }
        return ['status' => $this->rejectedWrite === $accountKey ? 'error' : 'success'];
    }

    public function cacheVerified(string $accountKey, string $productId, string $variantId, int $stock): void
    {
        if ($this->cacheFailure) {
            throw new \RuntimeException('secret database unavailable');
        }
    }
}

class MirrorFakeLease extends MarketplaceStockMirrorLease
{
    public int $acquires = 0;
    public int $releases = 0;
    public bool $available = true;
    public mixed $beforeRelease = null;

    public function __construct() {}

    public function acquire(): void
    {
        if (! $this->available) {
            throw new \RuntimeException('secret cache unavailable');
        }
        $this->acquires++;
    }

    public function renew(): void {}

    public function release(): void
    {
        if ($this->beforeRelease) {
            ($this->beforeRelease)();
        }
        $this->releases++;
    }
}
