<?php

namespace Tests\Feature;

use App\Http\Middleware\StockCatalogMutationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockCatalogMutationRevisionTest extends TestCase
{
    use RefreshDatabase;

    private int $mutations = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/test-catalog-mutation', function () {
            $this->mutations++;
            return response()->json(['revision' => Schema::hasTable('marketplace_catalog_mutation_revision')
                ? DB::table('marketplace_catalog_mutation_revision')->value('revision') : null]);
        })->middleware(StockCatalogMutationLock::class);
    }

    public function test_revision_is_durable_before_the_mutation_and_survives_cache_eviction(): void
    {
        $this->postJson('/test-catalog-mutation')->assertOk()->assertJsonPath('revision', 1);
        Cache::flush();
        $this->postJson('/test-catalog-mutation')->assertOk()->assertJsonPath('revision', 2);
        $this->assertSame(2, $this->mutations);
    }

    public function test_missing_revision_seed_blocks_mutation_and_releases_mutex(): void
    {
        DB::table('marketplace_catalog_mutation_revision')->delete();
        $this->postJson('/test-catalog-mutation')->assertStatus(503);
        $this->assertSame(0, $this->mutations);
        $lock = Cache::lock('stock-catalog-mutation', 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_missing_revision_table_after_creation_schema_exists_blocks_mutation(): void
    {
        Schema::dropIfExists('marketplace_catalog_mutation_revision');
        $this->postJson('/test-catalog-mutation')->assertStatus(503);
        $this->assertSame(0, $this->mutations);
    }

    public function test_legacy_mutation_remains_available_before_creation_migration(): void
    {
        Schema::dropIfExists('marketplace_catalog_mutation_revision');
        Schema::drop('stock_hub_tiktok_product_guards');
        Schema::drop('stock_hub_tiktok_product_runs');
        $this->postJson('/test-catalog-mutation')->assertOk();
        $this->assertSame(1, $this->mutations);
    }
}
