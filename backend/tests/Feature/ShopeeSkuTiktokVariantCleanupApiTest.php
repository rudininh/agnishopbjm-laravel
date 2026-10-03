<?php

namespace Tests\Feature;

use App\Services\MarketplaceApiService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ShopeeSkuTiktokVariantCleanupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stock_master', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_sku')->unique();
            $table->string('shopee_product_id')->nullable();
            $table->string('shopee_sku')->nullable();
            $table->string('shopee_seller_sku')->nullable();
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->integer('stock_qty')->default(0);
            $table->string('tiktok_product_id')->nullable();
            $table->string('tiktok_sku')->nullable();
            $table->string('tiktok_seller_sku')->nullable();
            $table->boolean('is_hidden_from_mapping')->default(false);
            $table->string('hidden_from_mapping_reason')->nullable();
            $table->timestamp('hidden_from_mapping_at')->nullable();
            $table->string('hidden_from_mapping_by')->nullable();
            $table->timestamps();
        });
        Schema::create('shopee_product', function (Blueprint $table): void {
            $table->unsignedBigInteger('item_id')->primary();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('shopee_product_model', function (Blueprint $table): void {
            $table->unsignedBigInteger('item_id');
            $table->string('model_id');
            $table->string('name')->nullable();
            $table->string('model_sku')->nullable();
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('original_price')->default(0);
            $table->integer('stock')->default(0);
            $table->timestamps();
            $table->primary(['item_id', 'model_id']);
        });
        Schema::create('shopee_product_image', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('model_id')->nullable();
            $table->string('image_url');
            $table->timestamps();
        });
        Schema::create('tiktok_products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id');
            $table->string('sku_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('image_url')->nullable();
            $table->string('sku_name')->nullable();
            $table->string('seller_sku')->nullable();
            $table->integer('stock_qty')->default(0);
            $table->unsignedBigInteger('price')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('sku_variant_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_master_id');
            $table->string('target_channel');
            $table->string('source_channel')->nullable();
            $table->string('action_type');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        Schema::create('shopee_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('synced_at')->nullable();
        });
        Schema::create('tiktok_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('synced_at')->nullable();
        });
    }

    public function test_submit_requires_a_64_character_revision(): void
    {
        Http::fake();

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/00000000-0000-4000-8000-000000000001/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('revision');

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/00000000-0000-4000-8000-000000000001/submit', [
            'revision' => str_repeat('a', 63),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('revision');

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/00000000-0000-4000-8000-000000000001/submit', [
            'revision' => str_repeat('a', 65),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('revision');

        Http::assertNothingSent();
    }

    public function test_preview_uses_mapping_only_candidates_without_sending_http(): void
    {
        Http::fake();
        $this->seedMappingOnlyCandidate();

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/preview')
            ->assertOk()
            ->assertJsonPath('status', 'ready_for_review')
            ->assertJsonPath('summary.conflicts', 1)
            ->assertJsonPath('summary.eligible', 1)
            ->assertJsonCount(1, 'items');

        $this->assertDatabaseCount('tiktok_reconciliation_runs', 1);
        $this->assertDatabaseCount('tiktok_reconciliation_run_items', 1);
        Http::assertNothingSent();
    }

    public function test_submit_maps_a_missing_run_to_not_found_without_sending_http(): void
    {
        Http::fake();

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/00000000-0000-4000-8000-000000000001/submit', [
            'revision' => str_repeat('a', 64),
        ])
            ->assertNotFound()
            ->assertJsonPath('status', 'not_found');

        Http::assertNothingSent();
    }

    public function test_submit_maps_a_stale_revision_and_completed_run(): void
    {
        Http::fake();
        $this->seedMappingOnlyCandidate();

        $preview = $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/preview')
            ->assertOk()
            ->json();

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/'.$preview['run_id'].'/submit', [
            'revision' => str_repeat('b', 64),
        ])
            ->assertConflict()
            ->assertJsonPath('status', 'stale_revision');

        DB::table('tiktok_reconciliation_runs')->where('id', $preview['run_id'])->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/'.$preview['run_id'].'/submit', [
            'revision' => $preview['revision'],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        Http::assertNothingSent();
    }

    public function test_submit_maps_a_busy_service_result_to_locked(): void
    {
        Http::fake();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException());
        $catalogLock = Mockery::mock(Lock::class);
        $catalogLock->shouldReceive('get')->once()->andReturn(true);
        $catalogLock->shouldReceive('release')->once();
        Cache::shouldReceive('lock')->once()->with('stock-catalog-mutation', 900)->andReturn($catalogLock);
        Cache::shouldReceive('lock')->once()->with('shopee-sku-tiktok-cleanup', 900)->andReturn($lock);

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/00000000-0000-4000-8000-000000000001/submit', [
            'revision' => str_repeat('a', 64),
        ])
            ->assertStatus(423)
            ->assertJsonPath('status', 'busy');

        Http::assertNothingSent();
    }

    public function test_submit_maps_a_claimed_run_to_locked_without_sending_http(): void
    {
        Http::fake();
        $runId = '00000000-0000-4000-8000-000000000004';
        $revision = str_repeat('e', 64);
        $this->insertRun($runId, $revision, 'claimed');
        $this->insertCleanupItem($runId, 'leased-item', 'ready', 'tt-claimed');
        DB::table('tiktok_reconciliation_run_items')->where('run_id', $runId)->update([
            'execution_owner' => '00000000-0000-4000-8000-000000000005',
            'execution_lease_until' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/'.$runId.'/submit', [
            'revision' => $revision,
        ])
            ->assertStatus(423)
            ->assertJsonPath('status', 'claimed');

        Http::assertNothingSent();
    }

    public function test_submit_fails_closed_for_an_unexpected_service_status(): void
    {
        Http::fake();
        $runId = '00000000-0000-4000-8000-000000000006';
        $revision = str_repeat('f', 64);
        $this->insertRun($runId, $revision, 'unexpected');

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/'.$runId.'/submit', [
            'revision' => $revision,
        ])
            ->assertStatus(500)
            ->assertJsonPath('status', 'unexpected');

        Http::assertNothingSent();
    }

    public function test_submit_uses_persisted_targets_and_keeps_failed_and_partial_results_at_ok(): void
    {
        Http::fake();
        $api = Mockery::mock(MarketplaceApiService::class);
        $api->shouldReceive('fetchTiktokProduct')->once()->with('tt-failed')->andReturn(['ok' => false]);
        $api->shouldReceive('fetchTiktokProduct')->once()->with('tt-partial')->andReturn(['ok' => false]);
        $this->app->instance(MarketplaceApiService::class, $api);

        $failedRunId = '00000000-0000-4000-8000-000000000002';
        $partialRunId = '00000000-0000-4000-8000-000000000003';
        $revision = str_repeat('c', 64);
        $this->insertRun($failedRunId, $revision, 'failed');
        $this->insertCleanupItem($failedRunId, 'failed-item', 'failed', 'tt-failed');
        $this->insertRun($partialRunId, $revision, 'partial');
        $this->insertCleanupItem($partialRunId, 'partial-failed-item', 'failed', 'tt-partial');
        $this->insertCleanupItem($partialRunId, 'partial-updated-item', 'updated', 'tt-updated');

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/'.$failedRunId.'/submit', [
            'revision' => $revision,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.status', 'failed');

        $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/'.$partialRunId.'/submit', [
            'revision' => $revision,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.status', 'failed')
            ->assertJsonPath('items.1.status', 'updated');

        Http::assertNothingSent();
    }

    private function seedMappingOnlyCandidate(): void
    {
        $now = now();
        $stockMasterId = DB::table('stock_master')->insertGetId([
            'internal_sku' => 'INTERNAL-KHAKKY',
            'shopee_product_id' => '54256579274',
            'shopee_sku' => 'model-khakky',
            'shopee_seller_sku' => 'INT-54256579274-SAND',
            'product_name' => 'Authoritative product',
            'variant_name' => 'Khakky',
            'stock_qty' => 7,
            'is_hidden_from_mapping' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sku_mappings')->insert([
            'stock_master_id' => $stockMasterId,
            'shopee_item_id' => '54256579274',
            'shopee_model_id' => 'model-khakky',
            'seller_sku' => 'INT-54256579274-SAND',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('shopee_product')->insert([
            'item_id' => 54256579274,
            'name' => 'Authoritative product',
            'status' => 'NORMAL',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('shopee_product_model')->insert([
            'item_id' => 54256579274,
            'model_id' => 'model-khakky',
            'name' => 'Khakky',
            'model_sku' => 'INT-54256579274-SAND',
            'price' => 12500,
            'original_price' => 12500,
            'stock' => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tiktok_products')->insert([
            [
                'product_id' => 'tt-1',
                'sku_id' => 'tt-sku-sand',
                'product_name' => 'Authoritative product',
                'sku_name' => 'Sand',
                'seller_sku' => 'INT-54256579274-SAND',
                'stock_qty' => 7,
                'price' => 12500,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'product_id' => 'tt-1',
                'sku_id' => 'tt-sku-survivor',
                'product_name' => 'Authoritative product',
                'sku_name' => 'Survivor',
                'seller_sku' => 'INT-SURVIVOR',
                'stock_qty' => 7,
                'price' => 12500,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    private function insertRun(string $runId, string $revision, string $status): void
    {
        DB::table('tiktok_reconciliation_runs')->insert([
            'id' => $runId,
            'revision' => $revision,
            'status' => $status,
            'summary' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCleanupItem(string $runId, string $itemKey, string $status, string $productId): void
    {
        DB::table('tiktok_reconciliation_run_items')->insert([
            'run_id' => $runId,
            'item_key' => $itemKey,
            'action_type' => 'shopee_sku_tiktok_delete',
            'status' => $status,
            'source_fingerprint' => str_repeat('d', 64),
            'target_product_id' => $productId,
            'target_sku_id' => 'target-'.$itemKey,
            'payload' => json_encode([
                'item_key' => $itemKey,
                'tiktok_product_id' => $productId,
                'tiktok_sku_id' => 'target-'.$itemKey,
                'shopee_item_id' => 'item-'.$itemKey,
                'shopee_model_id' => 'model-'.$itemKey,
                'old_sku' => 'OLD-'.$itemKey,
                'target_sku' => 'TARGET-'.$itemKey,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
