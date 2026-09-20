<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class MarketplaceSyncService
{
    public function __construct(
        private readonly MarketplaceApiService $apiService,
        private readonly MarketplaceFailureNotifier $failureNotifier,
    ) {
    }

    public function dashboard(): array
    {
        $today = Carbon::today();

        return [
            'accounts' => app(MarketplaceAccountReadinessService::class)->all(),
            'statuses' => [
                'shopee' => $this->marketplaceStatus('shopee', $today),
                'tiktok' => $this->marketplaceStatus('tiktok', $today),
            ],
            'engine' => [
                'status' => 'active',
                'realtime_sync' => true,
                'safety_check' => ! $this->stbHeavyFeaturesDisabled(),
                'live_push' => $this->livePushEnabled(),
                'stb_worker' => (bool) config('stb.sync_worker', false),
                'browser_auto_sync_allowed' => (bool) config('stb.features.auto_browser', true),
                'failure_notifications' => [
                    'enabled' => (bool) config('marketplace_notifications.failure.enabled'),
                    'telegram' => (bool) config('marketplace_notifications.telegram.enabled'),
                    'whatsapp' => (bool) config('marketplace_notifications.whatsapp.enabled'),
                ],
                'cron_interval' => 'Every 15 Minutes',
                'last_run' => $this->lastSafetyRun(),
                'next_run' => $this->nextSafetyRun(),
            ],
            'order_sync' => $this->orderSyncSummary($today),
            'alerts' => $this->autoSyncAlerts($today),
            'webhook_urls' => [
                'shopee' => url('/api/webhooks/shopee'),
                'tiktok' => url('/api/webhooks/tiktok'),
            ],
        ];
    }

    public function webhookLogs(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = DB::table('marketplace_webhook_logs')->orderByDesc('created_at')->orderByDesc('id');

        if (($filters['marketplace'] ?? '') !== '') {
            $query->where('marketplace', $filters['marketplace']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['date'] ?? '') !== '') {
            $query->whereDate('created_at', $filters['date']);
        }

        return $this->paginateQuery($query, $page, $perPage);
    }

    public function syncLogs(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = DB::table('marketplace_sync_logs')->orderByDesc('created_at')->orderByDesc('id');

        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['date'] ?? '') !== '') {
            $query->whereDate('created_at', $filters['date']);
        }
        if (($filters['marketplace'] ?? '') !== '') {
            $marketplace = $filters['marketplace'];
            $query->where(function ($inner) use ($marketplace): void {
                $inner->where('source_marketplace', $marketplace)
                    ->orWhere('target_marketplace', $marketplace);
            });
        }

        $result = $this->paginateQuery($query, $page, $perPage);
        $result['items'] = $this->decorateSyncLogRows($result['items']);

        return $result;
    }

    public function safetyHistory(int $page = 1, int $perPage = 20): array
    {
        $query = DB::table('marketplace_sync_logs')
            ->where('source_marketplace', 'safety_check')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $result = $this->paginateQuery($query, $page, $perPage);
        $result['items'] = $this->decorateSyncLogRows($result['items']);

        return $result;
    }

    public function orderSyncHistory(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order', 'gita_order'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyOrderSyncFilters($query, $filters);

        $result = $this->paginateQuery($query, $page, $perPage);
        $result['items'] = $this->decorateSyncLogRows($result['items']);

        return [
            'summary' => $this->orderSyncSummary(Carbon::today()),
            ...$result,
        ];
    }

    public function orderSyncExportRows(array $filters = [], int $limit = 5000)
    {
        $query = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order', 'gita_order'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyOrderSyncFilters($query, $filters);

        return $query->limit(min(5000, max(1, $limit)))->get();
    }

    public function autoSyncAlerts(?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $openIssueCount = (int) DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped'])
            ->count();
        $todayIssueCount = (int) DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped'])
            ->whereDate('created_at', $today)
            ->count();
        $latestIssue = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $stockSummary = $this->stbHeavyFeaturesDisabled()
            ? []
            : ($this->stockAnomalies([], 1, 1)['summary'] ?? []);

        $alerts = [];
        if ($openIssueCount > 0) {
            $alerts[] = [
                'type' => 'order_issue',
                'severity' => 'error',
                'title' => $openIssueCount.' issue order/stok masih terbuka',
                'message' => $latestIssue?->message ?: 'Ada order sync yang perlu dicek atau di-retry.',
                'created_at' => $latestIssue?->created_at,
                'count_today' => $todayIssueCount,
            ];
        }

        if (($stockSummary['total_anomalies'] ?? 0) > 0) {
            $alerts[] = [
                'type' => 'stock_anomaly',
                'severity' => 'warning',
                'title' => $stockSummary['total_anomalies'].' anomali stok terdeteksi',
                'message' => 'Buka tab Anomali Stok untuk menyamakan stok Shopee dan TikTok.',
                'created_at' => $stockSummary['last_safety_run'] ?? null,
                'count_today' => null,
            ];
        }

        return $alerts;
    }

    public function stockAnomalies(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        if ($this->stbHeavyFeaturesDisabled()) {
            return [
                'summary' => [
                    'total_anomalies' => 0,
                    'stock_mismatch' => 0,
                    'missing_shopee_stock' => 0,
                    'missing_tiktok_stock' => 0,
                    'tiktok_sku_conflict' => 0,
                    'incomplete_mapping' => 0,
                    'last_safety_run' => $this->lastSafetyRun(),
                    'disabled' => true,
                    'message' => 'Stock anomaly deep scan dinonaktifkan di mode STB sync worker.',
                ],
                'items' => collect(),
                'pagination' => [
                    'page' => max(1, $page),
                    'per_page' => min(100, max(1, $perPage)),
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];
        }

        $this->autoHideInactiveStockMasterMappings();

        $type = trim((string) ($filters['type'] ?? ''));
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $rows = $this->activeSkuMappings();

        $items = $rows->map(function ($row): array {
            $sku = $this->canonicalSku($row);
            $shopeeStock = $row->shopee_stock !== null ? (int) $row->shopee_stock : null;
            $resolvedTiktokSku = $this->resolveTiktokSku($row, true);
            $tiktokStock = $row->tiktok_stock !== null
                ? (int) $row->tiktok_stock
                : ($resolvedTiktokSku && $resolvedTiktokSku->stock_qty !== null ? (int) $resolvedTiktokSku->stock_qty : null);
            $hasShopeeMapping = trim((string) ($row->shopee_product_id ?? '')) !== '' && trim((string) ($row->shopee_sku ?? '')) !== '';
            $hasTiktokSku = $resolvedTiktokSku !== null;
            $ambiguousTiktokSku = $hasTiktokSku ? null : $this->ambiguousTiktokSkuForShopeeSellerSku($row);

            $issueType = null;
            $severity = 'warning';
            $message = '';
            if (! $hasShopeeMapping) {
                $issueType = 'incomplete_mapping';
                $severity = 'error';
                $message = 'Mapping Shopee belum lengkap.';
            } elseif ($shopeeStock === null && ($tiktokStock ?? 0) > 0) {
                $issueType = 'missing_shopee_stock';
                $severity = 'error';
                $message = 'Stok Shopee belum tersedia di cache.';
            } elseif ($ambiguousTiktokSku) {
                $issueType = 'tiktok_sku_conflict';
                $severity = 'warning';
                $message = sprintf(
                    'SKU Shopee %s sudah digunakan TikTok untuk varian %s; varian Shopee %s perlu verifikasi mapping. Stok tidak dipush otomatis.',
                    trim((string) ($row->shopee_seller_sku ?? '')),
                    trim((string) ($ambiguousTiktokSku->sku_name ?? '-')),
                    trim((string) ($row->variant_name ?? '-')),
                );
            } elseif ((! $hasTiktokSku || $tiktokStock === null) && ($shopeeStock ?? 0) > 0) {
                $issueType = 'missing_tiktok_stock';
                $severity = 'error';
                $message = 'Varian TikTok aktif belum ditemukan dari SKU/nama varian.';
            } elseif ($shopeeStock !== null && $tiktokStock !== null && $shopeeStock !== $tiktokStock) {
                $issueType = 'stock_mismatch';
                $severity = 'warning';
                $message = sprintf('Stok tidak sinkron. Shopee=%s TikTok=%s.', $shopeeStock, $tiktokStock);
            }

            return [
                'sku' => $sku,
                'product_name' => (string) ($row->product_name ?? ''),
                'variant_name' => (string) ($row->variant_name ?? ''),
                'shopee_stock' => $shopeeStock,
                'tiktok_stock' => $tiktokStock,
                'difference' => $shopeeStock !== null && $tiktokStock !== null ? $shopeeStock - $tiktokStock : null,
                'issue_type' => $issueType,
                'severity' => $severity,
                'message' => $message,
                'shopee_product_id' => (string) ($row->shopee_product_id ?? ''),
                'shopee_model_id' => (string) ($row->shopee_sku ?? ''),
                'tiktok_product_id' => (string) ($row->tiktok_product_id ?? $resolvedTiktokSku->product_id ?? $ambiguousTiktokSku->product_id ?? ''),
                'tiktok_sku_id' => (string) ($row->tiktok_sku ?? $resolvedTiktokSku->sku_id ?? $ambiguousTiktokSku->sku_id ?? ''),
                'updated_at' => (string) ($row->updated_at ?? ''),
            ];
        })->filter(fn (array $row): bool => $row['issue_type'] !== null);

        $summary = [
            'total_anomalies' => $items->count(),
            'stock_mismatch' => $items->where('issue_type', 'stock_mismatch')->count(),
            'missing_shopee_stock' => $items->where('issue_type', 'missing_shopee_stock')->count(),
            'missing_tiktok_stock' => $items->where('issue_type', 'missing_tiktok_stock')->count(),
            'tiktok_sku_conflict' => $items->where('issue_type', 'tiktok_sku_conflict')->count(),
            'incomplete_mapping' => $items->where('issue_type', 'incomplete_mapping')->count(),
            'last_safety_run' => $this->lastSafetyRun(),
        ];

        if ($type !== '') {
            $items = $items->filter(fn (array $row): bool => $row['issue_type'] === $type);
        }

        if ($search !== '') {
            $items = $items->filter(function (array $row) use ($search): bool {
                return str_contains(mb_strtolower($row['sku']), $search)
                    || str_contains(mb_strtolower($row['product_name']), $search)
                    || str_contains(mb_strtolower($row['variant_name']), $search);
            });
        }

        $items = $items
            ->sortBy([
                fn (array $row): int => $row['severity'] === 'error' ? 0 : 1,
                fn (array $row): string => $row['product_name'],
                fn (array $row): string => $row['variant_name'],
            ])
            ->values();

        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $total = $items->count();

        return [
            'summary' => $summary,
            'items' => $items->forPage($page, $perPage)->values(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function skuChangeHistory(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        if (! Schema::hasTable('marketplace_sku_change_logs')) {
            return $this->emptyPagination($page, $perPage);
        }

        $query = DB::table('marketplace_sku_change_logs as log')
            ->leftJoin('stock_master as sm', 'sm.id', '=', 'log.stock_master_id')
            ->select(
                'log.*',
                'sm.product_name',
                'sm.variant_name',
                'sm.internal_sku'
            )
            ->orderByDesc('log.created_at')
            ->orderByDesc('log.id');

        if (($filters['channel'] ?? '') !== '') {
            $query->where('log.channel', $filters['channel']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('log.status', $filters['status']);
        }
        if (($filters['date'] ?? '') !== '') {
            $query->whereDate('log.created_at', $filters['date']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('log.old_seller_sku', 'like', '%'.$search.'%')
                    ->orWhere('log.new_seller_sku', 'like', '%'.$search.'%')
                    ->orWhere('log.product_id', 'like', '%'.$search.'%')
                    ->orWhere('log.variant_id', 'like', '%'.$search.'%')
                    ->orWhere('sm.product_name', 'like', '%'.$search.'%')
                    ->orWhere('sm.variant_name', 'like', '%'.$search.'%')
                    ->orWhere('sm.internal_sku', 'like', '%'.$search.'%');
            });
        }

        return $this->paginateQuery($query, $page, $perPage);
    }

    public function orderWatchdog(int $minutes = 5, int $hours = 24): array
    {
        $minutes = max(1, min(180, $minutes));
        $hours = max(1, min(168, $hours));
        $threshold = now()->subMinutes($minutes);
        $since = now()->subHours($hours);
        $orders = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'tiktok_order'])
            ->where('status', 'success')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $threshold)
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        $items = $orders->map(function ($order) use ($minutes): ?array {
            $orderRef = $this->extractOrderReference($order);
            if ($orderRef === '') {
                return null;
            }

            $hasStockUpdate = DB::table('marketplace_sync_logs')
                ->where('id', '<>', $order->id)
                ->where('created_at', '>=', Carbon::parse($order->created_at)->subMinutes(10))
                ->where(function ($query) use ($orderRef): void {
                    $query->where('sku', $orderRef)
                        ->orWhere('message', 'like', '%'.$orderRef.'%');
                })
                ->where(function ($query): void {
                    $query->whereNotNull('old_stock')
                        ->orWhereNotNull('new_stock')
                        ->orWhere('source_marketplace', 'shopee_stock_refresh');
                })
                ->exists();

            if ($hasStockUpdate) {
                return null;
            }

            return [
                'id' => $order->id,
                'order_ref' => $orderRef,
                'source_marketplace' => $order->source_marketplace,
                'target_marketplace' => $order->target_marketplace,
                'status' => 'watch',
                'created_at' => $order->created_at,
                'age_minutes' => Carbon::parse($order->created_at)->diffInMinutes(now()),
                'message' => 'Order sudah lebih dari '.$minutes.' menit tetapi belum ada log update stok terkait.',
            ];
        })->filter()->values();

        return [
            'summary' => [
                'threshold_minutes' => $minutes,
                'window_hours' => $hours,
                'watch_count' => $items->count(),
                'checked_orders' => $orders->count(),
            ],
            'items' => $items,
        ];
    }

    public function reconciliationReport(array $filters = []): array
    {
        if ($this->stbHeavyFeaturesDisabled()) {
            return [
                'generated_at' => now()->toDateTimeString(),
                'summary' => [
                    'total_active_mappings' => 0,
                    'aligned' => 0,
                    'total_anomalies' => 0,
                    'stock_mismatch' => 0,
                    'missing_shopee_stock' => 0,
                    'missing_tiktok_stock' => 0,
                    'incomplete_mapping' => 0,
                    'last_safety_run' => $this->lastSafetyRun(),
                    'disabled' => true,
                    'message' => 'Reconciliation report dinonaktifkan di mode STB sync worker.',
                ],
                'items' => [],
            ];
        }

        $anomalyData = $this->stockAnomalies($filters, 1, 100);
        $summary = $anomalyData['summary'] ?? [];
        $totalMappings = $this->activeSkuMappings()->count();
        $totalAnomalies = (int) ($summary['total_anomalies'] ?? 0);

        return [
            'generated_at' => now()->toDateTimeString(),
            'summary' => [
                'total_active_mappings' => $totalMappings,
                'aligned' => max(0, $totalMappings - $totalAnomalies),
                'total_anomalies' => $totalAnomalies,
                'stock_mismatch' => (int) ($summary['stock_mismatch'] ?? 0),
                'missing_shopee_stock' => (int) ($summary['missing_shopee_stock'] ?? 0),
                'missing_tiktok_stock' => (int) ($summary['missing_tiktok_stock'] ?? 0),
                'incomplete_mapping' => (int) ($summary['incomplete_mapping'] ?? 0),
                'last_safety_run' => $summary['last_safety_run'] ?? null,
            ],
            'items' => $anomalyData['items'] ?? [],
        ];
    }

    public function syncQueueDashboard(int $hours = 24, int $limit = 50): array
    {
        $hours = max(1, min(168, $hours));
        $limit = max(1, min(100, $limit));
        $since = now()->subHours($hours);
        $base = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order', 'manual_shopee_master', 'manual_anomaly_tiktok_master', 'safety_check', 'stb_order_sync', 'stb_marketplace_lite', 'stb_safety_check_lite'])
            ->where('created_at', '>=', $since);
        $statusCounts = (clone $base)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $items = (clone $base)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'summary' => [
                'window_hours' => $hours,
                'pending' => (int) ($statusCounts['pending'] ?? 0),
                'processing' => (int) ($statusCounts['processing'] ?? 0),
                'success' => (int) ($statusCounts['success'] ?? 0),
                'failed' => (int) (($statusCounts['error'] ?? 0) + ($statusCounts['skipped'] ?? 0)),
                'checked' => (int) ($statusCounts['checked'] ?? 0),
                'total' => (int) $statusCounts->sum(),
            ],
            'items' => $items,
        ];
    }

    public function syncStockAnomaly(string $sku, string $sourceMarketplace): array
    {
        $sku = trim($sku);
        $sourceMarketplace = strtolower(trim($sourceMarketplace));
        if ($sku === '') {
            return ['status' => 'error', 'message' => 'SKU anomali wajib diisi.'];
        }
        if (! in_array($sourceMarketplace, ['shopee', 'tiktok'], true)) {
            return ['status' => 'error', 'message' => 'Sumber sinkron wajib Shopee atau TikTok.'];
        }

        $mapping = $this->findSkuMapping($sku);
        if (! $mapping) {
            return ['status' => 'error', 'message' => 'SKU mapping tidak ditemukan untuk '.$sku.'.'];
        }

        if ($sourceMarketplace === 'shopee') {
            return $this->mirrorShopeeStockToTiktok($mapping, 'Manual anomali stok Shopee -> TikTok', true, true);
        }

        $resolvedTiktokSku = $this->resolveTiktokSku($mapping, true);
        if (! $resolvedTiktokSku || $resolvedTiktokSku->stock_qty === null) {
            $this->logSync('manual_anomaly_tiktok_master', 'shopee', $sku, null, null, 'error', 'Manual anomali TikTok -> Shopee gagal: SKU TikTok aktif tidak ditemukan.');

            return ['status' => 'error', 'message' => 'SKU TikTok aktif tidak ditemukan untuk '.$sku.'.'];
        }

        $oldStock = $this->currentStockForMarketplace('shopee', $mapping) ?? (int) ($mapping->stock_qty ?? 0);
        $newStock = (int) $resolvedTiktokSku->stock_qty;
        $pushResult = $this->pushTargetStock($mapping, 'shopee', $newStock, true);
        $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';

        if ($status === 'success') {
            $this->updateLocalStock($mapping, 'shopee', $newStock);
        }

        $message = sprintf('Manual anomali TikTok -> Shopee: stok Shopee %s -> %s. %s', $oldStock, $newStock, $pushResult['message'] ?? '-');
        $this->logSync('manual_anomaly_tiktok_master', 'shopee', $sku, $oldStock, $newStock, $status, $message);
        $this->updateStatus('tiktok', ['last_sync_at' => now(), 'status' => 'connected']);
        $this->updateStatus('shopee', ['last_sync_at' => now(), 'status' => $status === 'error' ? 'disconnected' : 'connected']);

        return [
            'status' => $status,
            'message' => $message,
            'sku' => $sku,
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'push' => $pushResult,
        ];
    }

    public function orderSyncDetail(int $logId): array
    {
        $log = DB::table('marketplace_sync_logs')->where('id', $logId)->first();
        if (! $log) {
            return ['status' => 'error', 'message' => 'Log order sync tidak ditemukan.'];
        }

        $orderRef = $this->extractOrderReference($log);
        $relatedQuery = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->orderBy('created_at')
            ->orderBy('id');
        if ($orderRef !== '') {
            $relatedQuery->where(function ($query) use ($orderRef): void {
                $query->where('sku', $orderRef)
                    ->orWhere('message', 'like', '%'.$orderRef.'%');
            });
        } else {
            $relatedQuery->where('id', $logId);
        }

        $related = $relatedQuery->limit(200)->get();
        $order = null;
        if ($orderRef !== '' && ($log->source_marketplace === 'shopee_order' || $related->contains('source_marketplace', 'shopee_order'))) {
            $detail = $this->apiService->fetchShopeeOrderDetail($orderRef);
            if (($detail['status'] ?? '') === 'success') {
                $order = $this->formatShopeeOrderDetail($detail['order']);
            }
        }
        if ($orderRef !== '' && $order === null && ($log->source_marketplace === 'tiktok_order' || $related->contains('source_marketplace', 'tiktok_order'))) {
            $detail = $this->apiService->fetchTiktokOrderDetail($orderRef);
            if (($detail['status'] ?? '') === 'success') {
                $order = $this->formatTiktokOrderDetail($detail['order']);
            }
        }

        return [
            'status' => 'ok',
            'order_ref' => $orderRef,
            'log' => $this->decorateSyncLogRows([$log])[0] ?? $log,
            'order' => $order,
            'stock_updates' => $related->map(fn ($row): array => [
                'id' => $row->id,
                'time' => $row->created_at,
                'type' => $row->source_marketplace,
                'target' => $row->target_marketplace,
                'runner' => $row->runner ?? null,
                'runner_label' => $this->runnerLabel($this->normalizeRunner($row->runner ?? null, 'local')),
                'machine_name' => $row->machine_name ?? null,
                'sku' => $row->sku,
                'old_stock' => $row->old_stock,
                'new_stock' => $row->new_stock,
                'status' => $row->status,
                'message' => $row->message,
            ])->values(),
        ];
    }

    public function orderReferenceFromLog(object $log): string
    {
        return $this->extractOrderReference($log);
    }

    private function extractOrderReference(object $log): string
    {
        if (in_array($log->source_marketplace, ['shopee_order', 'tiktok_order'], true)) {
            return trim((string) $log->sku);
        }

        if (preg_match('/(?:Shopee|TikTok) order ([A-Z0-9]+)/i', (string) $log->message, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function formatShopeeOrderDetail(array $order): array
    {
        $items = [];
        foreach (($order['item_list'] ?? []) as $item) {
            $items[] = [
                'product_name' => $item['item_name'] ?? '-',
                'variant_name' => $item['model_name'] ?? '-',
                'qty' => (int) ($item['model_quantity_purchased'] ?? $item['active_qty'] ?? 0),
                'item_id' => (string) ($item['item_id'] ?? ''),
                'model_id' => (string) ($item['model_id'] ?? ''),
                'seller_sku' => (string) ($item['model_sku'] ?? $item['item_sku'] ?? ''),
                'image_url' => data_get($item, 'image_info.image_url'),
            ];
        }

        return [
            'order_sn' => $order['order_sn'] ?? null,
            'order_status' => $order['order_status'] ?? null,
            'create_time' => isset($order['create_time']) ? Carbon::createFromTimestamp((int) $order['create_time'])->toDateTimeString() : null,
            'update_time' => isset($order['update_time']) ? Carbon::createFromTimestamp((int) $order['update_time'])->toDateTimeString() : null,
            'items' => $items,
        ];
    }

    private function formatTiktokOrderDetail(array $order): array
    {
        $items = [];
        foreach (data_get($order, 'line_items', data_get($order, 'items', [])) as $item) {
            $items[] = [
                'product_name' => $item['product_name'] ?? '-',
                'variant_name' => $item['sku_name'] ?? data_get($item, 'sku.name', '-'),
                'qty' => (int) ($item['quantity'] ?? data_get($item, 'sku.quantity', 1)),
                'item_id' => (string) ($item['product_id'] ?? ''),
                'model_id' => (string) ($item['sku_id'] ?? data_get($item, 'sku.id', '')),
                'seller_sku' => (string) ($item['seller_sku'] ?? data_get($item, 'sku.seller_sku', '')),
                'image_url' => $item['sku_image'] ?? data_get($item, 'sku.image_url'),
            ];
        }

        return [
            'order_sn' => $order['id'] ?? $order['order_id'] ?? null,
            'order_status' => $order['status'] ?? $order['order_status'] ?? data_get($order, 'line_items.0.display_status'),
            'create_time' => isset($order['create_time']) ? Carbon::createFromTimestamp((int) $order['create_time'])->toDateTimeString() : null,
            'update_time' => isset($order['update_time']) ? Carbon::createFromTimestamp((int) $order['update_time'])->toDateTimeString() : null,
            'items' => $items,
        ];
    }

    private function applyOrderSyncFilters($query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $orderClass = trim((string) ($filters['order_class'] ?? ''));
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['date'] ?? '') !== '') {
            $query->whereDate('created_at', $filters['date']);
        }
        if (($filters['type'] ?? '') !== '') {
            $query->where('source_marketplace', $filters['type']);
        }
        if (($filters['runner'] ?? '') !== '' && $this->hasSyncLogColumn('runner')) {
            $runner = strtolower(trim((string) $filters['runner']));
            if ($runner === 'stb') {
                $query->where('runner', 'stb');
            } elseif ($runner === 'pc') {
                $query->where(function ($inner): void {
                    $inner->where('runner', 'like', 'pc%')
                        ->orWhereNull('runner');
                });
            } elseif ($runner === 'online_backup') {
                $query->where('runner', 'online_backup');
            }
        }
        if ($orderClass === 'instant') {
            $query->whereIn('source_marketplace', ['shopee_order', 'tiktok_order'])
                ->where('created_at', '>=', now()->subHour());
        }
        if ($orderClass === 'cancel') {
            $query->whereIn('source_marketplace', ['shopee_order', 'tiktok_order'])
                ->where(function ($inner): void {
                    $inner->where('message', 'ilike', '%cancel%')
                        ->orWhere('message', 'ilike', '%return%')
                        ->orWhere('message', 'ilike', '%refund%')
                        ->orWhere('message', 'ilike', '%restore%')
                        ->orWhereRaw('(old_stock IS NOT NULL AND new_stock IS NOT NULL AND new_stock > old_stock)');
                });
        }
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('sku', 'like', '%'.$search.'%')
                    ->orWhere('message', 'like', '%'.$search.'%');
            });
        }
    }

    public function safetySummary(): array
    {
        $lastRun = $this->lastSafetyRun();

        return [
            'last_run' => $lastRun,
            'next_run' => $this->nextSafetyRun(),
            'total_checked' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'safety_check')
                ->whereDate('created_at', Carbon::today())
                ->count(),
            'total_corrected' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'safety_check')
                ->where('status', 'success')
                ->whereDate('created_at', Carbon::today())
                ->count(),
        ];
    }

    public function processMarketplaceStockChange(string $sourceMarketplace, string $eventType, string $sku, int $qty, array $payload = []): array
    {
        $sourceMarketplace = strtolower($sourceMarketplace);
        $targetMarketplace = $sourceMarketplace === 'shopee' ? 'tiktok' : 'shopee';
        $mapping = $this->findSkuMapping($sku);

        if (! $mapping) {
            $this->logSync($sourceMarketplace, $targetMarketplace, $sku, null, null, 'error', 'SKU mapping tidak ditemukan.');
            return [
                'status' => 'error',
                'message' => 'SKU mapping tidak ditemukan.',
            ];
        }

        $oldStock = $this->currentStockForMarketplace($sourceMarketplace, $mapping) ?? (int) ($mapping->stock_qty ?? 0);
        $newStock = $this->resolveWebhookStock($payload, $oldStock, $qty);
        $canonicalSku = $this->canonicalSku($mapping, $sku);

        $this->updateLocalStock($mapping, $sourceMarketplace, $newStock);
        $this->updateLocalStock($mapping, $targetMarketplace, $newStock);
        $pushResult = $this->pushTargetStock($mapping, $targetMarketplace, $newStock);
        $this->updateStatus($sourceMarketplace, ['last_sync_at' => now(), 'status' => 'connected']);
        $this->updateStatus($targetMarketplace, ['last_sync_at' => now(), 'status' => 'connected']);

        $message = sprintf('%s %s diproses: stok %s -> %s. %s', strtoupper($sourceMarketplace), $eventType, $oldStock, $newStock, $pushResult['message']);
        $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
        $this->logSync($sourceMarketplace, $targetMarketplace, $canonicalSku, $oldStock, $newStock, $status, $message);

        return [
            'status' => $status,
            'message' => $message,
            'sku' => $canonicalSku,
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'target_marketplace' => $targetMarketplace,
            'push' => $pushResult,
        ];
    }

    public function logWebhook(string $marketplace, string $eventType, ?string $sku, ?int $qty, array $payload, string $status, ?string $message = null): int
    {
        $id = DB::table('marketplace_webhook_logs')->insertGetId([
            'marketplace' => strtolower($marketplace),
            'event_type' => $eventType,
            'sku' => $sku,
            'qty' => $qty,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->updateStatus($marketplace, ['last_webhook_at' => now(), 'status' => $status === 'error' ? 'disconnected' : 'connected']);

        return (int) $id;
    }

    public function logSync(?string $source, ?string $target, ?string $sku, ?int $oldStock, ?int $newStock, string $status, ?string $message = null): int
    {
        $payload = [
            'source_marketplace' => $source,
            'target_marketplace' => $target,
            'sku' => $sku,
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'status' => $status,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($this->hasSyncLogColumn('runner')) {
            $payload['runner'] = $this->currentRunner();
        }

        if ($this->hasSyncLogColumn('machine_name')) {
            $payload['machine_name'] = gethostname() ?: null;
        }

        $id = (int) DB::table('marketplace_sync_logs')->insertGetId($payload);

        try {
            $this->failureNotifier->notifySyncLog(['id' => $id, ...$payload]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $id;
    }

    public function decorateSyncLogRows(iterable $rows, string $origin = 'local'): array
    {
        return collect($rows)->map(function ($row) use ($origin): array {
            $data = (array) $row;
            $runner = $this->normalizeRunner($data['runner'] ?? null, $origin);

            return [
                ...$data,
                'runner' => $runner,
                'runner_label' => $this->runnerLabel($runner),
                'machine_name' => $data['machine_name'] ?? null,
                'log_origin' => $origin,
                'log_key' => $origin.':'.($data['id'] ?? md5(json_encode($data))),
            ];
        })->all();
    }

    public function findSkuMapping(string $sku): ?object
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        return DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join): void {
                $join->on(DB::raw('CAST(spm.item_id AS TEXT)'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('CAST(spm.model_id AS TEXT)'), '=', 'sm.shopee_sku');
            })
            ->leftJoin('tiktok_products as tp', function ($join): void {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->where(function ($query) use ($sku): void {
                $query->where('sm.internal_sku', $sku)
                    ->orWhere('sm.shopee_seller_sku', $sku)
                    ->orWhere('sm.tiktok_seller_sku', $sku)
                    ->orWhere('sm.tiktok_sku', $sku)
                    ->orWhere('map.seller_sku', $sku)
                    ->orWhere('map.tiktok_sku_id', $sku)
                    ->orWhere('spm.model_sku', $sku)
                    ->orWhere('tp.seller_sku', $sku)
                    ->orWhere('tp.sku_id', $sku);
            })
            ->select(
                'sm.*',
                'map.seller_sku as mapped_seller_sku',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id as mapped_tiktok_sku_id',
                'map.tiktok_sku_name as mapped_tiktok_sku_name',
                'spm.stock as shopee_stock',
                'spm.model_sku as shopee_model_sku',
                'tp.stock_qty as tiktok_stock',
                'tp.seller_sku as tiktok_product_seller_sku'
            )
            ->first();
    }

    public function findSkuMappingByTiktokOrderItem(string $productId = '', string $skuId = '', string $sellerSku = ''): ?object
    {
        $productId = trim($productId);
        $skuId = trim($skuId);
        $sellerSku = trim($sellerSku);

        if ($sellerSku !== '') {
            $mapping = $this->findSkuMapping($sellerSku);
            if ($mapping) {
                return $mapping;
            }
        }

        if ($skuId === '') {
            return null;
        }

        $mapping = DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join): void {
                $join->on(DB::raw('CAST(spm.item_id AS TEXT)'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('CAST(spm.model_id AS TEXT)'), '=', 'sm.shopee_sku');
            })
            ->leftJoin('tiktok_products as tp', function ($join): void {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->leftJoin('tiktok_products as order_tp', function ($join) use ($productId, $skuId): void {
                $join->where('order_tp.sku_id', '=', $skuId);
                if ($productId !== '') {
                    $join->where('order_tp.product_id', '=', $productId);
                }
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->where(function ($query) use ($skuId): void {
                $query->where('sm.tiktok_sku', $skuId)
                    ->orWhere('map.tiktok_sku_id', $skuId)
                    ->orWhere('tp.sku_id', $skuId)
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('order_tp.seller_sku')
                            ->where(function ($sellerMatch): void {
                                $sellerMatch->whereColumn('order_tp.seller_sku', 'sm.internal_sku')
                                    ->orWhereColumn('order_tp.seller_sku', 'sm.shopee_seller_sku')
                                    ->orWhereColumn('order_tp.seller_sku', 'sm.tiktok_seller_sku')
                                    ->orWhereColumn('order_tp.seller_sku', 'map.seller_sku')
                                    ->orWhereColumn('order_tp.seller_sku', 'spm.model_sku');
                            });
                    });
            })
            ->select(
                'sm.*',
                'map.seller_sku as mapped_seller_sku',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id as mapped_tiktok_sku_id',
                'map.tiktok_sku_name as mapped_tiktok_sku_name',
                'spm.stock as shopee_stock',
                'spm.model_sku as shopee_model_sku',
                DB::raw('COALESCE(tp.stock_qty, order_tp.stock_qty) as tiktok_stock'),
                DB::raw('COALESCE(tp.seller_sku, order_tp.seller_sku) as tiktok_product_seller_sku'),
                'order_tp.product_id as order_tiktok_product_id',
                'order_tp.sku_id as order_tiktok_sku_id'
            )
            ->first();

        if (! $mapping) {
            return null;
        }

        $mapping->tiktok_product_id = $mapping->tiktok_product_id ?: ($mapping->mapped_tiktok_product_id ?: $mapping->order_tiktok_product_id);
        $mapping->tiktok_sku = $mapping->tiktok_sku ?: ($mapping->mapped_tiktok_sku_id ?: $mapping->order_tiktok_sku_id);
        $mapping->tiktok_seller_sku = $mapping->tiktok_seller_sku ?: $mapping->tiktok_product_seller_sku;

        return $mapping;
    }

    public function activeSkuMappings()
    {
        return DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join): void {
                $join->on(DB::raw('CAST(spm.item_id AS TEXT)'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('CAST(spm.model_id AS TEXT)'), '=', 'sm.shopee_sku');
            })
            ->leftJoin('tiktok_products as tp', function ($join): void {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->where(function ($query): void {
                $query->whereNotNull('sm.shopee_sku')
                    ->orWhereNotNull('sm.tiktok_sku')
                    ->orWhereNotNull('map.seller_sku');
            })
            ->select(
                'sm.*',
                'map.seller_sku as mapped_seller_sku',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id as mapped_tiktok_sku_id',
                'map.tiktok_sku_name as mapped_tiktok_sku_name',
                'spm.stock as shopee_stock',
                'tp.stock_qty as tiktok_stock'
            )
            ->orderBy('sm.product_name')
            ->orderBy('sm.variant_name')
            ->get();
    }

    public function syncShopeeStocksToTiktok(bool $forceLive = false): array
    {
        $summary = [
            'status' => 'success',
            'message' => 'Sinkronisasi stok Shopee ke TikTok selesai.',
            'checked' => 0,
            'pushed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'skipped_inactive_tiktok' => 0,
            'skipped_missing_shopee_stock' => 0,
            'skipped_missing_tiktok_sku' => 0,
            'failed' => 0,
            'live_push' => $forceLive || $this->livePushEnabled(),
        ];

        foreach ($this->activeSkuMappings() as $mapping) {
            $summary['checked']++;
            $sku = $this->canonicalSku($mapping);
            $shopeeStock = $mapping->shopee_stock === null ? null : (int) $mapping->shopee_stock;
            $tiktokStock = $mapping->tiktok_stock === null ? null : (int) $mapping->tiktok_stock;

            if ($shopeeStock === null) {
                $summary['skipped']++;
                $summary['skipped_missing_shopee_stock']++;
                $this->logSync('manual_shopee_master', 'tiktok', $sku, $tiktokStock, null, 'skipped', 'Dilewati: stok Shopee tidak tersedia di cache.');
                continue;
            }

            $tiktokSku = $this->resolveTiktokSku($mapping);
            if (! $tiktokSku) {
                $summary['skipped']++;
                $inactiveTiktokSku = $this->resolveTiktokSku($mapping, false);
                $message = $inactiveTiktokSku
                    ? 'Dilewati: SKU TikTok ditemukan tetapi statusnya nonaktif.'
                    : 'Dilewati: SKU TikTok aktif tidak ditemukan.';
                if ($inactiveTiktokSku) {
                    $summary['skipped_inactive_tiktok']++;
                } else {
                    $summary['skipped_missing_tiktok_sku']++;
                }
                $this->logSync('manual_shopee_master', 'tiktok', $sku, $tiktokStock, $shopeeStock, 'skipped', $message);
                continue;
            }
            $mapping->tiktok_product_id = (string) $tiktokSku->product_id;
            $mapping->tiktok_sku = (string) $tiktokSku->sku_id;
            $mapping->tiktok_stock = $tiktokSku->stock_qty === null ? $tiktokStock : (int) $tiktokSku->stock_qty;
            $tiktokStock = $mapping->tiktok_stock;

            if ($tiktokStock !== null && $tiktokStock === $shopeeStock) {
                $summary['unchanged']++;
                $this->logSync('manual_shopee_master', 'tiktok', $sku, $tiktokStock, $shopeeStock, 'success', 'Manual Shopee -> TikTok: stok sudah sama, tidak perlu push.');
                continue;
            }

            $pushResult = $this->pushTargetStock($mapping, 'tiktok', $shopeeStock, $forceLive);
            $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
            if ($status === 'error') {
                $summary['failed']++;
            } else {
                $summary['pushed']++;
                $this->updateLocalStock($mapping, 'tiktok', $shopeeStock);
            }

            $this->logSync(
                'manual_shopee_master',
                'tiktok',
                $sku,
                $tiktokStock,
                $shopeeStock,
                $status,
                sprintf('Manual Shopee -> TikTok: stok TikTok %s -> %s. %s', $tiktokStock ?? '-', $shopeeStock, $pushResult['message'] ?? '-')
            );
        }

        if ($summary['failed'] > 0) {
            $summary['status'] = 'warning';
            $summary['message'] = 'Sinkronisasi selesai, tetapi sebagian SKU gagal dipush ke TikTok.';
        }

        $this->updateStatus('shopee', ['last_sync_at' => now(), 'status' => 'connected']);
        $this->updateStatus('tiktok', ['last_sync_at' => now(), 'status' => $summary['failed'] > 0 ? 'disconnected' : 'connected']);

        return $summary;
    }

    public function mirrorShopeeStockToTiktok(object $mapping, string $reason, bool $forceLive = false, bool $allowCachedFallback = false): array
    {
        $sku = $this->canonicalSku($mapping);
        $itemId = trim((string) ($mapping->shopee_product_id ?? ''));
        $modelId = trim((string) ($mapping->shopee_sku ?? ''));
        if ($itemId === '' || $modelId === '') {
            $this->logSync('shopee_stock_refresh', 'tiktok', $sku, null, null, 'skipped', 'Mirror dilewati: item/model Shopee belum lengkap.');
            return ['status' => 'skipped', 'message' => 'item/model Shopee belum lengkap.', 'sku' => $sku];
        }

        $stockResult = $this->apiService->fetchShopeeModelStock($itemId, $modelId);
        if (($stockResult['status'] ?? '') !== 'success') {
            if (! $allowCachedFallback) {
                $this->logSync('shopee_stock_refresh', 'tiktok', $sku, null, null, 'error', $stockResult['message'] ?? 'Stok Shopee gagal diambil.');
                return ['status' => 'error', 'message' => $stockResult['message'] ?? 'Stok Shopee gagal diambil.', 'sku' => $sku];
            }

            $stockResult = [
                'status' => 'success',
                'stock' => (int) ($mapping->shopee_stock ?? $mapping->stock_qty ?? 0),
                'message' => 'Fallback stok lokal karena stok model Shopee live tidak tersedia.',
            ];
        }

        $oldStock = $mapping->tiktok_stock === null ? null : (int) $mapping->tiktok_stock;
        $newStock = (int) $stockResult['stock'];
        $this->updateLocalStock($mapping, 'shopee', $newStock);

        $tiktokSku = $this->resolveTiktokSku($mapping);
        if ($tiktokSku) {
            $mapping->tiktok_product_id = (string) $tiktokSku->product_id;
            $mapping->tiktok_sku = (string) $tiktokSku->sku_id;
        }

        $pushResult = $this->pushTargetStock($mapping, 'tiktok', $newStock, $forceLive);
        $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
        if ($status === 'error' && str_contains((string) ($pushResult['message'] ?? ''), 'SKU TikTok aktif tidak ditemukan')) {
            $status = 'skipped';
        }
        if ($status === 'success') {
            $this->updateLocalStock($mapping, 'tiktok', $newStock);
        }

        $prefix = isset($stockResult['message']) ? $stockResult['message'].' ' : '';
        $message = sprintf('%s: %sstok Shopee terbaru %s, TikTok %s -> %s. %s', $reason, $prefix, $newStock, $oldStock ?? '-', $newStock, $pushResult['message'] ?? '-');
        $this->logSync('shopee_stock_refresh', 'tiktok', $sku, $oldStock, $newStock, $status, $message);
        $this->updateStatus('shopee', ['last_sync_at' => now(), 'status' => 'connected']);
        $this->updateStatus('tiktok', ['last_sync_at' => now(), 'status' => $status === 'error' ? 'disconnected' : 'connected']);

        return [
            'status' => $status,
            'message' => $message,
            'sku' => $sku,
            'old_stock' => $oldStock,
            'new_stock' => $newStock,
            'push' => $pushResult,
        ];
    }

    public function findSkuMappingByShopeeModel(string $itemId, string $modelId, bool $includeHidden = false): ?object
    {
        $itemId = trim($itemId);
        $modelId = trim($modelId);
        if ($itemId === '' || $modelId === '') {
            return null;
        }

        return DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join): void {
                $join->on(DB::raw('CAST(spm.item_id AS TEXT)'), '=', 'sm.shopee_product_id')
                    ->on(DB::raw('CAST(spm.model_id AS TEXT)'), '=', 'sm.shopee_sku');
            })
            ->leftJoin('tiktok_products as tp', function ($join): void {
                $join->on('tp.product_id', '=', 'sm.tiktok_product_id')
                    ->on('tp.sku_id', '=', 'sm.tiktok_sku')
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->when(! $includeHidden, function ($query): void {
                $query->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false');
            })
            ->where(function ($query) use ($itemId, $modelId): void {
                $query->where(function ($inner) use ($itemId, $modelId): void {
                    $inner->where('sm.shopee_product_id', $itemId)->where('sm.shopee_sku', $modelId);
                })->orWhere(function ($inner) use ($itemId, $modelId): void {
                    $inner->where('map.shopee_item_id', $itemId)->where('map.shopee_model_id', $modelId);
                });
            })
            ->select(
                'sm.*',
                'map.seller_sku as mapped_seller_sku',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id as mapped_tiktok_sku_id',
                'map.tiktok_sku_name as mapped_tiktok_sku_name',
                'spm.stock as shopee_stock',
                'tp.stock_qty as tiktok_stock'
            )
            ->first();
    }

    public function updateLocalStock(object $mapping, string $marketplace, int $stock): void
    {
        DB::table('stock_master')->where('id', (int) $mapping->id)->update([
            'stock_qty' => $stock,
            'updated_at' => now(),
        ]);

        if ($marketplace === 'shopee' && ($mapping->shopee_product_id ?? null) && ($mapping->shopee_sku ?? null)) {
            DB::table('shopee_product_model')
                ->where('item_id', $mapping->shopee_product_id)
                ->where('model_id', $mapping->shopee_sku)
                ->update(['stock' => $stock, 'updated_at' => now()]);
        }

        if ($marketplace === 'tiktok' && ($mapping->tiktok_product_id ?? null) && ($mapping->tiktok_sku ?? null)) {
            DB::table('tiktok_products')
                ->where('product_id', $mapping->tiktok_product_id)
                ->where('sku_id', $mapping->tiktok_sku)
                ->update(['stock_qty' => $stock, 'updated_at' => now()]);
        }
    }

    public function canonicalSku(object $mapping, ?string $fallback = null): string
    {
        return trim((string) (
            $mapping->internal_sku
            ?? $mapping->mapped_seller_sku
            ?? $mapping->shopee_seller_sku
            ?? $mapping->tiktok_seller_sku
            ?? $fallback
            ?? ''
        ));
    }

    public function currentStockForMarketplace(string $marketplace, object $mapping): ?int
    {
        if ($marketplace === 'shopee') {
            return $this->firstStockValue($mapping, ['shopee_stock', 'stock_qty']);
        }

        if ($marketplace === 'tiktok') {
            return $this->firstStockValue($mapping, ['tiktok_stock', 'stock_qty']);
        }

        return null;
    }

    public function findSkuMappingByStockMasterId(int $stockMasterId): ?object
    {
        return DB::table('stock_master')->where('id', $stockMasterId)->first();
    }

    private function firstStockValue(object $mapping, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (! property_exists($mapping, $key)) {
                continue;
            }

            $value = $mapping->{$key};
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }

    public function updateStatus(string $marketplace, array $values): void
    {
        $marketplace = strtolower($marketplace);
        $exists = DB::table('marketplace_sync_status')->where('marketplace', $marketplace)->exists();
        $payload = [
            ...$values,
            'updated_at' => now(),
        ];
        if (! $exists) {
            $payload['created_at'] = now();
        }

        DB::table('marketplace_sync_status')->updateOrInsert(['marketplace' => $marketplace], $payload);
    }

    public function pushTargetStockForAccount(object $mapping, string $targetAccountKey, int $stock, bool $forceLive = false, ?string $idempotencyKey = null): array
    {
        if (! $forceLive && ! $this->livePushEnabled()) {
            return ['status' => 'dry_run', 'message' => 'Live push disabled (AUTO_SYNC_PUSH_LIVE=false).'];
        }

        $targetAccountKey = trim($targetAccountKey);
        if (in_array($targetAccountKey, ['shopee-agnishopbjm', 'shopee-gitacollectionbjm'], true)) {
            $prefix = $targetAccountKey === 'shopee-gitacollectionbjm' ? 'shopee_gita_' : 'shopee_';
            $itemId = trim((string) ($mapping->{$prefix.'product_id'} ?? ''));
            $modelId = trim((string) ($mapping->{$prefix.'sku'} ?? $mapping->{$prefix.'model_id'} ?? ''));
            if ($itemId === '' || $modelId === '') {
                return ['status' => 'skipped', 'message' => 'Push Shopee dibatalkan: item/model target belum lengkap.'];
            }

            return $this->apiService->updateShopeeModelStockForAccount($targetAccountKey, $itemId, $modelId, $stock, $idempotencyKey);
        }

        if ($targetAccountKey === 'tiktok-agnishopbjm') {
            $productId = trim((string) ($mapping->tiktok_product_id ?? $mapping->mapped_tiktok_product_id ?? ''));
            $skuId = trim((string) ($mapping->tiktok_sku ?? $mapping->mapped_tiktok_sku_id ?? ''));
            if ($productId === '' || $skuId === '') {
                return ['status' => 'skipped', 'message' => 'Push TikTok dibatalkan: product/SKU target belum lengkap.'];
            }

            return $this->apiService->updateTiktokStockForAccount($targetAccountKey, $productId, $skuId, $stock, null, $idempotencyKey);
        }

        return ['status' => 'error', 'message' => 'Akun target marketplace tidak didukung.'];
    }

    public function pushTargetStock(object $mapping, string $targetMarketplace, int $stock, bool $forceLive = false): array
    {
        if (! $forceLive && ! $this->livePushEnabled()) {
            return [
                'status' => 'dry_run',
                'message' => 'Live push disabled (AUTO_SYNC_PUSH_LIVE=false); stok baru disimpan ke cache lokal.',
            ];
        }

        return $targetMarketplace === 'tiktok'
            ? $this->pushTiktokStock($mapping, $stock)
            : $this->pushShopeeStock($mapping, $stock);
    }

    private function marketplaceStatus(string $marketplace, Carbon $today): array
    {
        $row = DB::table('marketplace_sync_status')->where('marketplace', $marketplace)->first();

        return [
            'marketplace' => $marketplace,
            'status' => $row->status ?? 'disconnected',
            'connected' => ($row->status ?? '') === 'connected',
            'last_webhook_at' => $row->last_webhook_at ?? null,
            'last_sync_at' => $row->last_sync_at ?? null,
            'total_webhook_today' => (int) DB::table('marketplace_webhook_logs')
                ->where('marketplace', $marketplace)
                ->whereDate('created_at', $today)
                ->count(),
        ];
    }

    private function livePushEnabled(): bool
    {
        return filter_var(env('AUTO_SYNC_PUSH_LIVE', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function stbHeavyFeaturesDisabled(): bool
    {
        return (bool) config('stb.sync_worker', false)
            && ! (bool) config('stb.features.stock_analysis', false);
    }

    private function ensureSkuMappingVisibilityColumns(): void
    {
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS is_hidden_from_mapping BOOLEAN DEFAULT FALSE");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS hidden_from_mapping_reason TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS hidden_from_mapping_at TIMESTAMP NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS hidden_from_mapping_by VARCHAR(255) NULL");
    }

    private function autoHideInactiveStockMasterMappings(): int
    {
        if (! Schema::hasTable('stock_master')) {
            return 0;
        }

        $this->ensureSkuMappingVisibilityColumns();

        return DB::table('stock_master as sm')
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->where(function ($query): void {
                $query->whereRaw("NULLIF(COALESCE(sm.shopee_product_id, ''), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(COALESCE(sm.shopee_sku, ''), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(COALESCE(sm.tiktok_product_id, ''), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(COALESCE(sm.tiktok_sku, ''), '') IS NOT NULL");
            })
            ->whereRaw("
                NOT EXISTS (
                    SELECT 1
                    FROM shopee_product sp
                    JOIN shopee_product_model spm ON spm.item_id = sp.item_id
                    WHERE COALESCE(sp.is_active, true) = true
                      AND (
                        NULLIF(COALESCE(sm.shopee_product_id, ''), '') IS NULL
                        OR (
                            sm.shopee_product_id ~ '^[0-9]+$'
                            AND sp.item_id = sm.shopee_product_id::BIGINT
                        )
                      )
                      AND (
                        NULLIF(COALESCE(sm.shopee_sku, ''), '') IS NULL
                        OR spm.model_id = sm.shopee_sku
                      )
                      AND (
                        NULLIF(COALESCE(sm.variant_name, ''), '') IS NULL
                        OR LOWER(TRIM(spm.name)) = LOWER(TRIM(sm.variant_name))
                      )
                )
            ")
            ->whereRaw("
                NOT EXISTS (
                    SELECT 1
                    FROM tiktok_products tp
                    WHERE COALESCE(tp.is_active, true) = true
                      AND (
                        (
                            NULLIF(COALESCE(sm.tiktok_product_id, ''), '') IS NOT NULL
                            AND tp.product_id = sm.tiktok_product_id
                            AND (
                                NULLIF(COALESCE(sm.tiktok_sku, ''), '') IS NULL
                                OR tp.sku_id = sm.tiktok_sku
                            )
                        )
                        OR (
                            NULLIF(COALESCE(sm.tiktok_seller_sku, ''), '') IS NOT NULL
                            AND tp.seller_sku = sm.tiktok_seller_sku
                        )
                        OR (
                            NULLIF(COALESCE(sm.shopee_seller_sku, ''), '') IS NOT NULL
                            AND tp.seller_sku = sm.shopee_seller_sku
                        )
                    )
                )
            ")
            ->update([
                'is_hidden_from_mapping' => DB::raw('true'),
                'hidden_from_mapping_reason' => 'Auto-hide: varian marketplace aktif tidak ditemukan saat anomali stok dibuka.',
                'hidden_from_mapping_at' => now(),
                'hidden_from_mapping_by' => 'system',
                'updated_at' => now(),
            ]);
    }

    private function orderSyncSummary(Carbon $today): array
    {
        $lastOrderSync = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->max('created_at');
        $lastError = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->where('status', 'error')
            ->max('created_at');
        $latestIssue = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $openIssueQuery = DB::table('marketplace_sync_logs')
            ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
            ->whereIn('status', ['error', 'skipped']);
        if ($lastOrderSync) {
            $openIssueQuery->where('created_at', '>', $lastOrderSync);
        } else {
            $openIssueQuery->whereDate('created_at', $today);
        }
        $latestOpenIssue = (clone $openIssueQuery)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return [
            'status' => $lastError && (! $lastOrderSync || $lastError >= $lastOrderSync) ? 'warning' : 'active',
            'polling_interval' => sprintf(
                'Shopee + TikTok Every %d Minute%s',
                (int) config('stb.intervals.order_sync_minutes', 5),
                (int) config('stb.intervals.order_sync_minutes', 5) === 1 ? '' : 's'
            ),
            'last_order_sync_at' => $lastOrderSync ? (string) $lastOrderSync : null,
            'last_error_at' => $lastError ? (string) $lastError : null,
            'open_issues' => (int) (clone $openIssueQuery)->count(),
            'latest_open_issue_at' => $latestOpenIssue?->created_at ? (string) $latestOpenIssue->created_at : null,
            'latest_open_issue_status' => $latestOpenIssue?->status,
            'latest_open_issue_message' => $latestOpenIssue?->message,
            'errors_today' => (int) DB::table('marketplace_sync_logs')
                ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
                ->where('status', 'error')
                ->whereDate('created_at', $today)
                ->count(),
            'skipped_today' => (int) DB::table('marketplace_sync_logs')
                ->whereIn('source_marketplace', ['shopee_order', 'shopee_stock_refresh', 'tiktok_order'])
                ->where('status', 'skipped')
                ->whereDate('created_at', $today)
                ->count(),
            'latest_issue_at' => $latestIssue?->created_at ? (string) $latestIssue->created_at : null,
            'latest_issue_status' => $latestIssue?->status,
            'latest_issue_message' => $latestIssue?->message,
            'shopee_orders_processed_today' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'shopee_order')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
            'tiktok_orders_processed_today' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'tiktok_order')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
            'stock_pushes_today' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'shopee_stock_refresh')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
            'tiktok_to_shopee_pushes_today' => (int) DB::table('marketplace_sync_logs')
                ->where('source_marketplace', 'tiktok_order')
                ->where('target_marketplace', 'shopee')
                ->where('status', 'success')
                ->whereDate('created_at', $today)
                ->count(),
        ];
    }

    private function pushShopeeStock(object $mapping, int $stock): array
    {
        $itemId = trim((string) ($mapping->shopee_product_id ?? ''));
        $modelId = trim((string) ($mapping->shopee_sku ?? ''));
        if ($itemId === '' || $modelId === '') {
            return ['status' => 'error', 'message' => 'Push Shopee gagal: item_id/model_id belum lengkap.'];
        }
        $modelExists = DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->where('model_id', $modelId)
            ->exists();
        if (! $modelExists) {
            return ['status' => 'error', 'message' => 'Push Shopee dibatalkan: model Shopee aktif tidak ditemukan di cache.'];
        }

        $accountKey = trim((string) config('shopee.account_key', 'shopee-agnishopbjm'));
        $token = DB::table('shopee_tokens')
            ->when($accountKey !== '', function ($query) use ($accountKey): void {
                $query->where('account_key', $accountKey);
            })
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('created_at')
            ->first();
        if (! $token || trim((string) $token->access_token) === '' || (int) $token->shop_id <= 0) {
            return ['status' => 'error', 'message' => 'Push Shopee gagal: token aktif belum tersedia.'];
        }

        $payload = [
            'item_id' => (int) $itemId,
            'stock_list' => [
                [
                    'model_id' => (int) $modelId,
                    'seller_stock' => [
                        ['stock' => $stock],
                    ],
                ],
            ],
        ];
        $response = $this->shopeeSignedPost('/api/v2/product/update_stock', (int) $token->shop_id, (string) $token->access_token, $payload);
        if (($response['error'] ?? '') !== '') {
            return [
                'status' => 'error',
                'message' => $response['message'] ?? $response['error'] ?? 'Push Shopee gagal.',
                'response' => $response,
            ];
        }

        return ['status' => 'success', 'message' => 'Live push Shopee berhasil dikirim.', 'response' => $response];
    }

    private function pushTiktokStock(object $mapping, int $stock): array
    {
        $productId = trim((string) ($mapping->tiktok_product_id ?? ''));
        $skuId = trim((string) ($mapping->tiktok_sku ?? ''));
        $activeSku = $this->resolveTiktokSku($mapping);
        if (! $activeSku) {
            return ['status' => 'error', 'message' => 'Push TikTok dibatalkan: SKU TikTok aktif tidak ditemukan di cache.'];
        }
        $productId = (string) $activeSku->product_id;
        $skuId = (string) $activeSku->sku_id;
        $warehouseId = trim((string) config('tiktok.default_warehouse_id', ''));
        if ($warehouseId === '') {
            $warehouseId = trim((string) ($activeSku->warehouse_id ?? ''));
        }
        if ($warehouseId === '') {
            return ['status' => 'error', 'message' => 'Push TikTok gagal: warehouse_id belum tersedia pada konfigurasi atau cache SKU.'];
        }

        $token = DB::table('tiktok_tokens')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('created_at')
            ->first();
        $shop = DB::table('tiktok_shops')->orderByDesc('updated_at')->first();
        if (! $token || trim((string) $token->access_token) === '') {
            return ['status' => 'error', 'message' => 'Push TikTok gagal: token aktif belum tersedia.'];
        }
        $shopCipher = trim((string) ($shop->cipher ?? $shop->shop_cipher ?? ''));
        if ($shopCipher === '') {
            return ['status' => 'error', 'message' => 'Push TikTok gagal: shop_cipher belum tersedia.'];
        }

        $config = config('tiktok');
        $path = '/product/202309/products/'.$productId.'/inventory/update';
        $query = [
            'app_key' => $config['app_key'],
            'access_token' => (string) $token->access_token,
            'shop_cipher' => $shopCipher,
            'timestamp' => time(),
        ];
        $body = [
            'skus' => [
                [
                    'id' => $skuId,
                    'inventory' => [
                        [
                            'warehouse_id' => $warehouseId,
                            'quantity' => $stock,
                        ],
                    ],
                ],
            ],
        ];
        $bodyString = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $config['app_secret'], $bodyString);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => (string) $token->access_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->withBody($bodyString, 'application/json')
            ->post($config['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            return [
                'status' => 'error',
                'message' => is_array($payload) ? ($payload['message'] ?? 'Push TikTok gagal.') : 'TikTok tidak mengembalikan JSON valid.',
                'http_status' => $response->status(),
            ];
        }

        return ['status' => 'success', 'message' => 'Live push TikTok berhasil dikirim.'];
    }

    private function resolveTiktokSku(object $mapping, bool $activeOnly = true): ?object
    {
        $activeCondition = $activeOnly ? 'COALESCE(is_active, true) = true' : 'COALESCE(is_active, true) = false';
        $productId = trim((string) ($mapping->tiktok_product_id ?? $mapping->mapped_tiktok_product_id ?? ''));
        $skuId = trim((string) ($mapping->tiktok_sku ?? $mapping->mapped_tiktok_sku_id ?? ''));
        if ($productId !== '' && $skuId !== '') {
            $activeSku = DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->where('sku_id', $skuId)
                ->whereRaw($activeCondition)
                ->first();
            if ($activeSku) {
                return $activeSku;
            }
        }

        $sellerSkuCandidates = [
            $mapping->internal_sku ?? null,
            $mapping->mapped_seller_sku ?? null,
            $mapping->tiktok_seller_sku ?? null,
        ];
        $shopeeSellerSku = trim((string) ($mapping->shopee_seller_sku ?? ''));
        if ($shopeeSellerSku !== '' && ! $this->hasAmbiguousShopeeSellerSkuFallback($mapping, $shopeeSellerSku)) {
            $sellerSkuCandidates[] = $shopeeSellerSku;
        }
        $sellerSkus = array_values(array_unique(array_filter(array_map(
            fn ($value): string => trim((string) $value),
            $sellerSkuCandidates
        ))));
        if ($sellerSkus !== []) {
            $activeSku = DB::table('tiktok_products')
                ->whereIn('seller_sku', $sellerSkus)
                ->whereRaw($activeCondition)
                ->orderByDesc('updated_at')
                ->first();
            if ($activeSku) {
                return $activeSku;
            }
        }

        $productName = $this->normalizeSkuMatchValue((string) ($mapping->product_name ?? ''));
        $variantName = $this->normalizeSkuMatchValue((string) ($mapping->variant_name ?? $mapping->mapped_tiktok_sku_name ?? ''));
        if ($productName === '' || $variantName === '') {
            return null;
        }

        return DB::table('tiktok_products')
            ->whereRaw($activeCondition)
            ->whereRaw('LOWER(product_name) = ?', [mb_strtolower((string) ($mapping->product_name ?? ''))])
            ->get()
            ->first(function ($row) use ($variantName): bool {
                return $this->normalizeSkuMatchValue((string) ($row->sku_name ?? '')) === $variantName;
            });
    }

    private function hasAmbiguousShopeeSellerSkuFallback(object $mapping, string $sellerSku): bool
    {
        $canonicalSku = $this->canonicalSku($mapping);
        if ($canonicalSku === '' || $sellerSku === $canonicalSku || ! Schema::hasTable('stock_master')) {
            return false;
        }

        return DB::table('stock_master')
            ->where('shopee_seller_sku', $sellerSku)
            ->where('internal_sku', '<>', $canonicalSku)
            ->exists();
    }

    private function ambiguousTiktokSkuForShopeeSellerSku(object $mapping): ?object
    {
        $sellerSku = trim((string) ($mapping->shopee_seller_sku ?? ''));
        if ($sellerSku === '' || ! $this->hasAmbiguousShopeeSellerSkuFallback($mapping, $sellerSku)) {
            return null;
        }

        return DB::table('tiktok_products')
            ->where('seller_sku', $sellerSku)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function normalizeSkuMatchValue(mixed $value): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function generateTiktokWriteSign(string $path, array $params, string $secret): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $base = $secret.$path;
        foreach ($params as $key => $value) {
            $base .= $key.$value;
        }
        $base .= $secret;

        return hash_hmac('sha256', $base, $secret);
    }

    private function generateTiktokSign(string $path, array $params, string $secret, ?string $body = null): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $base = $secret.$path;
        foreach ($params as $key => $value) {
            $base .= $key.$value;
        }
        $base .= $body ?? '';
        $base .= $secret;

        return hash_hmac('sha256', $base, $secret);
    }

    private function shopeeSignedPost(string $path, int $shopId, string $accessToken, array $payload): array
    {
        $config = config('shopee');
        $timestamp = time();
        $query = [
            'partner_id' => (int) $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->generateShopeeApiSign((int) $config['partner_id'], (string) $config['partner_key'], $path, $timestamp, $accessToken, $shopId),
        ];

        $response = Http::timeout(45)
            ->acceptJson()
            ->post($config['host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $payload);
        $data = $response->json();

        if (! is_array($data)) {
            return [
                'error' => 'invalid_json',
                'message' => 'Shopee tidak mengembalikan JSON valid.',
                '_http_status' => $response->status(),
                '_body' => $response->body(),
            ];
        }

        return [
            ...$data,
            '_http_status' => $response->status(),
        ];
    }

    private function generateShopeeApiSign(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp.$accessToken.$shopId, $partnerKey);
    }

    private function resolveWebhookStock(array $payload, int $oldStock, int $qty): int
    {
        foreach (['new_stock', 'stock', 'stock_qty', 'available_stock'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return max(0, $oldStock - max(0, $qty));
    }

    private function lastSafetyRun(): ?string
    {
        $last = DB::table('marketplace_sync_logs')
            ->where('source_marketplace', 'safety_check')
            ->max('created_at');

        return $last ? (string) $last : null;
    }

    private function nextSafetyRun(): string
    {
        $now = now();
        $minutesToAdd = 15 - ((int) $now->format('i') % 15);
        if ($minutesToAdd === 15 && (int) $now->format('s') === 0) {
            $minutesToAdd = 0;
        }

        return $now->copy()->addMinutes($minutesToAdd)->setSecond(0)->toDateTimeString();
    }

    private function paginateQuery($query, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    private function hasSyncLogColumn(string $column): bool
    {
        try {
            return Schema::hasColumn('marketplace_sync_logs', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function currentRunner(): string
    {
        if ((bool) config('stb.sync_worker', false)) {
            return 'stb';
        }

        return PHP_SAPI === 'cli' ? 'pc_artisan' : 'pc_browser';
    }

    private function normalizeRunner(mixed $runner, string $origin): string
    {
        $runner = trim((string) $runner);
        if ($runner !== '') {
            return $runner;
        }

        return $origin === 'remote_stb' ? 'stb' : 'pc';
    }

    private function runnerLabel(string $runner): string
    {
        return match ($runner) {
            'stb' => 'STB',
            'pc_browser' => 'PC Browser',
            'pc_artisan' => 'PC Artisan',
            'pc' => 'PC',
            'online_backup' => 'Online Backup',
            default => $runner,
        };
    }

    private function emptyPagination(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        return [
            'items' => collect(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => 0,
                'last_page' => 1,
            ],
        ];
    }
}
