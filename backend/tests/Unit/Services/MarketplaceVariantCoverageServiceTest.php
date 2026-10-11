<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceVariantCoverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceVariantCoverageServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TT = 'tiktok-agnishopbjm';
    private const GITA = 'shopee-gitacollectionbjm';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['shopee-agnishopbjm' => 11, self::GITA => 22] as $account => $shop) {
            DB::table('shopee_tokens')->insert(['account_key' => $account, 'shop_id' => $shop, 'is_active' => true, 'created_at' => now()]);
        }
        Schema::create('shopee_product', function (Blueprint $t) {
            $t->id(); $t->string('item_id'); $t->string('shop_id'); $t->boolean('is_active')->default(true);
        });
        Schema::create('shopee_product_model', function (Blueprint $t) {
            $t->id(); $t->string('item_id'); $t->string('model_id'); $t->string('model_sku')->nullable();
        });
        Schema::create('tiktok_products', function (Blueprint $t) {
            $t->id(); $t->string('product_id'); $t->string('account_key')->default(self::TT);
            $t->string('seller_sku')->nullable(); $t->boolean('is_active')->default(true);
        });
    }

    private function source(string $id = '10', array $skus = ['RED', 'BLUE', 'GREEN']): array
    {
        DB::table('shopee_product')->insert(['item_id' => $id, 'shop_id' => '11']);
        $models = [];
        foreach ($skus as $i => $sku) {
            $model = ['model_id' => (string) ($i + 1), 'model_sku' => $sku, 'name' => $sku];
            DB::table('shopee_product_model')->insert(['item_id' => $id, 'model_id' => $model['model_id'], 'model_sku' => $sku]);
            $models[] = $model;
        }

        return ['item_id' => $id, 'shop_id' => '11', 'models' => $models];
    }

    private function target(string $id = '900', array $skus = ['RED', 'EXTRA'], string $account = self::TT): void
    {
        if ($account === self::GITA) {
            DB::table('shopee_product')->insert(['item_id' => $id, 'shop_id' => '22']);
        }
        foreach ($skus as $i => $sku) {
            if ($account === self::GITA) {
                DB::table('shopee_product_model')->insert(['item_id' => $id, 'model_id' => (string) ($i + 1), 'model_sku' => $sku]);
            } else {
                DB::table('tiktok_products')->insert(['product_id' => $id, 'seller_sku' => $sku, 'account_key' => $account]);
            }
        }
    }

    private function coverage(array $source, string $account = self::TT): array
    {
        return app(MarketplaceVariantCoverageService::class)->forProducts([$source])[$source['item_id']][$account];
    }

    public function test_exact_sku_sets_ignore_extras_and_resolve_accounts_independently_without_writes(): void
    {
        $source = $this->source();
        $this->target();
        $this->target('800', ['BLUE', 'GREEN'], self::GITA);
        $before = [DB::table('shopee_product_model')->get()->toArray(), DB::table('tiktok_products')->get()->toArray(), DB::table('marketplace_listings')->count()];
        $tt = $this->coverage($source);
        $this->assertSame('missing_variants', $tt['state']);
        $this->assertSame('900', $tt['target_product_id']);
        $this->assertSame([3, 1, 2], [$tt['source_count'], $tt['existing_count'], $tt['missing_count']]);
        $this->assertSame(['2', '3'], $tt['missing_model_ids']);
        $gita = $this->coverage($source, self::GITA);
        $this->assertSame(['1'], $gita['missing_model_ids']);
        $this->assertSame('800', $gita['target_product_id']);
        $this->assertEquals($before, [DB::table('shopee_product_model')->get()->toArray(), DB::table('tiktok_products')->get()->toArray(), DB::table('marketplace_listings')->count()]);
        Http::assertNothingSent();
    }

    public function test_equal_variant_counts_do_not_imply_completeness_and_unmapped_exact_skus_are_existing(): void
    {
        $source = $this->source();
        $this->target('900', ['RED', 'BLUE', 'EXTRA']);
        $this->assertSame(['3'], $this->coverage($source)['missing_model_ids']);
        DB::table('tiktok_products')->where('seller_sku', 'EXTRA')->update(['seller_sku' => ' GREEN ']);
        $this->assertSame('complete', $this->coverage($source)['state']);
        $this->assertSame(3, $this->coverage($source)['existing_count']);
    }

    public function test_missing_parent_is_distinct_from_unavailable_cache(): void
    {
        $source = $this->source();
        $this->assertUnknown($this->coverage($source));
        $this->target('999', ['UNRELATED']);
        $missing = $this->coverage($source);
        $this->assertSame('missing_product', $missing['state']);
        $this->assertNull($missing['target_product_id']);
        $this->assertSame([], $missing['missing_model_ids']);
    }

    public function test_shared_source_sku_outside_filtered_input_is_unknown(): void
    {
        $source = $this->source();
        $this->source('20', ['RED']);
        $this->target();
        $this->assertUnknown($this->coverage($source));
    }

    public function test_duplicate_or_blank_source_skus_and_model_ids_are_unknown(): void
    {
        $this->target();
        foreach ([['RED', 'RED'], ['RED', ''], ['RED', '-']] as $i => $skus) {
            $this->assertUnknown($this->coverage($this->source((string) (10 + $i), $skus)));
        }
        $source = $this->source('30', ['ONE', 'TWO']);
        $source['models'][1]['model_id'] = '1';
        $this->assertUnknown($this->coverage($source));
    }

    public function test_duplicate_target_skus_or_split_target_products_are_unknown(): void
    {
        $source = $this->source();
        $this->target('900', ['RED', 'RED']);
        $this->assertUnknown($this->coverage($source));
        DB::table('tiktok_products')->delete();
        $this->target('900', ['RED']);
        $this->target('901', ['BLUE']);
        $this->assertUnknown($this->coverage($source));
    }

    public function test_consistent_int_prefix_can_resolve_but_contradictory_int_ownership_is_unknown(): void
    {
        $source = $this->source('10', ['INT-10-RED', 'INT-10-BLUE']);
        $this->target('900', ['INT-10-OLD']);
        $this->assertSame('missing_variants', $this->coverage($source)['state']);
        $this->target('900', ['INT-20-OTHER']);
        $this->assertUnknown($this->coverage($source));
        $bad = $this->source('30', ['INT-10-RED']);
        $this->assertUnknown($this->coverage($bad));
    }

    public function test_links_must_agree_with_sku_evidence_and_have_a_cached_target(): void
    {
        $source = $this->source();
        $this->target();
        Schema::drop('sku_mappings');
        Schema::create('sku_mappings', function (Blueprint $t) {
            $t->id(); $t->string('shopee_item_id'); $t->string('tiktok_product_id');
        });
        DB::table('sku_mappings')->insert(['shopee_item_id' => '10', 'tiktok_product_id' => '900']);
        $this->assertSame('missing_variants', $this->coverage($source)['state']);
        DB::table('sku_mappings')->update(['tiktok_product_id' => 'gone']);
        $this->assertUnknown($this->coverage($source));
        $this->target('901', ['OTHER']);
        DB::table('sku_mappings')->update(['tiktok_product_id' => '901']);
        $this->assertUnknown($this->coverage($source));
    }

    public function test_account_scoped_listing_can_resolve_without_any_matching_skus(): void
    {
        $source = $this->source();
        $this->target('800', ['OTHER'], self::GITA);
        Schema::drop('marketplace_listings');
        Schema::create('marketplace_listings', function (Blueprint $t) {
            $t->id(); $t->integer('stock_master_id'); $t->string('account_key');
            $t->string('remote_product_id'); $t->boolean('is_active')->default(true);
        });
        DB::table('marketplace_listings')->insert([
            ['stock_master_id' => 1, 'account_key' => 'shopee-agnishopbjm', 'remote_product_id' => '10'],
            ['stock_master_id' => 1, 'account_key' => self::GITA, 'remote_product_id' => '800'],
        ]);
        $this->assertSame(3, $this->coverage($source, self::GITA)['missing_count']);
    }

    public function test_other_accounts_and_inactive_targets_do_not_supply_sku_evidence(): void
    {
        $source = $this->source();
        $this->target('999', ['UNRELATED']);
        $this->target('900', ['RED'], 'tiktok-other');
        $this->target('901', ['BLUE']);
        DB::table('tiktok_products')->where('product_id', '901')->update(['is_active' => false]);
        $this->assertSame('missing_product', $this->coverage($source)['state']);
        $source['shop_id'] = '22';
        $this->assertUnknown($this->coverage($source));
    }

    public function test_legacy_single_account_schema_and_missing_columns_fail_safely(): void
    {
        $source = $this->source();
        Schema::drop('tiktok_products');
        Schema::create('tiktok_products', function (Blueprint $t) {
            $t->id(); $t->string('product_id'); $t->string('seller_sku')->nullable();
        });
        DB::table('tiktok_products')->insert(['product_id' => '900', 'seller_sku' => 'RED']);
        $this->assertSame('missing_variants', $this->coverage($source)['state']);
        Schema::table('tiktok_products', fn (Blueprint $t) => $t->dropColumn('seller_sku'));
        $this->assertUnknown($this->coverage($source));
        Schema::drop('tiktok_products');
        $this->assertUnknown($this->coverage($source));
    }

    public function test_gita_shop_must_be_distinct_and_ambiguous_source_shop_is_unknown(): void
    {
        $source = $this->source();
        $this->target();
        DB::table('shopee_tokens')->where('account_key', self::GITA)->update(['shop_id' => 11]);
        $this->assertUnknown($this->coverage($source, self::GITA));
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-agnishopbjm', 'shop_id' => 33, 'is_active' => true, 'created_at' => now()]);
        $this->assertUnknown($this->coverage($source));
    }

    public function test_destination_linked_to_another_source_product_is_unknown_even_outside_input(): void
    {
        $source = $this->source();
        $this->source('20', ['OTHER']);
        $this->target();
        Schema::drop('sku_mappings');
        Schema::create('sku_mappings', function (Blueprint $t) {
            $t->id(); $t->string('shopee_item_id'); $t->string('tiktok_product_id');
        });
        DB::table('sku_mappings')->insert(['shopee_item_id' => '20', 'tiktok_product_id' => '900']);
        $this->assertUnknown($this->coverage($source));
    }

    public function test_source_ownership_includes_inactive_cached_products_and_sku_matching_is_case_sensitive(): void
    {
        $source = $this->source();
        $this->target('900', [' RED ', 'blue']);
        $this->assertSame(['2', '3'], $this->coverage($source)['missing_model_ids']);
        $this->source('20', ['RED']);
        DB::table('shopee_product')->where('item_id', '20')->update(['is_active' => false]);
        $this->assertUnknown($this->coverage($source));
    }

    private function assertUnknown(array $result): void
    {
        $this->assertSame('unknown', $result['state']);
        $this->assertNull($result['missing_count']);
        $this->assertSame([], $result['missing_model_ids']);
        $this->assertNotEmpty($result['message']);
    }
}
