<?php

// Opt-in: php tests/Integration/stock_hub_tiktok_variant_cache_postgres.php
// Real PostgreSQL insert/update boundary; all fixture writes roll back, no HTTP.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MarketplaceStockMirrorTransport;
use App\Services\StockHubTiktokProductGateway;
use App\Services\StockHubTiktokVariantGateway;
use App\Services\StockHubTiktokVariantPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function verifyCache(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}
verifyCache(DB::connection()->getDriverName() === 'pgsql', 'This smoke test requires PostgreSQL.');
Http::preventStrayRequests();
$targetId = '99'.random_int(100000000000000, 999999999999999);
$sourceId = '98'.random_int(100000000000000, 999999999999999);
$blue = 'CODEX-CACHE-'.$targetId;
$sku = fn ($id, $code, $color) => ['id' => $id, 'seller_sku' => $code, 'price' => ['currency' => 'IDR', 'sale_price' => '10000'],
    'inventory' => [['warehouse_id' => 'fixture-wh', 'quantity' => 0]], 'sales_attributes' => [['id' => '100', 'name' => 'Color', 'value_name' => $color]]];
$product = ['id' => $targetId, 'title' => 'Codex rollback fixture', 'status' => 'ACTIVATE', 'audit' => ['status' => 'AUDITING'],
    'package_weight' => ['value' => '0.2', 'unit' => 'KILOGRAM'], 'package_dimensions' => ['length' => '25', 'width' => '25', 'height' => '10', 'unit' => 'CENTIMETER'],
    'skus' => [$sku('901', 'CODEX-OLD-'.$targetId, 'Red'), $sku('902', $blue, 'Blue')]];
$source = ['id' => $sourceId, 'title' => 'Fixture', 'main_images' => ['https://fixture.test/main.jpg'],
    'variants' => [['id' => '102', 'seller_sku' => $blue]]];
$variant = ['id' => '102', 'seller_sku' => $blue, 'name' => 'Blue', 'stock' => 0, 'image_url' => 'https://fixture.test/blue.jpg',
    'attributes' => [['name' => 'Color', 'value_name' => 'Blue']]];
$payload = new StockHubTiktokVariantPayload();
$submitted = $payload->retained($product); unset($submitted[1]['id']);
$expected = ['price' => 10000, 'warehouse_id' => 'fixture-wh', 'variants' => [$variant], 'payload' => ['skus' => $submitted]];
$products = Mockery::mock(StockHubTiktokProductGateway::class);
// Use a reference so the second readback changes only the approval state.
$products->shouldReceive('target')->with($targetId, true)->andReturnUsing(function () use (&$product) { return $product; });
$products->shouldReceive('assertTargetShop')->with('fixture-shop')->andReturnNull();
$transport = Mockery::mock(MarketplaceStockMirrorTransport::class);
$transport->shouldReceive('context')->with(StockHubTiktokProductGateway::TARGET, false, 6)->andReturn(['shop_id' => 'fixture-shop']);
$gateway = new StockHubTiktokVariantGateway($products, $transport, $payload);
$initialCount = DB::table('tiktok_products')->count(); $failed = false;
DB::beginTransaction();
try {
    $result = $gateway->verify($source, $expected, $targetId);
    verifyCache($result['published'] === false, 'Pending result must remain under review.');
    $row = DB::table('tiktok_products')->where('product_id', $targetId)->where('seller_sku', $blue)->first();
    verifyCache($row !== null && in_array($row->is_active, [false, 0, '0', 'f'], true), 'Pending boolean insert must persist false.');
    $product['audit']['status'] = 'APPROVED';
    $result = $gateway->verify($source, $expected, $targetId);
    verifyCache($result['published'] === true, 'Approved result must verify.');
    $row = DB::table('tiktok_products')->where('product_id', $targetId)->where('seller_sku', $blue)->first();
    verifyCache(in_array($row->is_active, [true, 1, '1', 't'], true), 'Approved boolean update must persist true.');
    Http::assertNothingSent();
    Mockery::close();
} catch (Throwable $e) {
    $failed = true; echo 'FAIL '.get_class($e).' code='.$e->getCode().PHP_EOL;
} finally { DB::rollBack(); }
verifyCache(DB::table('tiktok_products')->count() === $initialCount, 'All fixture cache writes must roll back.');
verifyCache(! DB::table('tiktok_products')->where('product_id', $targetId)->exists(), 'Fixture must not survive rollback.');
if ($failed) { exit(1); }
echo 'PASS PostgreSQL pending cache insert and approved update; all writes rolled back; no HTTP.'.PHP_EOL;
