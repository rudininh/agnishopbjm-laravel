<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Services\MarketplaceTokenRefreshService;
use Tests\TestCase;

class OrphanVariantCleanupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_account_is_not_a_cleanup_target(): void
    {
        $this->postJson('/api/marketplace/orphan-variants/preview', ['account_key' => 'shopee-agnishopbjm'])->assertUnprocessable();
    }

    public function test_preview_is_account_bound_and_requires_explicit_delete_confirmation(): void
    {
        $this->mock(MarketplaceTokenRefreshService::class, fn ($m) => $m->shouldReceive('refreshDueTokens')->once()->andReturn([]));
        $run = $this->postJson('/api/marketplace/orphan-variants/preview', ['account_key' => 'tiktok-agnishopbjm'])
            ->assertOk()->assertJsonPath('status', 'scanning')->json();
        $this->getJson('/api/marketplace/orphan-variants/'.$run['run_id'].'?account_key=shopee-gitacollectionbjm')->assertNotFound();
        $this->postJson('/api/marketplace/orphan-variants/'.$run['run_id'].'/submit', [
            'account_key' => 'tiktok-agnishopbjm', 'revision' => 'abc', 'item_ids' => ['abc'],
        ])->assertUnprocessable();
        $this->postJson('/api/marketplace/orphan-variants/'.$run['run_id'].'/submit', [
            'account_key' => 'tiktok-agnishopbjm', 'revision' => 'abc', 'item_ids' => ['abc'], 'confirm_delete' => true,
        ])->assertConflict();
    }

    public function test_shared_mutation_lock_blocks_cleanup_and_legacy_deletion(): void
    {
        $lock = Cache::lock('stock-catalog-mutation', 900);
        $lock->get();
        try {
            $this->postJson('/api/marketplace/orphan-variants/00000000-0000-4000-8000-000000000000/step', ['account_key' => 'tiktok-agnishopbjm'])->assertStatus(423);
            $this->postJson('/api/shopee/delete-variant', [])->assertStatus(423);
            $this->postJson('/api/tiktok/delete-variant', [])->assertStatus(423);
            $this->postJson('/api/sku-mapping/update-marketplace-variant-sku', [])->assertStatus(423);
            $this->postJson('/api/tiktok/bulk-missing-variants/sku-cleanup/00000000-0000-4000-8000-000000000000/submit', [])->assertStatus(423);
        } finally { $lock->release(); }
    }
}
