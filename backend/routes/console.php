<?php

use App\Http\Controllers\OmnichannelController;
use App\Services\MarketplaceOrderSyncService;
use App\Services\MarketplaceTokenSyncService;
use App\Services\StbMappingSyncService;
use App\Services\StbSyncWorkerService;
use App\Services\StockConsistencyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

$stbMode = (bool) config('stb.sync_worker', false);
$stbCron = static fn (int $minutes): string => '*/'.max(1, min(60, $minutes)).' * * * *';
$stbOverlapMinutes = static fn (int $minutes, int $min, int $max): int => max($min, min($max, $minutes * 3));

Artisan::command('about:agnishop', function (): void {
    $this->info('AgniShop Banjarmasin API');
});

Artisan::command('agnishop:stb-heartbeat', function (): int {
    $result = app(StbSyncWorkerService::class)->heartbeat('agnishop:stb-heartbeat');

    $this->info($result['message'] ?? 'Heartbeat STB selesai.');
    $this->line('Heartbeat: '.($result['heartbeat_at'] ?? '-'));

    return ($result['status'] ?? 'ok') === 'ok' ? 0 : 1;
});

Artisan::command('agnishop:pull-stb-marketplace-tokens', function (): int {
    $result = app(MarketplaceTokenSyncService::class)->pullFromStb();
    $this->info('STB token sync: '.($result['status'] ?? 'error'));
    $this->line($result['message'] ?? '');

    return in_array(($result['status'] ?? 'error'), ['success', 'unchanged', 'skipped'], true) ? 0 : 1;
});

Artisan::command('agnishop:sync-orders {--hours= : Lookback order dalam jam}', function (): int {
    $hours = (int) ($this->option('hours') ?: config('stb.worker.hours', 24));
    $result = app(StbSyncWorkerService::class)->syncOrders($hours);

    $this->info($result['message'] ?? 'STB order sync selesai.');
    foreach (($result['context'] ?? []) as $key => $value) {
        $this->line($key.': '.(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    return in_array(($result['status'] ?? 'success'), ['success', 'skipped'], true) ? 0 : 1;
});

Artisan::command('agnishop:sync-marketplace-lite', function (): int {
    $result = app(StbSyncWorkerService::class)->syncMarketplaceLite();

    $this->info($result['message'] ?? 'STB marketplace lite selesai.');

    return in_array(($result['status'] ?? 'success'), ['success', 'skipped'], true) ? 0 : 1;
});

Artisan::command('agnishop:safety-check-lite', function (): int {
    $result = app(StbSyncWorkerService::class)->safetyCheckLite();

    $this->info($result['message'] ?? 'STB safety check lite selesai.');
    foreach (($result['context'] ?? []) as $key => $value) {
        $this->line($key.': '.(is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    return in_array(($result['status'] ?? 'success'), ['success', 'skipped'], true) ? 0 : 1;
});

Artisan::command('agnishop:runtime-status {--json}', function (): int {
    $status = app(StbSyncWorkerService::class)->runtimeStatus();

    if ($this->option('json')) {
        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ($status['db_status']['ok'] ?? false) ? 0 : 1;
    }

    $this->table(['Item', 'Value'], [
        ['Mode', $status['mode'] ?? '-'],
        ['STB Worker', ($status['worker_online'] ?? false) ? 'Online' : 'Offline'],
        ['Last STB Heartbeat', $status['last_heartbeat_at'] ?? '-'],
        ['Last Order Sync', $status['last_order_sync_at'] ?? '-'],
        ['Last Marketplace Sync', $status['last_marketplace_sync_at'] ?? '-'],
        ['Queue', ($status['queue_status']['status'] ?? '-').' | '.($status['queue_status']['message'] ?? '-')],
        ['Scheduler', ($status['scheduler_status']['status'] ?? '-').' | '.($status['scheduler_status']['last_tick_at'] ?? '-')],
        ['Database', ($status['db_status']['status'] ?? '-').' | '.($status['db_status']['connection'] ?? '-')],
        ['Disk', ($status['disk_warning']['status'] ?? '-').' | '.($status['disk_warning']['used_percent'] ?? '-').'%'],
        ['Memory', ($status['memory_warning']['status'] ?? '-').' | '.($status['memory_warning']['available_mb'] ?? '-').' MB available'],
        ['Last Error', $status['last_error']['message'] ?? '-'],
    ]);

    return ($status['db_status']['ok'] ?? false) ? 0 : 1;
});

Artisan::command('agnishop:export-stb-mapping {--output= : Path file JSON output} {--with-tokens : Sertakan token marketplace aktif} {--only-tokens : Export token marketplace aktif saja} {--skip-images : Jangan sertakan cache URL gambar Shopee}', function (): int {
    $service = app(StbMappingSyncService::class);
    $onlyTables = (bool) $this->option('only-tokens') ? $service->tokenTableNames() : [];
    $exceptTables = (bool) $this->option('skip-images') ? ['shopee_product_image'] : [];
    $snapshot = $service->snapshot((bool) $this->option('with-tokens') || (bool) $this->option('only-tokens'), $onlyTables, $exceptTables);
    $output = trim((string) ($this->option('output') ?: base_path('../stb-mapping-snapshot.json')));
    file_put_contents($output, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $this->info('Snapshot mapping STB dibuat: '.$output);
    if ((bool) $this->option('with-tokens')) {
        $this->warn('Snapshot ini berisi token marketplace. Simpan dan hapus dengan hati-hati.');
    }
    foreach ($snapshot['tables'] ?? [] as $table => $payload) {
        $this->line($table.': '.(int) ($payload['count'] ?? 0).' rows');
    }

    return 0;
});

Artisan::command('agnishop:push-stb-mapping {--url= : URL endpoint/base STB} {--token= : Token STB mapping sync} {--with-stock : Overwrite stok existing di STB} {--with-tokens : Sertakan token marketplace aktif untuk STB} {--only-tokens : Kirim token marketplace aktif saja} {--skip-images : Jangan kirim tabel shopee_product_image} {--dry-run : Validasi tanpa import permanen} {--chunk=500 : Jumlah row per request} {--timeout=90}', function (): int {
    $service = app(StbMappingSyncService::class);
    $url = $service->endpointFromBase($this->option('url'));
    $token = trim((string) ($this->option('token') ?: config('stb.mapping_sync_token', '')));
    $timeout = max(10, min(300, (int) $this->option('timeout')));

    if ($url === '') {
        $this->error('URL STB belum diisi. Pakai --url=http://192.168.18.15:8088 atau STB_MAPPING_SYNC_URL.');
        return 1;
    }
    if ($token === '') {
        $this->error('Token STB belum diisi. Pakai --token=... atau STB_MAPPING_SYNC_TOKEN.');
        return 1;
    }

    $includeTokens = (bool) $this->option('with-tokens') || (bool) $this->option('only-tokens');
    $onlyTables = (bool) $this->option('only-tokens') ? $service->tokenTableNames() : [];
    $exceptTables = (bool) $this->option('skip-images') ? ['shopee_product_image'] : [];
    $snapshot = $service->snapshot($includeTokens, $onlyTables, $exceptTables);
    $this->info('Mengirim snapshot mapping ke '.$url);
    if ($includeTokens) {
        $this->warn('Mode token aktif: token marketplace ikut dikirim ke STB melalui endpoint internal.');
    }
    if ((bool) $this->option('skip-images')) {
        $this->warn('Mode hemat: shopee_product_image tidak dikirim.');
    }
    foreach ($snapshot['tables'] ?? [] as $table => $payload) {
        $this->line($table.': '.(int) ($payload['count'] ?? 0).' rows');
    }

    $chunkSize = max(50, min(2000, (int) $this->option('chunk')));
    $aggregate = [];
    foreach (($snapshot['tables'] ?? []) as $table => $payload) {
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $chunks = array_chunk($rows, $chunkSize);
        if ($chunks === []) {
            $chunks = [[]];
        }

        foreach ($chunks as $index => $rowsChunk) {
            $partialSnapshot = [
                ...$snapshot,
                'tables' => [
                    $table => [
                        ...$payload,
                        'count' => count($rowsChunk),
                        'rows' => $rowsChunk,
                    ],
                ],
            ];

            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withToken($token)
                ->post($url, [
                    'snapshot' => $partialSnapshot,
                    'preserve_stock' => ! (bool) $this->option('with-stock'),
                    'dry_run' => (bool) $this->option('dry-run'),
                ]);

            $responsePayload = $response->json();
            if (! $response->successful() || ! is_array($responsePayload)) {
                $this->error(sprintf('Push mapping STB gagal di %s chunk %s. HTTP %s', $table, $index + 1, $response->status()));
                $this->line($response->body());
                return 1;
            }

            foreach (($responsePayload['tables'] ?? []) as $summaryTable => $summary) {
                foreach (['received', 'inserted', 'updated', 'skipped'] as $key) {
                    $aggregate[$summaryTable][$key] = (int) ($aggregate[$summaryTable][$key] ?? 0) + (int) ($summary[$key] ?? 0);
                }
            }

            $this->line(sprintf('%s chunk %s/%s terkirim.', $table, $index + 1, count($chunks)));
        }
    }

    $this->info('Push mapping STB selesai.');
    foreach ($aggregate as $table => $summary) {
        $this->line(sprintf(
            '%s: received=%s inserted=%s updated=%s skipped=%s',
            $table,
            (int) ($summary['received'] ?? 0),
            (int) ($summary['inserted'] ?? 0),
            (int) ($summary['updated'] ?? 0),
            (int) ($summary['skipped'] ?? 0),
        ));
    }

    return 0;
});

Artisan::command('agnishop:prune-marketplace-image-cache {--days=7 : Hapus file lebih tua dari jumlah hari ini} {--all : Hapus semua cache gambar marketplace}', function (): int {
    $baseDir = storage_path('app/public/marketplace-images');
    if (! is_dir($baseDir)) {
        $this->info('Folder cache gambar marketplace belum ada.');
        return 0;
    }

    $baseRoot = realpath($baseDir);
    if ($baseRoot === false) {
        $this->error('Folder cache gambar marketplace tidak valid.');
        return 1;
    }

    $all = (bool) $this->option('all');
    $days = max(0, (int) $this->option('days'));
    $cutoff = time() - ($days * 86400);
    $deleted = 0;
    $bytes = 0;
    $errors = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $realPath = realpath($path);
        if ($realPath === false || ! str_starts_with($realPath, $baseRoot)) {
            continue;
        }

        if ($file->isDir()) {
            @rmdir($realPath);
            continue;
        }

        if (! $file->isFile()) {
            continue;
        }

        if (! $all && $file->getMTime() > $cutoff) {
            continue;
        }

        $size = $file->getSize();
        if (@unlink($realPath)) {
            $deleted++;
            $bytes += $size;
        } else {
            $errors++;
        }
    }

    $formatBytes = static function (int $value): string {
        $amount = (float) $value;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($amount < 1024 || $unit === 'GB') {
                return round($amount, 2).' '.$unit;
            }
            $amount /= 1024;
        }

        return $value.' B';
    };

    $this->info(sprintf(
        'Cache gambar marketplace dibersihkan. File=%s, ruang bebas=%s, gagal=%s.',
        $deleted,
        $formatBytes($bytes),
        $errors
    ));

    return $errors > 0 ? 1 : 0;
});

Artisan::command('sku-mapping:sync-marketplaces', function (): int {
    app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
    $result = app(OmnichannelController::class)->syncMarketplaceCachesForSkuMapping();

    $this->info($result['message'] ?? 'Sync marketplace selesai.');
    $this->line('Shopee: '.($result['shopee']['message'] ?? $result['shopee']['status'] ?? '-'));
    $this->line('TikTok: '.($result['tiktok']['message'] ?? $result['tiktok']['status'] ?? '-'));
    $this->line('Auto-hidden stock master: '.($result['auto_hidden_inactive_stock_master'] ?? 0));

    return ($result['status'] ?? 'ok') === 'partial_error' ? 1 : 0;
});

if (! $stbMode) {
    Schedule::command('sku-mapping:sync-marketplaces')
        ->everyFifteenMinutes()
        ->withoutOverlapping();

    if ((bool) config('stb.mapping_sync_enabled', false)) {
        Schedule::command('agnishop:push-stb-mapping')
            ->cron($stbCron((int) config('stb.mapping_sync_minutes', 15)))
            ->withoutOverlapping();
    }
}

Artisan::command('marketplace:refresh-tokens {--force}', function (): int {
    $result = app(OmnichannelController::class)->autoRefreshMarketplaceTokens((bool) $this->option('force'));

    $this->info($result['message'] ?? 'Auto refresh token selesai.');
    foreach ($result['items'] ?? [] as $item) {
        $this->line(sprintf(
            '%s | %s | %s',
            $item['account_name'] ?? $item['account_key'] ?? '-',
            $item['status'] ?? '-',
            $item['message'] ?? $item['error'] ?? '-'
        ));
    }

    return ($result['status'] ?? 'ok') === 'warning' ? 1 : 0;
});

if (! $stbMode) {
    Schedule::command('marketplace:refresh-tokens')
        ->everyTenMinutes()
        ->withoutOverlapping();

    if ((bool) config('stb.token_sync_enabled', false) && trim((string) config('stb.token_sync_url', '')) !== '') {
        Schedule::command('agnishop:pull-stb-marketplace-tokens')
            ->cron($stbCron((int) config('stb.token_sync_minutes', 5)))
            ->withoutOverlapping(3);
    }
}

Artisan::command('sync:safety-check', function (): int {
    app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
    $result = app(StockConsistencyService::class)->run();

    $this->info($result['message'] ?? 'Safety check selesai.');
    $this->line('Total checked: '.($result['total_checked'] ?? 0));
    $this->line('Total corrected: '.($result['total_corrected'] ?? 0));

    return ($result['status'] ?? 'ok') === 'ok' ? 0 : 1;
});

if (! $stbMode) {
    Schedule::command('sync:safety-check')
        ->everyFifteenMinutes()
        ->withoutOverlapping();
}

Artisan::command('sync:shopee-orders {--hours=24}', function (): int {
    app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
    $result = app(MarketplaceOrderSyncService::class)->pollShopeeReadyOrders((int) $this->option('hours'));

    $this->info($result['message'] ?? 'Polling order Shopee selesai.');
    foreach ($result['messages'] ?? [] as $message) {
        $this->line($message);
    }

    return ($result['status'] ?? 'success') === 'success' ? 0 : 1;
});

if (! $stbMode) {
    Schedule::command('sync:shopee-orders --hours=24')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}

Artisan::command('sync:tiktok-orders {--hours=24}', function (): int {
    app(OmnichannelController::class)->autoRefreshMarketplaceTokens();
    $result = app(MarketplaceOrderSyncService::class)->pollTiktokUpdatedOrders((int) $this->option('hours'));

    $this->info($result['message'] ?? 'Polling order TikTok selesai.');
    foreach ($result['messages'] ?? [] as $message) {
        $this->line($message);
    }

    return ($result['status'] ?? 'success') === 'success' ? 0 : 1;
});

if (! $stbMode) {
    Schedule::command('sync:tiktok-orders --hours=24')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}

Artisan::command('sync:order-product-refresh {--limit=20}', function (): int {
    $result = app(MarketplaceOrderSyncService::class)->processPendingProductCacheRefreshes((int) $this->option('limit'));

    $this->info($result['message'] ?? 'Pending refresh produk order selesai.');
    foreach ($result['items'] ?? [] as $item) {
        $this->line(sprintf(
            '%s | %s | %s',
            $item['order_ref'] ?? '-',
            $item['status'] ?? '-',
            $item['message'] ?? '-'
        ));
    }

    return ($result['status'] ?? 'success') === 'success' ? 0 : 1;
});

if (! $stbMode) {
    Schedule::command('sync:order-product-refresh --limit=50')
        ->everyMinute()
        ->withoutOverlapping();
}

if ($stbMode) {
    Schedule::command('agnishop:stb-heartbeat')
        ->everyMinute()
        ->withoutOverlapping(2);

    if ((bool) config('stb.features.order_sync', true)) {
        $orderSyncMinutes = (int) config('stb.intervals.order_sync_minutes', 5);

        Schedule::command('agnishop:sync-orders --hours='.(int) config('stb.worker.hours', 24))
            ->cron($stbCron($orderSyncMinutes))
            ->withoutOverlapping($stbOverlapMinutes($orderSyncMinutes, 3, 10));
    }

    $safetyCheckMinutes = (int) config('stb.intervals.safety_check_minutes', 15);
    Schedule::command('agnishop:safety-check-lite')
        ->cron($stbCron($safetyCheckMinutes))
        ->withoutOverlapping($stbOverlapMinutes($safetyCheckMinutes, 5, 30));

    if ((bool) config('stb.features.marketplace_sync', true)) {
        $marketplaceLiteMinutes = (int) config('stb.intervals.marketplace_lite_minutes', 60);

        Schedule::command('agnishop:sync-marketplace-lite')
            ->cron($stbCron($marketplaceLiteMinutes))
            ->withoutOverlapping($stbOverlapMinutes($marketplaceLiteMinutes, 10, 120));
    }
}
