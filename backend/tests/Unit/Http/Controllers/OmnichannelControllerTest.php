<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\OmnichannelController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class OmnichannelControllerTest extends TestCase
{
    public function test_tiktok_generated_payload_weight_is_normalized_from_kilogram_to_gram(): void
    {
        $payload = $this->normalizePackageWeight([
            'package_weight' => [
                'unit' => 'KILOGRAM',
                'value' => '0.2',
            ],
        ]);

        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_weight_defaults_to_200_gram(): void
    {
        $payload = $this->normalizePackageWeight([]);

        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_weight_is_normalized_inside_nested_payload(): void
    {
        $payload = $this->normalizePackageWeight([
            'data' => [
                'product' => [
                    'package_weight' => [
                        'unit' => 'KILOGRAM',
                        'value' => '0',
                    ],
                ],
            ],
        ]);

        $this->assertSame('GRAM', $payload['data']['product']['package_weight']['unit']);
        $this->assertSame('200', $payload['data']['product']['package_weight']['value']);
        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_sku_weights_are_normalized_to_gram(): void
    {
        $payload = $this->normalizePackageWeight([
            'skus' => [
                [
                    'seller_sku' => 'SKU-1',
                    'sku_weight' => [
                        'unit' => 'KILOGRAM',
                        'value' => '0.07',
                    ],
                ],
                [
                    'seller_sku' => 'SKU-2',
                    'sku_weight' => [
                        'unit' => 'KG',
                        'value' => '0,2',
                    ],
                ],
            ],
        ]);

        $this->assertSame('GRAM', $payload['skus'][0]['sku_weight']['unit']);
        $this->assertSame('70', $payload['skus'][0]['sku_weight']['value']);
        $this->assertSame('GRAM', $payload['skus'][1]['sku_weight']['unit']);
        $this->assertSame('200', $payload['skus'][1]['sku_weight']['value']);
    }

    public function test_tiktok_generated_payload_missing_sku_weight_uses_product_weight(): void
    {
        $payload = $this->normalizePackageWeight([
            'package_weight' => [
                'unit' => 'KILOGRAM',
                'value' => '0.07',
            ],
            'skus' => [
                ['seller_sku' => 'NEW-SKU'],
            ],
        ]);

        $this->assertSame('GRAM', $payload['skus'][0]['sku_weight']['unit']);
        $this->assertSame('70', $payload['skus'][0]['sku_weight']['value']);
    }

    public function test_tiktok_generated_payload_dimensions_are_normalized_to_non_zero_centimeter(): void
    {
        $payload = $this->normalizeDimensions([
            'package_dimensions' => [
                'unit' => 'CENTIMETER',
                'height' => '0',
                'length' => '',
                'width' => '0',
            ],
            'skus' => [
                [
                    'sku_dimensions' => [
                        'unit' => 'CENTIMETER',
                        'height' => '0',
                        'length' => '0',
                        'width' => '0',
                    ],
                ],
            ],
        ]);

        $this->assertSame('CENTIMETER', $payload['package_dimensions']['unit']);
        $this->assertSame('1', $payload['package_dimensions']['height']);
        $this->assertSame('1', $payload['package_dimensions']['length']);
        $this->assertSame('1', $payload['package_dimensions']['width']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['height']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['length']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['width']);
    }

    public function test_tiktok_generated_payload_missing_sku_dimensions_uses_product_dimensions(): void
    {
        $payload = $this->normalizeDimensions([
            'package_dimensions' => [
                'unit' => 'CENTIMETER',
                'height' => '2',
                'length' => '3',
                'width' => '4',
            ],
            'skus' => [
                ['seller_sku' => 'NEW-SKU'],
            ],
        ]);

        $this->assertSame('CENTIMETER', $payload['skus'][0]['sku_dimensions']['unit']);
        $this->assertSame('2', $payload['skus'][0]['sku_dimensions']['height']);
        $this->assertSame('3', $payload['skus'][0]['sku_dimensions']['length']);
        $this->assertSame('4', $payload['skus'][0]['sku_dimensions']['width']);
    }

    public function test_shopee_bulk_candidates_include_only_blank_skus_and_use_internal_template(): void
    {
        $controller = new OmnichannelController();
        $this->assertTrue(method_exists($controller, 'shopeeMissingSkuBulkCandidates'));

        $candidates = $this->shopeeMissingSkuBulkCandidates(collect([
            (object) ['item_id' => '100', 'model_id' => '1', 'name' => 'Merah / L', 'model_sku' => ''],
            (object) ['item_id' => '100', 'model_id' => '2', 'name' => 'Biru', 'model_sku' => 'SKU-SUDAH-ADA'],
            (object) ['item_id' => '101', 'model_id' => '3', 'name' => 'Hitam', 'model_sku' => '   '],
        ]));

        $this->assertSame([
            ['item_id' => '100', 'model_id' => '1', 'model_name' => 'Merah / L', 'seller_sku' => 'INT-100-MERAH-L'],
            ['item_id' => '101', 'model_id' => '3', 'model_name' => 'Hitam', 'seller_sku' => 'INT-101-HITAM'],
        ], $candidates->all());
    }

    public function test_shopee_bulk_empty_sku_route_is_registered(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => in_array('POST', $route->methods(), true)
                && $route->uri() === 'api/sku-mapping/bulk-update-empty-shopee-variant-skus');

        $this->assertNotNull($route);
        $this->assertSame('App\\Http\\Controllers\\OmnichannelController@bulkUpdateShopeeEmptyVariantSkus', $route->getActionName());
    }
    public function test_tiktok_bulk_candidates_keep_only_shopee_skus_missing_from_tiktok(): void
    {
        $groups = $this->tiktokBulkMissingVariantCandidates(collect([
            (object) [
                'tiktok_product_id' => '900',
                'product_name' => 'Produk A',
                'shopee_item_id' => '100',
                'shopee_model_id' => '1',
                'shopee_model_sku' => 'SH-RED',
                'shopee_variant_name' => 'Merah',
                'shopee_image_url' => 'https://cdn.example/red.jpg',
                'tiktok_seller_skus' => ['SH-BLUE'],
                'tiktok_skus' => [['seller_sku' => 'SH-BLUE', 'sku_id' => 'TT-2']],
            ],
            (object) [
                'tiktok_product_id' => '900',
                'product_name' => 'Produk A',
                'shopee_item_id' => '100',
                'shopee_model_id' => '2',
                'shopee_model_sku' => 'sh-blue',
                'shopee_variant_name' => 'Biru',
                'shopee_image_url' => 'https://cdn.example/blue.jpg',
                'tiktok_seller_skus' => ['SH-BLUE'],
                'tiktok_skus' => [['seller_sku' => 'SH-BLUE', 'sku_id' => 'TT-2']],
            ],
        ]));

        $this->assertSame(['SH-RED'], $groups->first()['variants']->pluck('seller_sku')->all());
        $this->assertSame(['SH-BLUE'], $groups->first()['mapping_only_variants']->pluck('seller_sku')->all());
        $this->assertSame('TT-2', $groups->first()['mapping_only_variants']->first()['tiktok_sku_id']);
        $this->assertSame('SKU sudah ada di TikTok; mapping belum tersambung.', $groups->first()['mapping_only_variants']->first()['reason']);
    }

    public function test_tiktok_bulk_candidates_deduplicate_missing_shopee_seller_skus_per_tiktok_product(): void
    {
        $groups = $this->tiktokBulkMissingVariantCandidates(collect([
            (object) [
                'tiktok_product_id' => '900',
                'product_name' => 'Produk A',
                'shopee_item_id' => '100',
                'shopee_model_id' => '1',
                'shopee_model_sku' => 'SH-RED',
                'shopee_variant_name' => 'Merah Pertama',
                'shopee_image_url' => 'https://cdn.example/red-first.jpg',
                'tiktok_seller_skus' => [],
                'tiktok_skus' => [],
            ],
            (object) [
                'tiktok_product_id' => '900',
                'product_name' => 'Produk A',
                'shopee_item_id' => '100',
                'shopee_model_id' => '2',
                'shopee_model_sku' => ' sh-red ',
                'shopee_variant_name' => 'Merah Duplikat',
                'shopee_image_url' => 'https://cdn.example/red-duplicate.jpg',
                'tiktok_seller_skus' => [],
                'tiktok_skus' => [],
            ],
        ]));

        $this->assertCount(1, $groups->first()['variants']);
        $this->assertSame(['SH-RED'], $groups->first()['variants']->pluck('seller_sku')->all());
        $this->assertSame('1', $groups->first()['variants']->first()['shopee_model_id']);
    }

    public function test_linked_tiktok_seller_sku_lookup_ignores_variant_name_mismatch(): void
    {
        $match = $this->linkedTiktokSellerSkuMatch([
            'product_groups' => [
                '900' => [
                    'rows_by_seller_sku' => [
                        'sh blue' => (object) ['product_id' => '900', 'sku_id' => 'TT-2', 'sku_name' => 'Nama TikTok Lama', 'seller_sku' => 'SH-BLUE'],
                    ],
                ],
            ],
        ], '900', 'sh-blue');

        $this->assertSame('TT-2', $match->sku_id);
        $this->assertSame('SH-BLUE', $match->seller_sku);
    }

    public function test_tiktok_bulk_candidate_row_uses_suggested_tiktok_product_from_sku_mapping(): void
    {
        $row = $this->tiktokBulkCandidateRowFromSkuMapping([
            'status' => 'tiktok_missing',
            'product_name' => 'Produk Shopee',
            'shopee' => ['item_id' => '100', 'model_id' => '1', 'seller_sku' => 'SH-RED', 'variant_name' => 'Merah', 'image_url' => '/cached/red.jpg'],
            'tiktok' => ['product_id' => '900', 'product_name' => 'Produk TikTok', 'source' => 'suggested_product'],
        ]);

        $this->assertSame('900', $row->tiktok_product_id);
        $this->assertSame('100', $row->shopee_item_id);
        $this->assertSame('SH-RED', $row->shopee_model_sku);
        $this->assertSame('/cached/red.jpg', $row->shopee_image_url);
    }

    public function test_tiktok_majority_price_returns_the_most_frequent_price(): void
    {
        $result = $this->tiktokMajorityPrice([
            ['sale_price' => '50000'],
            ['sale_price' => '50000'],
            ['sale_price' => '19000'],
        ]);

        $this->assertSame(['price' => 50000, 'reason' => null], $result);
    }

    public function test_tiktok_majority_price_rejects_a_tie(): void
    {
        $result = $this->tiktokMajorityPrice([
            ['sale_price' => '50000'],
            ['sale_price' => '19000'],
        ]);

        $this->assertSame(['price' => null, 'reason' => 'Harga TikTok mayoritas seri.'], $result);
    }
    public function test_bulk_tiktok_image_refresh_replaces_the_identity_cache_file(): void
    {
        $oldImage = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        $newImage = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL3pAAAAABJRU5ErkJggg==');
        Http::fake([
            'https://cdn.example/old.jpg' => Http::response($oldImage, 200, ['Content-Type' => 'image/gif']),
            'https://cdn.example/new.png' => Http::response($newImage, 200, ['Content-Type' => 'image/png']),
        ]);

        $oldCachedUrl = $this->refreshMarketplaceImageUrl('https://cdn.example/old.jpg', 'shopee', '100', '7');
        $newCachedUrl = $this->refreshMarketplaceImageUrl('https://cdn.example/new.png', 'shopee', '100', '7');
        $oldPath = $this->cachedImagePath($oldCachedUrl);
        $newPath = $this->cachedImagePath($newCachedUrl);

        try {
            $this->assertNotSame($oldCachedUrl, $newCachedUrl);
            $this->assertFileDoesNotExist($oldPath);
            $this->assertSame($newImage, file_get_contents($newPath));
            Http::assertSentCount(2);
        } finally {
            @unlink($oldPath);
            @unlink($newPath);
        }
    }

    public function test_forced_shopee_image_refresh_keeps_existing_cache_when_download_fails(): void
    {
        Schema::dropIfExists('shopee_product_image');
        Schema::create('shopee_product_image', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('model_id')->nullable();
            $table->string('image_url');
            $table->timestamps();
        });
        DB::table('shopee_product_image')->insert([
            'item_id' => 100,
            'model_id' => '1',
            'image_url' => '/cached-images/marketplace-images/shopee/existing.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake([
            'https://cdn.example/unavailable.jpg' => Http::response('', 503),
        ]);

        try {
            $this->invokeControllerMethod('storeShopeeImages', [
                100,
                '1',
                ['https://cdn.example/unavailable.jpg'],
                true,
            ]);

            $this->assertSame([
                '/cached-images/marketplace-images/shopee/existing.jpg',
            ], DB::table('shopee_product_image')->orderBy('id')->pluck('image_url')->all());
        } finally {
            Schema::dropIfExists('shopee_product_image');
        }
    }

    public function test_forced_shopee_image_refresh_rejects_invalid_image_body_and_keeps_existing_cache_file(): void
    {
        Schema::dropIfExists('shopee_product_image');
        Schema::create('shopee_product_image', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('model_id')->nullable();
            $table->string('image_url');
            $table->timestamps();
        });
        $existingUrl = '/cached-images/marketplace-images/shopee/existing.jpg';
        $existingPath = $this->cachedImagePath($existingUrl);
        @mkdir(dirname($existingPath), 0775, true);
        file_put_contents($existingPath, 'existing-image');
        DB::table('shopee_product_image')->insert([
            'item_id' => 100,
            'model_id' => '1',
            'image_url' => $existingUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake([
            'https://cdn.example/error-page.jpg' => Http::response('<html>gateway error</html>', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        try {
            $this->invokeControllerMethod('storeShopeeImages', [
                100,
                '1',
                ['https://cdn.example/error-page.jpg'],
                true,
            ]);

            $this->assertSame([$existingUrl], DB::table('shopee_product_image')->orderBy('id')->pluck('image_url')->all());
            $this->assertSame('existing-image', file_get_contents($existingPath));
        } finally {
            @unlink($existingPath);
            Schema::dropIfExists('shopee_product_image');
        }
    }

    public function test_marketplace_image_cache_rejects_invalid_success_body(): void
    {
        $sourceUrl = 'https://cdn.example/not-an-image.jpg';
        $cachedPath = storage_path('app/public/marketplace-images/shopee/'.sha1('shopee|100|1|'.$sourceUrl).'.jpg');
        Http::fake([
            $sourceUrl => Http::response('<html>gateway error</html>', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        try {
            $cachedUrl = $this->invokeControllerMethod('cacheMarketplaceImageUrl', [$sourceUrl, 'shopee', '100', '1']);

            $this->assertNull($cachedUrl);
            $this->assertFileDoesNotExist($cachedPath);
        } finally {
            @unlink($cachedPath);
        }
    }

    public function test_forced_shopee_image_refresh_discards_staging_files_when_any_source_fails(): void
    {
        Schema::dropIfExists('shopee_product_image');
        Schema::create('shopee_product_image', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('model_id')->nullable();
            $table->string('image_url');
            $table->timestamps();
        });
        $validSource = 'https://cdn.example/valid.png';
        $existingUrl = '/cached-images/marketplace-images/shopee/'.sha1('shopee|100|1|'.$validSource).'.png';
        $existingPath = $this->cachedImagePath($existingUrl);
        @mkdir(dirname($existingPath), 0775, true);
        file_put_contents($existingPath, 'existing-image');
        DB::table('shopee_product_image')->insert([
            'item_id' => 100,
            'model_id' => '1',
            'image_url' => $existingUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake([
            $validSource => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL3pAAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/png']),
            'https://cdn.example/unavailable.jpg' => Http::response('', 503),
        ]);

        try {
            $cacheFilesBefore = glob(dirname($existingPath).DIRECTORY_SEPARATOR.'*') ?: [];
            $this->invokeControllerMethod('storeShopeeImages', [
                100,
                '1',
                [$validSource, 'https://cdn.example/unavailable.jpg'],
                true,
            ]);

            $this->assertSame([$existingUrl], DB::table('shopee_product_image')->orderBy('id')->pluck('image_url')->all());
            $this->assertSame('existing-image', file_get_contents($existingPath));
            $this->assertSame($cacheFilesBefore, glob(dirname($existingPath).DIRECTORY_SEPARATOR.'*') ?: []);
        } finally {
            @unlink($existingPath);
            Schema::dropIfExists('shopee_product_image');
        }
    }

    public function test_forced_shopee_image_refresh_preserves_existing_cache_when_database_swap_fails(): void
    {
        Schema::dropIfExists('shopee_product_image');
        Schema::create('shopee_product_image', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('model_id')->nullable();
            $table->string('image_url');
            $table->string('write_guard');
            $table->timestamps();
        });
        $validSource = 'https://cdn.example/valid.png';
        $existingUrl = '/cached-images/marketplace-images/shopee/'.sha1('shopee|100|1|'.$validSource).'.png';
        $existingPath = $this->cachedImagePath($existingUrl);
        @mkdir(dirname($existingPath), 0775, true);
        file_put_contents($existingPath, 'existing-image');
        DB::table('shopee_product_image')->insert([
            'item_id' => 100,
            'model_id' => '1',
            'image_url' => $existingUrl,
            'write_guard' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake([
            $validSource => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL3pAAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/png']),
        ]);

        try {
            $cacheFilesBefore = glob(dirname($existingPath).DIRECTORY_SEPARATOR.'*') ?: [];
            $this->invokeControllerMethod('storeShopeeImages', [100, '1', [$validSource], true]);

            $this->assertSame([$existingUrl], DB::table('shopee_product_image')->orderBy('id')->pluck('image_url')->all());
            $this->assertSame('existing-image', file_get_contents($existingPath));
            $this->assertSame($cacheFilesBefore, glob(dirname($existingPath).DIRECTORY_SEPARATOR.'*') ?: []);
        } finally {
            @unlink($existingPath);
            Schema::dropIfExists('shopee_product_image');
        }
    }

    public function test_bulk_tiktok_missing_variant_routes_are_registered(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $preview = $routes->first(fn ($route) => in_array('GET', $route->methods(), true)
            && $route->uri() === 'api/tiktok/bulk-missing-variants');
        $submit = $routes->first(fn ($route) => in_array('POST', $route->methods(), true)
            && $route->uri() === 'api/tiktok/bulk-missing-variants/submit');

        $this->assertNotNull($preview);
        $this->assertSame('App\\Http\\Controllers\\OmnichannelController@bulkTiktokMissingVariantsPreview', $preview->getActionName());
        $this->assertNotNull($submit);
        $this->assertSame('App\\Http\\Controllers\\OmnichannelController@bulkSubmitTiktokMissingVariants', $submit->getActionName());
    }

    public function test_tiktok_variant_reconciliation_routes_are_registered(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $this->assertNotNull($routes->first(fn ($route) => in_array('GET', $route->methods(), true)
            && $route->uri() === 'api/tiktok/variant-reconciliation/products'));
        $this->assertNotNull($routes->first(fn ($route) => in_array('GET', $route->methods(), true)
            && $route->uri() === 'api/tiktok/variant-reconciliation/preview'));
        $this->assertNotNull($routes->first(fn ($route) => in_array('POST', $route->methods(), true)
            && $route->uri() === 'api/tiktok/variant-reconciliation/submit'));
    }

    public function test_tiktok_variant_reconciliation_canonical_sku_reuses_shopee_template_normalizer(): void
    {
        $canonical = $this->invokeControllerMethod('canonicalShopeeVariantSellerSku', ['100', 'Rose_Gold']);
        $template = $this->invokeControllerMethod('buildShopeeTemplateSellerSku', ['100', 'Rose_Gold']);

        $this->assertSame('INT-100-ROSE_GOLD', $canonical);
        $this->assertSame($template, $canonical);
    }

    public function test_variant_reconciliation_preview_returns_only_selected_linked_product_rows(): void
    {
        $snapshot = $this->invokeControllerMethod('buildTiktokVariantReconciliationPreview', [[
            'shopee_item_id' => '100',
            'tiktok_product_id' => '900',
            'shopee_models' => [['model_id' => '1', 'name' => 'Merah', 'model_sku' => 'INT-100-OLD', 'stock_qty' => 2, 'image_url' => '/cached-images/red.jpg']],
            'tiktok_skus' => [['sku_id' => 'tt-1', 'seller_sku' => 'INT-100-OLD', 'sku_name' => 'Merah', 'stock_qty' => 1, 'image_url' => 'tos/red']],
        ]]);

        $this->assertSame('100', $snapshot['product']['shopee_item_id']);
        $this->assertSame('900', $snapshot['product']['tiktok_product_id']);
        $this->assertNotSame('', $snapshot['revision']);
        $this->assertCount(1, $snapshot['rows']);
    }

    public function test_variant_reconciliation_products_returns_linked_stock_master_choices(): void
    {
        Schema::dropIfExists('sku_mappings');
        Schema::dropIfExists('stock_master');
        Schema::create('stock_master', function (Blueprint $table): void {
            $table->id();
            $table->string('product_name')->nullable();
            $table->string('shopee_product_id')->nullable();
            $table->string('tiktok_product_id')->nullable();
        });
        DB::table('stock_master')->insert([
            'product_name' => 'Produk Merah',
            'shopee_product_id' => '100',
            'tiktok_product_id' => '900',
        ]);

        try {
            $response = (new OmnichannelController())->tiktokVariantReconciliationProducts()->getData(true);

            $this->assertSame([[
                'shopee_item_id' => '100',
                'tiktok_product_id' => '900',
                'product_name' => 'Produk Merah',
            ]], $response['items']);
        } finally {
            Schema::dropIfExists('stock_master');
        }
    }

    public function test_variant_reconciliation_preview_rejects_unlinked_ids_before_refresh(): void
    {
        $controller = new class extends OmnichannelController {
            public bool $refreshInvoked = false;

            protected function tiktokVariantReconciliationLinkedProductChoice(string $shopeeItemId, string $tiktokProductId): ?array
            {
                return null;
            }

            protected function refreshTiktokVariantReconciliationShopeeItem(string $shopeeItemId): array
            {
                $this->refreshInvoked = true;

                return ['status' => 'ok'];
            }
        };

        try {
            $controller->tiktokVariantReconciliationPreview(
                \Illuminate\Http\Request::create('/api/tiktok/variant-reconciliation/preview', 'GET', [
                    'shopee_item_id' => '100',
                    'tiktok_product_id' => '900',
                ])
            );
            $this->fail('Expected unlinked product IDs to be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertFalse($controller->refreshInvoked);
    }

    public function test_variant_reconciliation_classifies_outdated_sku_and_tiktok_differences(): void
    {
        $result = $this->invokeControllerMethod('classifyTiktokVariantReconciliation', [[
            ['model_id' => 'model-1', 'name' => 'Rose Gold', 'model_sku' => 'INT-100-OLD', 'stock_qty' => 5, 'image_url' => '/cached-images/rose.jpg'],
        ], [
            ['sku_id' => 'tt-1', 'seller_sku' => 'INT-100-OLD', 'sku_name' => 'Old', 'stock_qty' => 1, 'image_url' => 'tos/old'],
        ], '100']);

        $this->assertSame('INT-100-ROSE-GOLD', $result['rows'][0]['target']['seller_sku']);
        $this->assertContains('shopee_sku_outdated', $result['rows'][0]['actions']);
        $this->assertContains('tiktok_variant_outdated', $result['rows'][0]['actions']);
    }

    public function test_tiktok_variant_reconciliation_classifies_duplicate_templates_as_manual_review(): void
    {
        $result = $this->invokeControllerMethod('classifyTiktokVariantReconciliation', [[
            ['model_id' => 'model-1', 'name' => 'Rose Gold', 'model_sku' => 'LEGACY-1', 'stock_qty' => 5, 'image_url' => '/cached-images/rose.jpg'],
            ['model_id' => 'model-2', 'name' => 'Rose / Gold', 'model_sku' => 'LEGACY-2', 'stock_qty' => 5, 'image_url' => '/cached-images/rose-two.jpg'],
        ], [], '100']);

        $this->assertSame(['manual_review', 'manual_review'], array_column($result['rows'], 'classification'));
        $this->assertSame(['INT-100-ROSE-GOLD', 'INT-100-ROSE-GOLD'], array_column(array_column($result['rows'], 'target'), 'seller_sku'));
    }

    public function test_tiktok_variant_reconciliation_classifies_ambiguous_name_matches_as_manual_review(): void
    {
        $result = $this->invokeControllerMethod('classifyTiktokVariantReconciliation', [[
            ['model_id' => 'model-1', 'name' => 'Red', 'model_sku' => 'LEGACY-RED', 'stock_qty' => 5, 'image_url' => '/cached-images/red.jpg'],
        ], [
            ['sku_id' => 'tt-red-1', 'seller_sku' => 'OTHER-RED-1', 'sku_name' => 'Red', 'stock_qty' => 5, 'image_url' => 'tos/red-1'],
            ['sku_id' => 'tt-red-2', 'seller_sku' => 'OTHER-RED-2', 'sku_name' => 'Red', 'stock_qty' => 5, 'image_url' => 'tos/red-2'],
        ], '100']);

        $this->assertSame('manual_review', $result['rows'][0]['classification']);
        $this->assertSame(['manual_review'], $result['rows'][0]['actions']);
    }

    public function test_tiktok_variant_reconciliation_classifies_only_selected_prefix_orphans(): void
    {
        $result = $this->invokeControllerMethod('classifyTiktokVariantReconciliation', [[], [
            ['sku_id' => 'tt-owned', 'seller_sku' => 'INT-100-STALE', 'sku_name' => 'Stale', 'stock_qty' => 1, 'image_url' => 'tos/stale'],
            ['sku_id' => 'tt-foreign', 'seller_sku' => 'INT-999-FOREIGN', 'sku_name' => 'Foreign', 'stock_qty' => 1, 'image_url' => 'tos/foreign'],
        ], '100']);

        $this->assertSame('tiktok_orphan', $result['rows'][0]['classification']);
        $this->assertSame('manual_review', $result['rows'][1]['classification']);
        $this->assertNotContains('tiktok_orphan', $result['rows'][1]['actions']);
    }

    public function test_tiktok_variant_reconciliation_does_not_orphan_a_tiktok_sku_for_image_missing_shopee_model(): void
    {
        $result = $this->invokeControllerMethod('classifyTiktokVariantReconciliation', [[
            ['model_id' => 'model-1', 'name' => 'Red', 'model_sku' => 'INT-100-RED', 'stock_qty' => 5, 'image_url' => ''],
        ], [
            ['sku_id' => 'tt-red', 'seller_sku' => 'INT-100-RED', 'sku_name' => 'Red', 'stock_qty' => 5, 'image_url' => 'tos/red'],
        ], '100']);

        $this->assertSame('manual_review', $result['rows'][0]['classification']);
        $this->assertSame('manual_review', $result['rows'][1]['classification']);
        $this->assertNotContains('tiktok_orphan', $result['rows'][1]['actions']);
    }

    public function test_tiktok_variant_reconciliation_treats_mismatched_shopee_product_as_manual_review(): void
    {
        $result = $this->invokeControllerMethod('classifyTiktokVariantReconciliation', [[
            ['item_id' => '999', 'model_id' => 'model-1', 'name' => 'Red', 'model_sku' => 'INT-100-RED', 'stock_qty' => 5, 'image_url' => '/cached-images/red.jpg'],
        ], [
            ['sku_id' => 'tt-red', 'seller_sku' => 'INT-100-RED', 'sku_name' => 'Red', 'stock_qty' => 5, 'image_url' => '/cached-images/red.jpg'],
        ], '100']);

        $this->assertSame('manual_review', $result['rows'][0]['classification']);
        $this->assertSame('manual_review', $result['rows'][1]['classification']);
        $this->assertNotContains('tiktok_orphan', $result['rows'][1]['actions']);
    }

    public function test_tiktok_variant_reconciliation_keeps_unsafe_associations_manual_review(): void
    {
        $result = $this->invokeControllerMethod('classifyTiktokVariantReconciliation', [[
            ['model_id' => 'model-1', 'name' => 'Red', 'model_sku' => 'LEGACY-RED', 'stock_qty' => 5, 'image_url' => ''],
            ['model_id' => '', 'name' => 'Blue', 'model_sku' => 'LEGACY-BLUE', 'stock_qty' => 4, 'image_url' => '/cached-images/blue.jpg'],
            ['model_id' => 'model-3', 'name' => 'Black', 'model_sku' => 'LEGACY-BLACK', 'stock_qty' => 3, 'image_url' => '/cached-images/black.jpg'],
        ], [
            ['sku_id' => 'tt-red-1', 'seller_sku' => 'OTHER-RED-1', 'sku_name' => 'Red', 'stock_qty' => 5, 'image_url' => 'tos/red-1'],
            ['sku_id' => 'tt-red-2', 'seller_sku' => 'OTHER-RED-2', 'sku_name' => 'Red', 'stock_qty' => 5, 'image_url' => 'tos/red-2'],
            ['sku_id' => 'tt-foreign', 'seller_sku' => 'INT-999-FOREIGN', 'sku_name' => 'Foreign', 'stock_qty' => 1, 'image_url' => 'tos/foreign'],
        ], '100']);

        $this->assertSame('manual_review', $result['rows'][0]['classification']);
        $this->assertSame('manual_review', $result['rows'][1]['classification']);
        $this->assertSame('manual_review', $result['rows'][2]['classification']);
        $this->assertSame('manual_review', $result['rows'][3]['classification']);
        $this->assertNotContains('tiktok_orphan', $result['rows'][3]['actions']);
    }

    public function test_bulk_tiktok_missing_variant_manual_price_must_be_positive(): void
    {
        $this->postJson('/api/tiktok/bulk-missing-variants/submit', [
            'scope' => 'selected',
            'product_ids' => ['900'],
            'price_mode' => 'manual',
            'manual_price' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('manual_price');
    }

    public function test_existing_tiktok_product_requires_readable_detail_before_mutation(): void
    {
        $this->assertTrue($this->hasControllerMethod('canSubmitTiktokProductMutation'));

        $canSubmit = $this->invokeControllerMethod('canSubmitTiktokProductMutation', [
            ['product_id' => '123'],
            null,
        ]);

        $this->assertFalse($canSubmit);
    }

    public function test_bulk_tiktok_audit_payload_redacts_signing_credentials(): void
    {
        $this->assertTrue($this->hasControllerMethod('redactBulkTiktokAuditPayload'));

        $payload = $this->invokeControllerMethod('redactBulkTiktokAuditPayload', [[
            'request' => [
                'query' => [
                    'access_token' => 'secret',
                    'sign' => 'signature',
                    'shop_cipher' => 'cipher',
                ],
            ],
            'response' => ['code' => 400, 'message' => 'invalid'],
        ]]);

        $this->assertArrayNotHasKey('access_token', $payload['request']['query']);
        $this->assertArrayNotHasKey('sign', $payload['request']['query']);
        $this->assertSame('invalid', $payload['response']['message']);
    }

    public function test_bulk_tiktok_outcome_keeps_sku_and_marks_unverified(): void
    {
        $this->assertTrue($this->hasControllerMethod('bulkTiktokVariantOutcome'));

        $outcome = $this->invokeControllerMethod('bulkTiktokVariantOutcome', [[
            'seller_sku' => 'SKU-ROSE',
        ], 'submitted_unverified', 48000, 'SKU belum muncul pada katalog TikTok terbaru.']);

        $this->assertSame([
            'seller_sku' => 'SKU-ROSE',
            'price' => 48000,
            'status' => 'submitted_unverified',
            'reason' => 'SKU belum muncul pada katalog TikTok terbaru.',
        ], $outcome);
    }

    public function test_bulk_tiktok_verification_requires_fresh_catalogue_sku(): void
    {
        $this->assertTrue($this->hasControllerMethod('bulkTiktokVerificationOutcome'));

        $missingSku = $this->invokeControllerMethod('bulkTiktokVerificationOutcome', [[
            'status' => 'ok',
            'message' => 'Produk TikTok dipilih berhasil disinkronkan: 5 varian aktif.',
        ], false]);
        $syncFailure = $this->invokeControllerMethod('bulkTiktokVerificationOutcome', [[
            'status' => 'error',
            'message' => 'Detail produk TikTok tidak berhasil diambil.',
        ], false]);
        $verified = $this->invokeControllerMethod('bulkTiktokVerificationOutcome', [[
            'status' => 'ok',
        ], true]);

        $this->assertSame([
            'status' => 'submitted_unverified',
            'reason' => 'SKU belum muncul pada katalog TikTok terbaru.',
        ], $missingSku);
        $this->assertSame([
            'status' => 'submitted_unverified',
            'reason' => 'Detail produk TikTok tidak berhasil diambil.',
        ], $syncFailure);
        $this->assertSame(['status' => 'updated', 'reason' => null], $verified);
    }

    public function test_bulk_tiktok_variant_image_upload_uses_attribute_image(): void
    {
        $this->assertTrue($this->hasControllerMethod('bulkTiktokVariantImageUseCase'));

        $this->assertSame('ATTRIBUTE_IMAGE', $this->invokeControllerMethod('bulkTiktokVariantImageUseCase', []));
    }

    public function test_tiktok_main_images_for_mutation_use_structured_tiktok_uris_only(): void
    {
        $this->assertTrue($this->hasControllerMethod('normalizeTiktokMainImagesForMutation'));

        $images = $this->invokeControllerMethod('normalizeTiktokMainImagesForMutation', [[
            'main_images' => [
                ['uri' => 'tos-alisg-i-aphluv4xwc-sg/existing-image'],
                'https://p16-oec-sg.ibyteimg.com/tos-alisg-i-aphluv4xwc-sg/cdn-image~tplv-origin.jpeg?x=1',
                '/cached-images/marketplace-images/shopee/invalid.jpg',
                'tos-alisg-i-aphluv4xwc-sg/existing-image',
            ],
        ]]);

        $this->assertSame([
            ['uri' => 'tos-alisg-i-aphluv4xwc-sg/existing-image'],
            ['uri' => 'tos-alisg-i-aphluv4xwc-sg/cdn-image'],
        ], $images);
    }

    public function test_tiktok_variant_mutation_sku_rows_use_structured_prices(): void
    {
        $this->assertTrue($this->hasControllerMethod('buildTiktokVariantMutationSkuRows'));

        $rows = $this->invokeControllerMethod('buildTiktokVariantMutationSkuRows', [
            [
                'skus' => [[
                    'id' => 'existing-1',
                    'seller_sku' => 'EXISTING',
                    'sku_name' => 'Hitam',
                    'price' => '48000',
                    'stock' => 3,
                ]],
            ],
            [
                'source' => ['price' => 48000],
                'target' => [
                    'variant_name' => 'Rose Gold',
                    'seller_sku' => 'NEW',
                    'stock_qty' => 0,
                ],
            ],
            (object) ['variant_name' => 'Fallback', 'internal_sku' => 'FALLBACK'],
            'tos-alisg-i-aphluv4xwc-sg/new-image',
        ]);

        $expectedPrice = [
            'currency' => 'IDR',
            'sale_price' => '48000',
            'tax_exclusive_price' => '48000',
            'amount' => '48000',
        ];

        $this->assertSame($expectedPrice, $rows[0]['price']);
        $this->assertSame($expectedPrice, $rows[1]['price']);
    }

    public function test_tiktok_mutation_description_preserves_existing_detail_only(): void
    {
        $this->assertTrue($this->hasControllerMethod('tiktokMutationDescription'));

        $description = $this->invokeControllerMethod('tiktokMutationDescription', [[
            'description' => '  Deskripsi TikTok asli.  ',
        ]]);
        $blank = $this->invokeControllerMethod('tiktokMutationDescription', [[
            'description' => '   ',
        ]]);

        $this->assertSame('Deskripsi TikTok asli.', $description);
        $this->assertSame('', $blank);
    }

    public function test_tiktok_existing_product_partial_edit_batch_mutation_appends_all_new_skus(): void
    {
        $this->assertTrue($this->hasControllerMethod('buildTiktokExistingProductPartialEditBatchMutation'));

        $mutation = $this->invokeControllerMethod('buildTiktokExistingProductPartialEditBatchMutation', [
            ['product_id' => 'product-1'],
            ['id' => 'product-1', 'skus' => [$this->tiktokPartialEditFixtureSku()]],
            [
                [
                    'seller_sku' => 'NEW-RED',
                    'variant_name' => 'Merah',
                    'stock_qty' => 2,
                    'price' => 48000,
                    'uploaded_image_uri' => 'tos-alisg-i-aphluv4xwc-sg/red',
                ],
                [
                    'seller_sku' => 'NEW-BLUE',
                    'variant_name' => 'Biru',
                    'stock_qty' => 3,
                    'price' => 48000,
                    'uploaded_image_uri' => 'tos-alisg-i-aphluv4xwc-sg/blue',
                ],
            ],
        ]);

        $this->assertSame('/product/202509/products/product-1/partial_edit', $mutation['path']);
        $this->assertSame('LISTING', $mutation['body']['save_mode']);
        $this->assertCount(3, $mutation['body']['skus']);
        $this->assertSame('EXISTING', $mutation['body']['skus'][0]['seller_sku']);
        $this->assertSame('NEW-RED', $mutation['body']['skus'][1]['seller_sku']);
        $this->assertSame('Merah', $mutation['body']['skus'][1]['sales_attributes'][0]['value_name']);
        $this->assertSame('NEW-BLUE', $mutation['body']['skus'][2]['seller_sku']);
        $this->assertSame('Biru', $mutation['body']['skus'][2]['sales_attributes'][0]['value_name']);
        $this->assertSame(['type' => 'NONE'], $mutation['body']['skus'][2]['pre_sale']);
    }

    public function test_tiktok_existing_product_partial_edit_batch_mutation_rejects_duplicate_seller_sku(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->invokeControllerMethod('buildTiktokExistingProductPartialEditBatchMutation', [
            ['product_id' => 'product-1'],
            ['id' => 'product-1', 'skus' => [$this->tiktokPartialEditFixtureSku()]],
            [
                [
                    'seller_sku' => 'NEW-DUPLICATE',
                    'variant_name' => 'Merah',
                    'stock_qty' => 2,
                    'price' => 48000,
                    'uploaded_image_uri' => 'tos-alisg-i-aphluv4xwc-sg/red',
                ],
                [
                    'seller_sku' => 'NEW-DUPLICATE',
                    'variant_name' => 'Biru',
                    'stock_qty' => 3,
                    'price' => 48000,
                    'uploaded_image_uri' => 'tos-alisg-i-aphluv4xwc-sg/blue',
                ],
            ],
        ]);
    }

    public function test_bulk_tiktok_batch_submission_sends_one_payload_and_verifies_all_prepared_skus(): void
    {
        $controller = new class extends OmnichannelController {
            public array $submissions = [];
            public array $verificationCalls = [];

            protected function submitTiktokBulkPartialEditPayload(string $path, array $payload, array $context): array
            {
                $this->submissions[] = compact('path', 'payload', 'context');

                return ['code' => 0, 'message' => 'Success'];
            }

            protected function verifyBulkTiktokVariantGroup(string $productId, array $sellerSkus): array
            {
                $this->verificationCalls[] = compact('productId', 'sellerSkus');

                return [
                    'sync' => ['status' => 'ok'],
                    'found_seller_skus' => array_fill_keys($sellerSkus, true),
                ];
            }
        };
        $method = (new ReflectionClass($controller))->getMethod('submitPreparedBulkTiktokVariantBatch');
        $method->setAccessible(true);

        $result = $method->invoke($controller, 'product-1', [
            'id' => 'product-1',
            'skus' => [$this->tiktokPartialEditFixtureSku()],
        ], [
            [
                'seller_sku' => 'NEW-RED',
                'uploaded_image_uri' => 'tos-alisg-i-aphluv4xwc-sg/red',
                'variant' => ['variant_name' => 'Merah'],
            ],
            [
                'seller_sku' => 'NEW-BLUE',
                'uploaded_image_uri' => 'tos-alisg-i-aphluv4xwc-sg/blue',
                'variant' => ['variant_name' => 'Biru'],
            ],
        ], 48000, (object) ['shop_id' => 'shop-1', 'shop_cipher' => 'cipher-1'], 'token-1');

        $this->assertTrue($result['mutation']['ok']);
        $this->assertCount(1, $controller->submissions);
        $this->assertSame('/product/202509/products/product-1/partial_edit', $controller->submissions[0]['path']);
        $this->assertSame(['EXISTING', 'NEW-RED', 'NEW-BLUE'], array_column($controller->submissions[0]['payload']['skus'], 'seller_sku'));
        $this->assertSame(['NEW-RED', 'NEW-BLUE'], $controller->verificationCalls[0]['sellerSkus']);
        $this->assertSame(['NEW-RED', 'NEW-BLUE'], array_keys($result['verification']['found_seller_skus']));
    }

    public function test_tiktok_existing_product_partial_edit_mutation_preserves_sku_contract(): void
    {
        $this->assertTrue($this->hasControllerMethod('buildTiktokExistingProductPartialEditMutation'));

        $mutation = $this->invokeControllerMethod('buildTiktokExistingProductPartialEditMutation', [
            ['product_id' => 'product-1'],
            [
                'id' => 'product-1',
                'skus' => [[
                    'id' => 'existing-1',
                    'seller_sku' => 'EXISTING',
                    'price' => [
                        'currency' => 'IDR',
                        'sale_price' => '48000',
                        'tax_exclusive_price' => '48000',
                    ],
                    'inventory' => [[
                        'quantity' => 3,
                        'warehouse_id' => 'warehouse-1',
                    ]],
                    'sales_attributes' => [[
                        'id' => '100000',
                        'name' => 'Warna',
                        'value_id' => 'black',
                        'value_name' => 'Hitam',
                        'sku_img' => ['uri' => 'tos-alisg-i-aphluv4xwc-sg/black'],
                    ]],
                    'sku_weight' => ['unit' => 'GRAM', 'value' => '100'],
                    'sku_dimensions' => [
                        'unit' => 'CENTIMETER',
                        'height' => '1',
                        'length' => '1',
                        'width' => '1',
                    ],
                ]],
            ],
            [
                'source' => ['price' => 48000],
                'target' => [
                    'variant_name' => 'Rose Gold',
                    'seller_sku' => 'NEW',
                    'stock_qty' => 2,
                ],
            ],
            (object) ['variant_name' => 'Rose Gold', 'internal_sku' => 'NEW'],
            'tos-alisg-i-aphluv4xwc-sg/rose-gold',
        ]);

        $this->assertSame('/product/202509/products/product-1/partial_edit', $mutation['path']);
        $this->assertSame('LISTING', $mutation['body']['save_mode']);
        $this->assertSame('existing-1', $mutation['body']['skus'][0]['id']);
        $this->assertSame('Rose Gold', $mutation['body']['skus'][1]['sales_attributes'][0]['value_name']);
        $this->assertSame('tos-alisg-i-aphluv4xwc-sg/rose-gold', $mutation['body']['skus'][1]['sales_attributes'][0]['sku_img']['uri']);
        $this->assertSame('warehouse-1', $mutation['body']['skus'][1]['inventory'][0]['warehouse_id']);
        $this->assertSame(['type' => 'NONE'], $mutation['body']['skus'][0]['pre_sale']);
        $this->assertSame(['type' => 'NONE'], $mutation['body']['skus'][1]['pre_sale']);
        $this->assertSame('48000', $mutation['body']['skus'][1]['price']['sale_price']);
    }

    public function test_tiktok_existing_product_partial_edit_mutation_rejects_variant_attribute_without_identity(): void
    {
        $invalidSku = $this->tiktokPartialEditFixtureSku([
            'sales_attributes' => [[
                'name' => 'Warna',
                'value_name' => 'Hitam',
                'sku_img' => ['uri' => 'tos-alisg-i-aphluv4xwc-sg/black'],
            ]],
        ]);

        $this->expectException(\RuntimeException::class);

        $this->buildTiktokExistingProductPartialEditMutationForTest([$invalidSku]);
    }

    public function test_tiktok_existing_product_partial_edit_mutation_rejects_inconsistent_variant_attributes(): void
    {
        $firstSku = $this->tiktokPartialEditFixtureSku();
        $secondSku = $this->tiktokPartialEditFixtureSku([
            'id' => 'existing-2',
            'seller_sku' => 'EXISTING-2',
            'sales_attributes' => [[
                'id' => '200000',
                'name' => 'Ukuran',
                'value_id' => 'large',
                'value_name' => 'Large',
                'sku_img' => ['uri' => 'tos-alisg-i-aphluv4xwc-sg/large'],
            ]],
        ]);

        $this->expectException(\RuntimeException::class);

        $this->buildTiktokExistingProductPartialEditMutationForTest([$firstSku, $secondSku]);
    }

    public function test_tiktok_existing_product_partial_edit_mutation_rejects_inconsistent_warehouses(): void
    {
        $firstSku = $this->tiktokPartialEditFixtureSku();
        $secondSku = $this->tiktokPartialEditFixtureSku([
            'id' => 'existing-2',
            'seller_sku' => 'EXISTING-2',
            'inventory' => [[
                'quantity' => 3,
                'warehouse_id' => 'warehouse-2',
            ]],
        ]);

        $this->expectException(\RuntimeException::class);

        $this->buildTiktokExistingProductPartialEditMutationForTest([$firstSku, $secondSku]);
    }

    /**
     * @dataProvider incompleteTiktokPartialEditSkuProvider
     */
    public function test_tiktok_existing_product_partial_edit_mutation_rejects_incomplete_existing_sku(array $overrides): void
    {
        $firstSku = $this->tiktokPartialEditFixtureSku();
        $secondSku = $this->tiktokPartialEditFixtureSku(array_merge([
            'id' => 'existing-2',
            'seller_sku' => 'EXISTING-2',
        ], $overrides));

        $this->expectException(\RuntimeException::class);

        $this->buildTiktokExistingProductPartialEditMutationForTest([$firstSku, $secondSku]);
    }

    public static function incompleteTiktokPartialEditSkuProvider(): array
    {
        return [
            'missing price' => [['price' => []]],
            'non-positive price' => [[
                'price' => [
                    'currency' => 'IDR',
                    'sale_price' => '0',
                    'tax_exclusive_price' => '0',
                ],
            ]],
            'missing inventory' => [['inventory' => []]],
        ];
    }

    public function test_bulk_tiktok_action_is_persisted_with_redacted_payload(): void
    {
        Schema::dropIfExists('sku_variant_actions');
        Schema::dropIfExists('stock_master');

        try {
            Schema::create('stock_master', function (Blueprint $table): void {
                $table->id();
                $table->string('internal_sku')->unique();
                $table->string('shopee_product_id')->nullable();
                $table->string('shopee_sku')->nullable();
            });
            Schema::create('sku_variant_actions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stock_master_id');
                $table->string('target_channel');
                $table->string('source_channel')->nullable();
                $table->string('action_type');
                $table->json('payload')->nullable();
                $table->string('status');
                $table->timestamps();
                $table->unique(['stock_master_id', 'target_channel', 'action_type']);
            });
            DB::table('stock_master')->insert([
                'internal_sku' => 'SKU-ROSE',
                'shopee_product_id' => '55307930257',
                'shopee_sku' => '267828680247',
            ]);

            $this->assertTrue($this->hasControllerMethod('recordBulkTiktokVariantAction'));
            $this->invokeControllerMethod('recordBulkTiktokVariantAction', [[
                'shopee_item_id' => '55307930257',
                'shopee_model_id' => '267828680247',
                'seller_sku' => 'SKU-ROSE',
            ], 'failed', 48000, 'TikTok menolak varian.', [
                'mutation' => [
                    'request' => ['query' => ['access_token' => 'secret', 'sign' => 'signature']],
                ],
            ]]);

            $action = DB::table('sku_variant_actions')->first();
            $this->assertSame('failed', $action->status);
            $this->assertSame('bulk_create_variant', $action->action_type);
            $this->assertStringNotContainsString('secret', $action->payload);
            $this->assertStringNotContainsString('signature', $action->payload);
        } finally {
            Schema::dropIfExists('sku_variant_actions');
            Schema::dropIfExists('stock_master');
        }
    }

    private function shopeeMissingSkuBulkCandidates(Collection $models): Collection
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('shopeeMissingSkuBulkCandidates');
        $method->setAccessible(true);

        return $method->invoke($controller, $models);
    }
    private function tiktokBulkMissingVariantCandidates(Collection $rows): Collection
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('tiktokBulkMissingVariantCandidates');
        $method->setAccessible(true);

        return $method->invoke($controller, $rows);
    }

    private function tiktokBulkCandidateRowFromSkuMapping(array $item): ?object
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('tiktokBulkCandidateRowFromSkuMapping');
        $method->setAccessible(true);

        return $method->invoke($controller, $item);
    }
    private function linkedTiktokSellerSkuMatch(array $lookup, string $productId, string $sellerSku): ?object
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('linkedTiktokSellerSkuMatch');
        $method->setAccessible(true);

        return $method->invoke($controller, $lookup, $productId, $sellerSku);
    }
    private function tiktokMajorityPrice(array $skus): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('tiktokMajorityPrice');
        $method->setAccessible(true);

        return $method->invoke($controller, $skus);
    }
    private function refreshMarketplaceImageUrl(string $sourceUrl, string $channel, string $scope, string $variant): ?string
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('refreshMarketplaceImageUrl');
        $method->setAccessible(true);

        return $method->invoke($controller, $sourceUrl, $channel, $scope, $variant);
    }

    private function cachedImagePath(?string $cachedUrl): string
    {
        return storage_path('app/public/'.ltrim(substr((string) $cachedUrl, strlen('/cached-images/')), '/'));
    }
    private function normalizePackageWeight(array $payload): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('normalizeTiktokGeneratedPayloadWeights');
        $method->setAccessible(true);

        return $method->invoke($controller, $payload);
    }

    private function normalizeDimensions(array $payload): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('normalizeTiktokGeneratedPayloadDimensions');
        $method->setAccessible(true);

        return $method->invoke($controller, $payload);
    }

    private function buildTiktokExistingProductPartialEditMutationForTest(array $skus): array
    {
        return $this->invokeControllerMethod('buildTiktokExistingProductPartialEditMutation', [
            ['product_id' => 'product-1'],
            ['id' => 'product-1', 'skus' => $skus],
            [
                'source' => ['price' => 48000],
                'target' => [
                    'variant_name' => 'Rose Gold',
                    'seller_sku' => 'NEW',
                    'stock_qty' => 2,
                ],
            ],
            (object) ['variant_name' => 'Rose Gold', 'internal_sku' => 'NEW'],
            'tos-alisg-i-aphluv4xwc-sg/rose-gold',
        ]);
    }

    private function tiktokPartialEditFixtureSku(array $overrides = []): array
    {
        return array_replace([
            'id' => 'existing-1',
            'seller_sku' => 'EXISTING',
            'price' => [
                'currency' => 'IDR',
                'sale_price' => '48000',
                'tax_exclusive_price' => '48000',
            ],
            'inventory' => [[
                'quantity' => 3,
                'warehouse_id' => 'warehouse-1',
            ]],
            'sales_attributes' => [[
                'id' => '100000',
                'name' => 'Warna',
                'value_id' => 'black',
                'value_name' => 'Hitam',
                'sku_img' => ['uri' => 'tos-alisg-i-aphluv4xwc-sg/black'],
            ]],
            'sku_weight' => ['unit' => 'GRAM', 'value' => '100'],
            'sku_dimensions' => [
                'unit' => 'CENTIMETER',
                'height' => '1',
                'length' => '1',
                'width' => '1',
            ],
        ], $overrides);
    }

    private function hasControllerMethod(string $name): bool
    {
        return method_exists(OmnichannelController::class, $name);
    }

    private function invokeControllerMethod(string $name, array $arguments): mixed
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod($name);
        $method->setAccessible(true);

        return $method->invoke($controller, ...$arguments);
    }
}
