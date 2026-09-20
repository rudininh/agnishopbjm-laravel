<?php

namespace Tests\Unit\Services;

use App\Services\StbMappingSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class StbMappingSyncServiceTest extends TestCase
{
    public function test_import_boolean_columns_are_sent_as_postgresql_boolean_literals(): void
    {
        $service = new StbMappingSyncService();
        $method = new ReflectionMethod($service, 'normalizeBooleanColumns');

        $stockMaster = $method->invoke($service, 'stock_master', [
            'is_hidden_from_mapping' => 0,
        ]);
        $tiktokProduct = $method->invoke($service, 'tiktok_products', [
            'is_active' => 1,
        ]);

        $grammar = DB::connection()->getQueryGrammar();

        $this->assertSame('false', $stockMaster['is_hidden_from_mapping']->getValue($grammar));
        $this->assertSame('true', $tiktokProduct['is_active']->getValue($grammar));
    }
    public function test_mapping_table_registry_includes_account_aware_marketplace_listings(): void
    {
        $method = new ReflectionMethod(StbMappingSyncService::class, 'tables');
        $method->setAccessible(true);
        $tables = $method->invoke(new StbMappingSyncService(), false);

        $this->assertArrayHasKey('marketplace_listings', $tables);
        $this->assertSame(['stock_master_id', 'account_key'], $tables['marketplace_listings']);
    }
    public function test_stock_master_import_reconciles_existing_sku_when_incoming_id_is_occupied(): void
    {
        Schema::create('stock_master', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('internal_sku')->unique();
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->integer('stock_qty')->default(0);
            $table->timestamps();
        });
        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_master_id');
            $table->string('account_key');
            $table->string('channel');
            $table->string('remote_product_id');
            $table->string('remote_variant_id')->default('');
            $table->char('remote_identity_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['stock_master_id', 'account_key']);
        });
        $now = now();
        DB::table('stock_master')->insert([
            ['id' => 969, 'internal_sku' => 'INT-54256579274-SAND', 'product_name' => 'Old occupied row', 'variant_name' => 'Sand', 'stock_qty' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 1500, 'internal_sku' => 'INT-54256579274-KHAKKY', 'product_name' => 'Old Khakky row', 'variant_name' => 'Khakky', 'stock_qty' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('marketplace_listings')->insert([
            'stock_master_id' => 1500,
            'account_key' => 'shopee-gitacollectionbjm',
            'channel' => 'shopee',
            'remote_product_id' => 'gita-item',
            'remote_variant_id' => 'gita-model',
            'remote_identity_hash' => hash('sha256', 'gita-item:gita-model'),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $method = new ReflectionMethod(StbMappingSyncService::class, 'importTable');
        $method->setAccessible(true);
        $summary = $method->invoke(new StbMappingSyncService(), 'stock_master', [
            'rows' => [[
                'id' => 969,
                'internal_sku' => 'INT-54256579274-KHAKKY',
                'product_name' => 'KALISHA X MIMA',
                'variant_name' => 'Khakky',
                'stock_qty' => 9,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
        ], true, false);

        $this->assertSame(1, $summary['updated']);
        $this->assertDatabaseHas('stock_master', [
            'id' => 969,
            'internal_sku' => 'INT-54256579274-KHAKKY',
            'product_name' => 'KALISHA X MIMA',
        ]);
        $this->assertDatabaseMissing('stock_master', ['id' => 1500]);
        $this->assertDatabaseHas('marketplace_listings', [
            'stock_master_id' => 969,
            'account_key' => 'shopee-gitacollectionbjm',
        ]);
    }
}
