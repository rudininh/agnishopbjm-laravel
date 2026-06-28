<?php

namespace App\Services;

use App\Http\Controllers\OmnichannelController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MarketplaceOrderSyncService
{
    public function __construct(
        private readonly MarketplaceApiService $apiService,
        private readonly MarketplaceSyncService $syncService,
    ) {
    }

    public function processShopeeOrder(string $orderSn, string $eventType, array $payload = []): array
    {
        $orderSn = trim($orderSn);
        if ($orderSn === '') {
            return ['status' => 'error', 'message' => 'order_sn Shopee kosong.'];
        }

        $detail = $this->apiService->fetchShopeeOrderDetail($orderSn);
        if (($detail['status'] ?? '') !== 'success') {
            $this->syncService->logSync('shopee_order', 'tiktok', $orderSn, null, null, 'error', $detail['message'] ?? 'Detail order Shopee gagal diambil.');
            return $detail;
        }

        $order = $detail['order'];
        $orderStatus = (string) ($order['order_status'] ?? data_get($payload, 'data.status', 'UNKNOWN'));
        $items = $order['item_list'] ?? [];
        $results = [];
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $productRefreshRefs = [
            'shopee_item_ids' => [],
            'tiktok_product_ids' => [],
        ];

        foreach (is_array($items) ? $items : [] as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            $modelId = (string) ($item['model_id'] ?? '');
            $sellerSku = trim((string) ($item['model_sku'] ?? $item['item_sku'] ?? ''));
            $mapping = $this->syncService->findSkuMappingByShopeeModel($itemId, $modelId, true)
                ?: ($sellerSku !== '' ? $this->syncService->findSkuMapping($sellerSku) : null);

            if (! $mapping) {
                $skipped++;
                $message = sprintf('Order %s item dilewati: SKU mapping tidak ditemukan untuk item_id=%s model_id=%s seller_sku=%s.', $orderSn, $itemId, $modelId, $sellerSku ?: '-');
                $this->syncService->logSync('shopee_order', 'tiktok', $sellerSku ?: $orderSn, null, null, 'skipped', $message);
                $results[] = ['status' => 'skipped', 'message' => $message];
                continue;
            }

            $result = $this->syncService->mirrorShopeeStockToTiktok($mapping, sprintf('Shopee order %s %s/%s', $orderSn, $eventType, $orderStatus), true, true);
            $results[] = $result;
            if (($result['status'] ?? '') === 'success') {
                $success++;
                $productRefreshRefs['shopee_item_ids'][] = $itemId;
                $productRefreshRefs['tiktok_product_ids'][] = (string) ($mapping->tiktok_product_id ?? $mapping->mapped_tiktok_product_id ?? '');
            } elseif (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
        }

        if ($items === []) {
            $this->syncService->logSync('shopee_order', 'tiktok', $orderSn, null, null, 'skipped', 'Detail order Shopee tidak memiliki item_list.');
            $skipped++;
        }

        $productRefresh = $this->refreshProductCachesAfterOrder($orderSn, $productRefreshRefs);

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Order Shopee %s diproses. Success=%s skipped=%s failed=%s.', $orderSn, $success, $skipped, $failed),
            'order_sn' => $orderSn,
            'order_status' => $orderStatus,
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
            'items' => $results,
            'product_refresh' => $productRefresh,
        ];
    }

    public function pollShopeeReadyOrders(int $hours = 24): array
    {
        $timeTo = time();
        $timeFrom = $timeTo - (max(1, $hours) * 3600);
        $statuses = ['PROCESSED', 'READY_TO_SHIP', 'CANCELLED'];
        $seen = [];
        $processed = 0;
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $alreadyProcessed = 0;
        $messages = [];

        foreach ($statuses as $status) {
            $list = $this->apiService->fetchShopeeOrderSnList($timeFrom, $timeTo, $status);
            if (($list['status'] ?? '') !== 'success') {
                $failed++;
                $messages[] = $status.': '.($list['message'] ?? 'Order list Shopee gagal diambil.');
                continue;
            }

            foreach ($list['orders'] ?? [] as $order) {
                $orderSn = trim((string) ($order['order_sn'] ?? ''));
                if ($orderSn === '' || isset($seen[$orderSn])) {
                    continue;
                }
                $seen[$orderSn] = true;
                $stockEvent = $status === 'CANCELLED' ? 'POLL_CANCEL_ORDER' : 'POLL_READY_ORDER';
                if ($this->alreadyProcessed('shopee_order', $orderSn, $stockEvent, $orderSn)) {
                    $alreadyProcessed++;
                    continue;
                }

                $result = $this->processShopeeOrder($orderSn, $stockEvent, ['order_status' => $status]);
                $processed++;
                if (($result['status'] ?? '') === 'success') {
                    $success++;
                    $this->syncService->logSync('shopee_order', 'tiktok', $orderSn, null, null, 'success', sprintf('Shopee order %s %s selesai.', $orderSn, $stockEvent));
                } else {
                    $failed++;
                    $messages[] = $orderSn.': '.($result['message'] ?? 'gagal');
                }
            }
        }

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Polling order Shopee selesai. Baru diproses=%s, berhasil=%s, sudah pernah diproses=%s, dilewati=%s, gagal=%s.', $processed, $success, $alreadyProcessed, $skipped, $failed),
            'processed' => $processed,
            'success' => $success,
            'already_processed' => $alreadyProcessed,
            'skipped' => $skipped,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    public function pollTiktokUpdatedOrders(int $hours = 24): array
    {
        $timeTo = time();
        $timeFrom = $timeTo - (max(1, $hours) * 3600);
        $list = $this->apiService->fetchTiktokOrderList($timeFrom, $timeTo);
        if (($list['status'] ?? '') !== 'success') {
            return [
                'status' => 'warning',
                'message' => 'Polling order TikTok gagal: '.($list['message'] ?? 'Order list TikTok gagal diambil.'),
                'processed' => 0,
                'success' => 0,
                'already_processed' => 0,
                'skipped' => 0,
                'failed' => 1,
                'messages' => [$list['message'] ?? 'Order list TikTok gagal diambil.'],
            ];
        }

        $seen = [];
        $processed = 0;
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $alreadyProcessed = 0;
        $messages = [];

        foreach ($list['orders'] ?? [] as $order) {
            $orderId = trim((string) ($order['id'] ?? $order['order_id'] ?? ''));
            if ($orderId === '' || isset($seen[$orderId])) {
                continue;
            }
            $seen[$orderId] = true;

            $stockEvent = $this->tiktokStockEventType('POLL_UPDATED_ORDER', is_array($order) ? $order : []);
            if ($stockEvent === null) {
                $skipped++;
                continue;
            }

            if ($this->alreadyProcessed('tiktok_order', $orderId, $stockEvent, $orderId)) {
                $alreadyProcessed++;
                continue;
            }

            $result = $this->processTiktokOrder($orderId, $stockEvent, [
                'poll_order' => $order,
                'poll_time_from' => $timeFrom,
                'poll_time_to' => $timeTo,
            ]);
            if (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } elseif (($result['status'] ?? '') === 'success') {
                $processed++;
                $success++;
                $this->syncService->logSync('tiktok_order', 'shopee', $orderId, null, null, 'success', sprintf('TikTok order %s %s selesai.', $orderId, $stockEvent));
            } elseif (($result['status'] ?? '') === 'warning') {
                $processed++;
                $failed++;
                $messages[] = $orderId.': '.($result['message'] ?? 'warning');
            } else {
                $processed++;
                $failed++;
                $messages[] = $orderId.': '.($result['message'] ?? 'gagal');
            }
        }

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Polling order TikTok selesai. Baru diproses=%s, berhasil=%s, sudah pernah diproses=%s, dilewati=%s, gagal=%s.', $processed, $success, $alreadyProcessed, $skipped, $failed),
            'processed' => $processed,
            'success' => $success,
            'already_processed' => $alreadyProcessed,
            'skipped' => $skipped,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    public function retryOrderSyncLog(int $logId): array
    {
        $log = DB::table('marketplace_sync_logs')->where('id', $logId)->first();
        if (! $log) {
            return ['status' => 'error', 'message' => 'Log order sync tidak ditemukan.'];
        }

        $orderRef = $this->syncService->orderReferenceFromLog($log);
        if ($orderRef !== '' && in_array($log->source_marketplace, ['shopee_order', 'shopee_stock_refresh'], true)) {
            return $this->processShopeeOrder($orderRef, 'MANUAL_RETRY', ['retry_log_id' => $logId]);
        }

        if ($orderRef !== '' && $log->source_marketplace === 'tiktok_order') {
            return $this->processTiktokOrder($orderRef, 'MANUAL_RETRY', ['retry_log_id' => $logId]);
        }

        $mapping = trim((string) $log->sku) !== '' ? $this->syncService->findSkuMapping((string) $log->sku) : null;
        if (! $mapping) {
            return ['status' => 'error', 'message' => 'Retry gagal: order reference/SKU mapping tidak ditemukan.'];
        }

        return $this->syncService->mirrorShopeeStockToTiktok($mapping, 'Manual retry order sync log '.$logId, true, true);
    }

    public function processTiktokOrder(string $orderId, string $eventType, array $payload = []): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return ['status' => 'error', 'message' => 'order_id TikTok kosong.'];
        }

        $detail = $this->apiService->fetchTiktokOrderDetail($orderId);
        if (($detail['status'] ?? '') !== 'success') {
            $this->syncService->logSync('tiktok_order', 'shopee', $orderId, null, null, 'error', $detail['message'] ?? 'Detail order TikTok gagal diambil.');
            return $detail;
        }

        $order = $detail['order'];
        $stockEvent = $this->tiktokStockEventType($eventType, $order);
        if ($stockEvent === null) {
            $status = strtoupper((string) ($order['status'] ?? $order['order_status'] ?? data_get($payload, 'data.order_status', 'UNKNOWN')));
            $this->syncService->logSync('tiktok_order', 'shopee', $orderId, null, null, 'skipped', sprintf('TikTok order %s status %s belum mengubah stok, dilewati.', $orderId, $status));
            return [
                'status' => 'success',
                'message' => sprintf('Order TikTok %s dilewati karena status %s belum mengubah stok.', $orderId, $status),
                'order_id' => $orderId,
                'success' => 0,
                'skipped' => 1,
                'failed' => 0,
                'items' => [],
            ];
        }

        $staleSale = $this->staleTiktokSaleFromPolling($stockEvent, $order, $payload);
        if ($staleSale !== null) {
            $message = $staleSale['sale_time'] instanceof Carbon
                ? sprintf(
                    'TikTok order %s TIKTOK_STALE_SALE dilewati: waktu sale %s lebih lama dari window polling mulai %s.',
                    $orderId,
                    $staleSale['sale_time']->toDateTimeString(),
                    $staleSale['poll_time_from']->toDateTimeString()
                )
                : sprintf(
                    'TikTok order %s TIKTOK_STALE_SALE dilewati: waktu sale tidak tersedia pada detail/list order, window polling mulai %s. Demi keamanan stok, sale dari polling tanpa waktu order tidak dipush.',
                    $orderId,
                    $staleSale['poll_time_from']->toDateTimeString()
                );
            if (! $this->alreadyLogged('tiktok_order', $orderId, 'TIKTOK_STALE_SALE', $orderId, ['skipped'])) {
                $this->syncService->logSync('tiktok_order', 'shopee', $orderId, null, null, 'skipped', $message);
            }

            return [
                'status' => 'skipped',
                'message' => $message,
                'order_id' => $orderId,
                'success' => 0,
                'skipped' => 1,
                'failed' => 0,
                'items' => [],
            ];
        }

        $lineItems = data_get($order, 'line_items', data_get($order, 'items', []));
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];
        $productRefreshRefs = [
            'shopee_item_ids' => [],
            'tiktok_product_ids' => [],
        ];

        foreach (is_array($lineItems) ? $lineItems : [] as $item) {
            $sellerSku = $this->normalizeOrderSku($item['seller_sku'] ?? data_get($item, 'sku.seller_sku', ''));
            $skuId = trim((string) ($item['sku_id'] ?? data_get($item, 'sku.id', '')));
            $productId = trim((string) ($item['product_id'] ?? data_get($item, 'product.id', '')));
            $mapping = $this->syncService->findSkuMappingByTiktokOrderItem($productId, $skuId, $sellerSku);

            if (! $mapping) {
                $skipped++;
                $message = sprintf('Order TikTok %s item dilewati: SKU mapping tidak ditemukan untuk seller_sku=%s sku_id=%s.', $orderId, $sellerSku ?: '-', $skuId ?: '-');
                $this->syncService->logSync('tiktok_order', 'shopee', $sellerSku ?: $orderId, null, null, 'skipped', $message);
                $results[] = ['status' => 'skipped', 'message' => $message];
                continue;
            }

            $canonicalSku = $this->syncService->canonicalSku($mapping, $sellerSku);
            if ($this->alreadyProcessed('tiktok_order', $orderId, $stockEvent, $canonicalSku)) {
                $skipped++;
                $message = sprintf('TikTok order %s %s SKU %s sudah pernah diproses, dilewati agar stok tidak berubah dua kali.', $orderId, $stockEvent, $canonicalSku);
                $this->syncService->logSync('tiktok_order', 'shopee', $canonicalSku, null, null, 'skipped', $message);
                $results[] = ['status' => 'skipped', 'message' => $message];
                continue;
            }

            $oldStock = $this->syncService->currentStockForMarketplace('tiktok', $mapping) ?? (int) ($mapping->stock_qty ?? 0);
            $qty = max(1, (int) ($item['quantity'] ?? data_get($item, 'sku.quantity', 1)));
            $newStock = $this->stockAfterOrderEvent($stockEvent, $oldStock, $qty);
            $this->syncService->updateLocalStock($mapping, 'tiktok', $newStock);
            $pushResult = $this->syncService->pushTargetStock($mapping, 'shopee', $newStock, true);
            $status = ($pushResult['status'] ?? '') === 'error' ? 'error' : 'success';
            if ($status === 'success') {
                $this->syncService->updateLocalStock($mapping, 'shopee', $newStock);
            }
            $this->syncService->logSync('tiktok_order', 'shopee', $canonicalSku, $oldStock, $newStock, $status, sprintf('TikTok order %s %s: stok %s -> %s. %s', $orderId, $stockEvent, $oldStock, $newStock, $pushResult['message'] ?? '-'));
            $results[] = ['status' => $status, 'sku' => $canonicalSku];
            if ($status === 'success') {
                $success++;
                $productRefreshRefs['tiktok_product_ids'][] = $productId ?: (string) ($mapping->tiktok_product_id ?? $mapping->mapped_tiktok_product_id ?? '');
                $productRefreshRefs['shopee_item_ids'][] = (string) ($mapping->shopee_product_id ?? $mapping->mapped_shopee_item_id ?? '');
            } else {
                $failed++;
            }
        }

        $productRefresh = $this->refreshProductCachesAfterOrder($orderId, $productRefreshRefs);

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Order TikTok %s diproses. Success=%s skipped=%s failed=%s.', $orderId, $success, $skipped, $failed),
            'order_id' => $orderId,
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
            'items' => $results,
            'product_refresh' => $productRefresh,
        ];
    }

    private function refreshProductCachesAfterOrder(string $orderRef, array $refs): array
    {
        $hasShopeeRefs = collect($refs['shopee_item_ids'] ?? [])->contains(fn ($value): bool => (int) $value > 0);
        $hasTiktokRefs = collect($refs['tiktok_product_ids'] ?? [])->contains(fn ($value): bool => trim((string) $value) !== '');
        if (! $hasShopeeRefs && ! $hasTiktokRefs) {
            return ['status' => 'skipped', 'message' => 'Tidak ada product id marketplace yang perlu di-refresh.'];
        }

        $tiktokDelaySeconds = (int) env('AUTO_SYNC_TIKTOK_PRODUCT_REFRESH_DELAY_SECONDS', 60);
        $result = [
            'status' => 'success',
            'message' => 'Refresh cache produk order dijadwalkan.',
            'shopee' => null,
            'tiktok' => null,
        ];

        if ($hasShopeeRefs) {
            try {
                $shopeeResult = app(OmnichannelController::class)->syncMarketplaceProductCachesForOrder([
                    'shopee_item_ids' => $refs['shopee_item_ids'] ?? [],
                    'tiktok_product_ids' => [],
                ]);
                $status = ($shopeeResult['status'] ?? '') === 'success' ? 'success' : 'error';
                $this->syncService->logSync(
                    'order_product_refresh',
                    'shopee_products',
                    $orderRef,
                    null,
                    null,
                    $status,
                    $shopeeResult['message'] ?? 'Refresh cache produk Shopee selesai.'
                );
                $result['shopee'] = $shopeeResult;
                if ($status === 'error') {
                    $result['status'] = 'warning';
                }
            } catch (\Throwable $exception) {
                report($exception);
                $this->syncService->logSync('order_product_refresh', 'shopee_products', $orderRef, null, null, 'error', 'Refresh cache produk Shopee gagal: '.$exception->getMessage());
                $result['status'] = 'warning';
                $result['shopee'] = ['status' => 'error', 'message' => $exception->getMessage()];
            }
        }

        if ($hasTiktokRefs) {
            $dueAt = now()->addSeconds(max(0, min(300, $tiktokDelaySeconds)));
            $payload = [
                'order_ref' => $orderRef,
                'due_at' => $dueAt->toDateTimeString(),
                'refs' => [
                    'shopee_item_ids' => [],
                    'tiktok_product_ids' => array_values(array_unique(array_filter(array_map(
                        fn ($value): string => trim((string) $value),
                        $refs['tiktok_product_ids'] ?? []
                    )))),
                ],
            ];
            $this->syncService->logSync(
                'order_product_refresh',
                'tiktok_products',
                $orderRef,
                null,
                null,
                'pending',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $result['tiktok'] = [
                'status' => 'pending',
                'message' => 'Refresh cache produk TikTok dijadwalkan pada '.$dueAt->toDateTimeString().'.',
                'delay_seconds' => $tiktokDelaySeconds,
            ];
        }

        return $result;
    }

    public function processPendingProductCacheRefreshes(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $delaySeconds = max(0, min(300, (int) env('AUTO_SYNC_TIKTOK_PRODUCT_REFRESH_DELAY_SECONDS', 60)));
        $rows = DB::table('marketplace_sync_logs')
            ->where('source_marketplace', 'order_product_refresh')
            ->where('target_marketplace', 'tiktok_products')
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subSeconds($delaySeconds))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $processed = 0;
        $success = 0;
        $failed = 0;
        $items = [];

        foreach ($rows as $row) {
            $payload = $this->pendingProductRefreshPayload((string) ($row->message ?? ''));
            $refs = $payload['refs'] ?? ['tiktok_product_ids' => []];

            try {
                $refresh = app(OmnichannelController::class)->syncMarketplaceProductCachesForOrder([
                    'shopee_item_ids' => [],
                    'tiktok_product_ids' => $refs['tiktok_product_ids'] ?? [],
                ]);
                $status = ($refresh['status'] ?? '') === 'success' ? 'success' : 'error';
                $message = ($refresh['message'] ?? 'Refresh cache produk TikTok selesai.').' Order='.($payload['order_ref'] ?? $row->sku ?? '-');
            } catch (\Throwable $exception) {
                report($exception);
                $status = 'error';
                $message = 'Refresh cache produk TikTok gagal: '.$exception->getMessage();
                $refresh = ['status' => 'error', 'message' => $exception->getMessage()];
            }

            DB::table('marketplace_sync_logs')->where('id', (int) $row->id)->update([
                'status' => $status,
                'message' => $message,
                'updated_at' => now(),
            ]);

            $processed++;
            $status === 'success' ? $success++ : $failed++;
            $items[] = [
                'id' => (int) $row->id,
                'order_ref' => $payload['order_ref'] ?? $row->sku,
                'status' => $status,
                'message' => $message,
                'refresh' => $refresh,
            ];
        }

        return [
            'status' => $failed > 0 ? 'warning' : 'success',
            'message' => sprintf('Pending refresh produk order selesai. Diproses=%s berhasil=%s gagal=%s.', $processed, $success, $failed),
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'items' => $items,
        ];
    }

    private function pendingProductRefreshPayload(string $message): array
    {
        $payload = json_decode($message, true);
        if (! is_array($payload)) {
            return ['refs' => ['tiktok_product_ids' => []]];
        }

        return $payload;
    }

    private function stockAfterOrderEvent(string $eventType, int $oldStock, int $qty): int
    {
        $event = strtoupper($eventType);
        if (str_contains($event, 'CANCEL') || str_contains($event, 'RETURN') || str_contains($event, 'REFUND')) {
            return $oldStock + max(0, $qty);
        }

        return max(0, $oldStock - max(0, $qty));
    }

    private function normalizeOrderSku(mixed $value): string
    {
        $sku = trim((string) $value);

        return $sku === '-' ? '' : $sku;
    }

    private function tiktokStockEventType(string $eventType, array $order): ?string
    {
        $event = strtoupper($eventType);
        $orderStatus = strtoupper((string) ($order['status'] ?? $order['order_status'] ?? data_get($order, 'line_items.0.display_status', '')));

        if (
            str_contains($event, 'CANCEL')
            || str_contains($event, 'RETURN')
            || str_contains($event, 'REFUND')
            || in_array($orderStatus, ['CANCELLED', 'CANCELED', 'RETURNED', 'REFUNDED', 'RETURN_COMPLETED'], true)
        ) {
            return 'TIKTOK_RESTORE';
        }

        if (
            str_contains($event, 'PAID')
            || str_contains($event, 'ORDER_CREATED')
            || str_contains($event, 'READY')
            || in_array($orderStatus, ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'PARTIALLY_SHIPPING', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'], true)
        ) {
            return 'TIKTOK_SALE';
        }

        return null;
    }

    private function staleTiktokSaleFromPolling(string $stockEvent, array $order, array $payload): ?array
    {
        if ($stockEvent !== 'TIKTOK_SALE') {
            return null;
        }

        $pollTimeFrom = $this->tiktokTimestampToCarbon($payload['poll_time_from'] ?? null);
        if ($pollTimeFrom === null) {
            return null;
        }

        $saleTime = $this->tiktokSaleTime($order, $payload);
        if ($saleTime === null) {
            return [
                'sale_time' => null,
                'poll_time_from' => $pollTimeFrom,
            ];
        }

        if ($saleTime->greaterThanOrEqualTo($pollTimeFrom)) {
            return null;
        }

        return [
            'sale_time' => $saleTime,
            'poll_time_from' => $pollTimeFrom,
        ];
    }

    private function tiktokSaleTime(array $order, array $payload): ?Carbon
    {
        $sources = [
            $order,
            is_array($payload['poll_order'] ?? null) ? $payload['poll_order'] : [],
        ];
        $keys = ['paid_time', 'payment_time', 'paid_at', 'payment_at', 'create_time', 'create_at', 'created_time', 'created_at'];

        foreach ($sources as $source) {
            foreach ($keys as $key) {
                $time = $this->tiktokTimestampToCarbon(data_get($source, $key));
                if ($time !== null) {
                    return $time;
                }
            }
        }

        return null;
    }

    private function tiktokTimestampToCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp, config('app.timezone')) : null;
        }

        try {
            return Carbon::parse((string) $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function alreadyProcessed(string $source, string $orderId, string $eventType, string $sku): bool
    {
        return $this->alreadyLogged($source, $orderId, $eventType, $sku, ['success']);
    }

    private function alreadyLogged(string $source, string $orderId, string $eventType, string $sku, array $statuses): bool
    {
        return DB::table('marketplace_sync_logs')
            ->where('source_marketplace', $source)
            ->where('sku', $sku)
            ->whereIn('status', $statuses)
            ->where('message', 'like', '%'.$orderId.' '.$eventType.'%')
            ->exists();
    }
}
