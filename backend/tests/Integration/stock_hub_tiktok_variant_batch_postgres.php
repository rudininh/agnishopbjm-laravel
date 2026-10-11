<?php

// Opt-in: php tests/Integration/stock_hub_tiktok_variant_batch_postgres.php
// Uses the configured PostgreSQL database, rolls back all writes, and fakes children.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\StockHubTiktokVariantBatchSelection;
use App\Services\StockHubTiktokVariantBatchService;
use App\Services\StockHubTiktokVariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function verifyBatch(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

verifyBatch(DB::connection()->getDriverName() === 'pgsql', 'This smoke test requires PostgreSQL.');
Http::preventStrayRequests();
$service = $app->make(StockHubTiktokVariantBatchService::class);
verifyBatch($service->active() === null, 'Stop: an operator batch is already active.');
$initialCount = DB::table('stock_hub_tiktok_variant_batches')->count();
$selection = Mockery::mock(StockHubTiktokVariantBatchSelection::class);
$selection->shouldReceive('assertReady')->andReturnNull();
$selection->shouldReceive('forProducts')->andReturn([
    ['source_product_id' => '1', 'name' => 'Fixture queued', 'coverage' => ['state' => 'missing_variants', 'missing_count' => 1]],
    ['source_product_id' => '2', 'name' => 'Fixture complete', 'coverage' => ['state' => 'complete', 'missing_count' => 0]],
]);
$child = Mockery::mock(StockHubTiktokVariantService::class);
$childId = (string) Str::uuid();
$preparing = ['run_id' => $childId, 'status' => 'preparing', 'can_continue' => true, 'message' => 'Fixture preparing', 'result' => null];
$child->shouldReceive('source')->andReturnNull();
$child->shouldReceive('start')->once()->andReturn($preparing);
$child->shouldReceive('show')->andReturn($preparing);
$child->shouldReceive('step')->once()->andReturn(['run_id' => $childId, 'status' => 'success', 'can_continue' => false,
    'message' => 'Fixture verified', 'result' => ['variants' => [['seller_sku' => 'FIXTURE', 'status' => 'updated']]]]);
$service = new StockHubTiktokVariantBatchService($selection, $child);
$failed = false;
DB::beginTransaction();
try {
    $key = (string) Str::uuid();
    $run = $service->start($key);
    verifyBatch(DB::table('stock_hub_tiktok_variant_batches')->where('id', $run['run_id'])->value('request_key') === $key,
        'Stop: another operator acquired the catalog before this smoke test.');
    verifyBatch($run['summary']['products_processed'] === 1, 'Both false and true item flags must insert.');
    verifyBatch($service->start($key)['run_id'] === $run['run_id'], 'Start identity must deduplicate.');
    $id = $run['run_id'];
    verifyBatch($service->pause($id)['status'] === 'paused', 'Pause flag must update.');
    verifyBatch($service->resume($id)['status'] === 'running', 'Resume flag must update.');
    $run = $service->step($id);
    verifyBatch($run['items'][0]['status'] === 'preparing', 'Unprocessed predicate must select the queued child.');
    $run = $service->step($id);
    verifyBatch($run['status'] === 'completed' && $run['summary']['products_processed'] === 2, 'Processed update/completion query must succeed.');
    verifyBatch($run['summary']['variants_verified'] === 1, 'Saved child result must be visible.');
    verifyBatch($service->active() === null, 'Completed batch must release its claim.');
    Http::assertNothingSent();
    Mockery::close();
} catch (Throwable $e) {
    $failed = true;
    echo 'FAIL '.get_class($e).' code='.$e->getCode().PHP_EOL;
    if ($e instanceof Illuminate\Database\QueryException) { echo strtok($e->getPrevious()->getMessage(), "\n").PHP_EOL; }
} finally { DB::rollBack(); }
verifyBatch(DB::table('stock_hub_tiktok_variant_batches')->count() === $initialCount, 'Smoke writes must roll back.');
verifyBatch($app->make(StockHubTiktokVariantBatchService::class)->active() === null, 'Smoke must not leave an active batch.');
if ($failed) { exit(1); }
echo 'PASS PostgreSQL start/dedupe/pause/resume/step/completion; all writes rolled back; no marketplace HTTP.'.PHP_EOL;
