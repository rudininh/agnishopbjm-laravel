<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class MarketplaceApiService
{
    private array $shopeeModelStockCache = [];

    public function fetchTiktokProduct(string $productId): array
    {
        $productId = trim($productId);
        $path = '/product/202309/products/'.$productId;
        $request = ['method' => 'GET', 'path' => $path, 'body' => null];
        $context = $this->activeTiktokContext();

        if (($context['status'] ?? '') !== 'success') {
            return $this->catalogResult(false, (string) ($context['message'] ?? 'Konteks TikTok belum tersedia.'), [
                'product_id' => $productId,
                'product' => null,
            ], $request, []);
        }

        $shopId = trim((string) ($context['shop_id'] ?? ''));
        if ($shopId === '') {
            return $this->catalogResult(false, 'Shop ID TikTok belum tersedia.', [
                'product_id' => $productId,
                'product' => null,
            ], $request, []);
        }

        $query = [
            'app_key' => $context['config']['app_key'],
            'shop_cipher' => $context['shop_cipher'],
            'shop_id' => $shopId,
            'timestamp' => time(),
            'version' => '202309',
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['config']['app_secret']);

        try {
            $httpResponse = Http::timeout(45)
                ->withHeaders([
                    'x-tts-access-token' => $context['access_token'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get($context['config']['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        } catch (ConnectionException) {
            return $this->catalogResult(false, 'Koneksi ke TikTok gagal.', [
                'product_id' => $productId,
                'product' => null,
            ], $request, []);
        }
        $response = $httpResponse->json();

        if (! is_array($response)) {
            return $this->catalogResult(false, 'TikTok tidak mengembalikan JSON valid.', [
                'product_id' => $productId,
                'product' => null,
            ], $request, [
                'code' => $httpResponse->status(),
                'message' => 'TikTok tidak mengembalikan JSON valid.',
            ]);
        }

        $marketplaceOk = $httpResponse->successful() && (int) ($response['code'] ?? -1) === 0;
        $product = data_get($response, 'data');
        $productIsValid = is_array($product) && $product !== [] && ! array_is_list($product);
        $ok = $marketplaceOk && $productIsValid;

        return $this->catalogResult(
            $ok,
            $ok
                ? 'Detail produk TikTok berhasil diambil.'
                : $this->marketplaceFailureMessage(
                    $response,
                    $marketplaceOk
                        ? 'Detail produk TikTok tidak valid.'
                        : ($httpResponse->successful() ? 'Detail produk TikTok gagal diambil.' : 'TikTok merespons HTTP '.$httpResponse->status().'.'),
                ),
            ['product_id' => $productId, 'product' => $productIsValid ? $product : null],
            $request,
            $response,
        );
    }

    public function partialEditTiktokProduct(string $productId, array $payload): array
    {
        $productId = trim($productId);
        $path = '/product/202509/products/'.$productId.'/partial_edit';
        $request = ['method' => 'POST', 'path' => $path, 'body' => $payload];
        $context = $this->activeTiktokContext();

        if (($context['status'] ?? '') !== 'success') {
            return $this->catalogResult(false, (string) ($context['message'] ?? 'Konteks TikTok belum tersedia.'), [
                'product_id' => $productId,
            ], $request, []);
        }

        $shopId = trim((string) ($context['shop_id'] ?? ''));
        if ($shopId === '') {
            return $this->catalogResult(false, 'Shop ID TikTok belum tersedia.', [
                'product_id' => $productId,
            ], $request, []);
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return $this->catalogResult(false, 'Payload TikTok tidak bisa diencode.', [
                'product_id' => $productId,
            ], $request, []);
        }

        $query = [
            'access_token' => $context['access_token'],
            'app_key' => $context['config']['app_key'],
            'shop_cipher' => $context['shop_cipher'],
            'shop_id' => $shopId,
            'timestamp' => time(),
            'version' => '202509',
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['config']['app_secret'], $body);

        try {
            $httpResponse = Http::timeout(45)
                ->withHeaders([
                    'x-tts-access-token' => $context['access_token'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post($context['config']['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        } catch (ConnectionException) {
            return $this->catalogResult(false, 'Koneksi ke TikTok gagal.', [
                'product_id' => $productId,
            ], $request, []);
        }
        $response = $httpResponse->json();

        if (! is_array($response)) {
            return $this->catalogResult(false, 'TikTok tidak mengembalikan JSON valid.', [
                'product_id' => $productId,
            ], $request, [
                'code' => $httpResponse->status(),
                'message' => 'TikTok tidak mengembalikan JSON valid.',
            ]);
        }

        $ok = $httpResponse->successful() && (int) ($response['code'] ?? -1) === 0;

        return $this->catalogResult(
            $ok,
            $ok
                ? 'Produk TikTok berhasil diperbarui.'
                : $this->marketplaceFailureMessage(
                    $response,
                    $httpResponse->successful() ? 'Produk TikTok gagal diperbarui.' : 'TikTok merespons HTTP '.$httpResponse->status().'.',
                ),
            ['product_id' => $productId],
            $request,
            $response,
        );
    }

    public function fetchShopeeModels(string $itemId): array
    {
        $itemId = trim($itemId);
        $body = ['item_id' => is_numeric($itemId) ? (int) $itemId : $itemId];
        $request = ['method' => 'GET', 'path' => '/api/v2/product/get_model_list', 'body' => $body];
        $token = $this->activeShopeeToken();

        if (! $token) {
            return $this->catalogResult(false, 'Token Shopee aktif belum tersedia.', [
                'item_id' => $itemId,
                'models' => [],
            ], $request, []);
        }

        try {
            $response = $this->shopeeSignedGet(
                '/api/v2/product/get_model_list',
                (int) $token->shop_id,
                (string) $token->access_token,
                $body,
            );
        } catch (ConnectionException) {
            return $this->catalogResult(false, 'Koneksi ke Shopee gagal.', [
                'item_id' => $itemId,
                'models' => [],
            ], $request, []);
        }
        $ok = $this->successfulShopeeResponse($response)
            && trim((string) ($response['error'] ?? '')) === '';
        $models = data_get($response, 'response.model', data_get($response, 'response.model_list', []));

        return $this->catalogResult(
            $ok,
            $ok
                ? 'Model Shopee berhasil diambil.'
                : $this->marketplaceFailureMessage(
                    $response,
                    $this->successfulShopeeResponse($response) ? 'Model Shopee gagal diambil.' : 'Shopee merespons HTTP '.(int) ($response['_http_status'] ?? 0).'.',
                ),
            ['item_id' => $itemId, 'models' => is_array($models) ? $models : []],
            $request,
            $this->publicMarketplaceResponse($response),
        );
    }

    public function updateShopeeModelSku(string $itemId, string $modelId, string $targetSku): array
    {
        $itemId = trim($itemId);
        $modelId = trim($modelId);
        $body = [
            'item_id' => is_numeric($itemId) ? (int) $itemId : $itemId,
            'model' => [[
                'model_id' => is_numeric($modelId) ? (int) $modelId : $modelId,
                'model_sku' => trim($targetSku),
            ]],
        ];
        $request = ['method' => 'POST', 'path' => '/api/v2/product/update_model', 'body' => $body];
        $token = $this->activeShopeeToken();

        if (! $token) {
            return $this->catalogResult(false, 'Token Shopee aktif belum tersedia.', [
                'item_id' => $itemId,
                'model_id' => $modelId,
            ], $request, []);
        }

        try {
            $response = $this->shopeeSignedPost(
                '/api/v2/product/update_model',
                (int) $token->shop_id,
                (string) $token->access_token,
                $body,
            );
        } catch (ConnectionException) {
            return $this->catalogResult(false, 'Koneksi ke Shopee gagal.', [
                'item_id' => $itemId,
                'model_id' => $modelId,
            ], $request, []);
        }
        $ok = $this->successfulShopeeResponse($response)
            && trim((string) ($response['error'] ?? '')) === '';

        return $this->catalogResult(
            $ok,
            $ok
                ? 'Model SKU Shopee berhasil diperbarui.'
                : $this->marketplaceFailureMessage(
                    $response,
                    $this->successfulShopeeResponse($response) ? 'Model SKU Shopee gagal diperbarui.' : 'Shopee merespons HTTP '.(int) ($response['_http_status'] ?? 0).'.',
                ),
            ['item_id' => $itemId, 'model_id' => $modelId],
            $request,
            $this->publicMarketplaceResponse($response),
        );
    }

    public function fetchShopeeOfficialShippingDocument(string $orderSn, string $documentType = '', string $documentSize = 'A6'): array
    {
        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $detail = $this->fetchShopeeOrderDetail($orderSn);
        if (($detail['status'] ?? '') !== 'success') {
            return $detail;
        }

        $order = is_array($detail['order'] ?? null) ? $detail['order'] : [];
        $package = data_get($order, 'package_list.0', []);
        $packageNumber = (string) (data_get($package, 'package_number') ?: '');
        $trackingNumber = $this->normalizeShopeeTrackingNumber(data_get($package, 'tracking_number'));
        $trackingNumberSource = $trackingNumber !== '' ? 'order_detail' : '';

        $parameter = $this->shopeeSignedPost('/api/v2/logistics/get_shipping_document_parameter', (int) $token->shop_id, (string) $token->access_token, [
            'order_list' => [[
                'order_sn' => $orderSn,
                ...array_filter(['package_number' => $packageNumber], fn ($value) => $value !== ''),
            ]],
        ]);

        $parameterRow = $this->firstShopeeShippingDocumentParameter($parameter);
        $resolvedDocumentType = $documentType !== ''
            ? $documentType
            : (string) ($parameterRow['suggest_shipping_document_type'] ?? data_get($parameterRow, 'selectable_shipping_document_type.0', 'THERMAL_AIR_WAYBILL'));
        $packageNumber = $packageNumber ?: (string) ($parameterRow['package_number'] ?? '');
        $parameterTrackingNumber = $this->normalizeShopeeTrackingNumber($parameterRow['tracking_number'] ?? '');
        if ($trackingNumber === '' && $parameterTrackingNumber !== '') {
            $trackingNumber = $parameterTrackingNumber;
            $trackingNumberSource = 'shipping_document_parameter';
        }

        $trackingNumberResponse = $this->fetchShopeeTrackingNumberForDocument($token, $orderSn, $packageNumber);
        $resolvedTrackingNumber = $this->firstShopeeTrackingNumber($trackingNumberResponse);
        if ($resolvedTrackingNumber !== '') {
            $trackingNumber = $resolvedTrackingNumber;
            $trackingNumberSource = 'get_tracking_number';
        }

        $orderPayload = array_filter([
            'order_sn' => $orderSn,
            'package_number' => $packageNumber,
            'tracking_number' => $trackingNumber,
            'shipping_document_type' => $resolvedDocumentType,
        ], fn ($value) => $value !== null && $value !== '');

        $documentResult = $this->fetchShopeeOfficialShippingDocumentWithPayload(
            $token,
            $orderSn,
            $orderPayload,
            $parameter,
            [
                'tracking_number_response' => $trackingNumberResponse,
                'tracking_number_source' => $trackingNumberSource,
            ]
        );

        if (($documentResult['status'] ?? '') === 'error' && $this->isShopeeTrackingNumberInvalidError($documentResult)) {
            if (($orderPayload['tracking_number'] ?? '') !== '') {
                $withoutTrackingPayload = $orderPayload;
                unset($withoutTrackingPayload['tracking_number']);

                $retryWithoutTrackingResult = $this->fetchShopeeOfficialShippingDocumentWithPayload(
                    $token,
                    $orderSn,
                    $withoutTrackingPayload,
                    $parameter,
                    [
                        'tracking_number_response' => $trackingNumberResponse,
                        'tracking_number_source' => $trackingNumberSource,
                        'retried_without_tracking_number' => true,
                        'first_attempt' => $documentResult,
                    ]
                );

                if (($retryWithoutTrackingResult['status'] ?? '') !== 'error' || ! $this->isShopeeTrackingNumberInvalidError($retryWithoutTrackingResult)) {
                    return $retryWithoutTrackingResult;
                }
            }

            $minimalPayload = array_filter([
                'order_sn' => $orderSn,
                'shipping_document_type' => $resolvedDocumentType,
            ], fn ($value) => $value !== null && $value !== '');

            $retryResult = $this->fetchShopeeOfficialShippingDocumentWithPayload(
                $token,
                $orderSn,
                $minimalPayload,
                $parameter,
                [
                    'tracking_number_response' => $trackingNumberResponse,
                    'tracking_number_source' => $trackingNumberSource,
                    'retried_without_package_number' => true,
                    'first_attempt' => $documentResult,
                ]
            );

            if (($retryResult['status'] ?? '') !== 'error' || ! $this->isShopeeTrackingNumberInvalidError($retryResult)) {
                return $retryResult;
            }

            $downloadResult = $this->downloadExistingShopeeOfficialShippingDocument(
                $token,
                $orderSn,
                $minimalPayload,
                [
                    'parameter' => $parameter,
                    'retried_without_create' => true,
                    'retried_without_package_number' => true,
                    'first_attempt' => $documentResult,
                    'second_attempt' => $retryResult,
                ]
            );

            return ($downloadResult['status'] ?? '') === 'success' ? $downloadResult : $retryResult;
        }

        return $documentResult;
    }

    private function fetchShopeeOfficialShippingDocumentWithPayload(object $token, string $orderSn, array $orderPayload, array $parameter, array $meta = []): array
    {
        $documentPayload = $this->shopeeShippingDocumentLookupPayload($orderPayload);
        $create = $this->shopeeSignedPost('/api/v2/logistics/create_shipping_document', (int) $token->shop_id, (string) $token->access_token, [
            'order_list' => [$orderPayload],
        ]);

        $createError = ($create['error'] ?? '') !== '';
        $canTryExistingDocument = $createError && $this->isShopeeAlreadyShippedPrintError($create);
        if ($createError && ! $canTryExistingDocument) {
            return ['status' => 'error', 'message' => $this->shopeeBatchFailMessage($create) ?: ($create['message'] ?? $create['error']), 'response' => $create, 'parameter' => $parameter, ...$meta];
        }

        $result = [];
        $ready = $canTryExistingDocument;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if ($attempt > 0) {
                usleep(800000);
            }

            $result = $this->shopeeSignedPost('/api/v2/logistics/get_shipping_document_result', (int) $token->shop_id, (string) $token->access_token, [
                'order_list' => [$documentPayload],
            ]);

            if (($result['error'] ?? '') !== '') {
                if ($canTryExistingDocument) {
                    break;
                }

                return ['status' => 'error', 'message' => $this->shopeeBatchFailMessage($result) ?: ($result['message'] ?? $result['error']), 'response' => $result, 'create_response' => $create, ...$meta];
            }

            $ready = $this->shopeeShippingDocumentReady($result);
            if ($ready) {
                break;
            }
        }

        if (! $ready && ! $canTryExistingDocument) {
            return [
                'status' => 'pending',
                'message' => 'Dokumen resmi Shopee masih dibuat oleh marketplace. Coba klik lagi beberapa detik lagi.',
                'create_response' => $create,
                'result_response' => $result,
                'parameter' => $parameter,
                ...$meta,
            ];
        }

        $download = $this->shopeeSignedPostRaw('/api/v2/logistics/download_shipping_document', (int) $token->shop_id, (string) $token->access_token, [
            'order_list' => [$documentPayload],
        ]);
        $downloadResult = $this->normalizeOfficialDocumentResponse($download, 'shopee-'.$orderSn.'.pdf', [
            'create_response' => $create,
            'result_response' => $result,
            'parameter' => $parameter,
            'used_existing_document_fallback' => $canTryExistingDocument,
            ...$meta,
        ]);

        if (($downloadResult['status'] ?? '') === 'error' && $canTryExistingDocument) {
            $downloadResult['message'] = 'Paket Shopee sudah berstatus dikirim, jadi Shopee menolak membuat dokumen baru. Dokumen lama juga belum bisa diunduh dari API. Coba cetak dari Seller Centre Shopee untuk order ini.';
        }

        return $downloadResult;
    }

    private function downloadExistingShopeeOfficialShippingDocument(object $token, string $orderSn, array $orderPayload, array $meta = []): array
    {
        $download = $this->shopeeSignedPostRaw('/api/v2/logistics/download_shipping_document', (int) $token->shop_id, (string) $token->access_token, [
            'order_list' => [$orderPayload],
        ]);

        return $this->normalizeOfficialDocumentResponse($download, 'shopee-'.$orderSn.'.pdf', $meta);
    }

    private function fetchShopeeTrackingNumberForDocument(object $token, string $orderSn, string $packageNumber = ''): array
    {
        $params = array_filter([
            'order_sn' => $orderSn,
            'package_number' => $packageNumber,
            'response_optional_fields' => 'first_mile_tracking_number,last_mile_tracking_number,plp_number',
        ], fn ($value) => $value !== null && $value !== '');

        return $this->shopeeSignedGet('/api/v2/logistics/get_tracking_number', (int) $token->shop_id, (string) $token->access_token, $params);
    }

    private function firstShopeeTrackingNumber(array $response): string
    {
        foreach ([
            'response.tracking_number',
            'response.first_mile_tracking_number',
            'response.last_mile_tracking_number',
            'response.shopee_tracking_number',
            'response.plp_number',
            'tracking_number',
            'first_mile_tracking_number',
            'last_mile_tracking_number',
            'shopee_tracking_number',
            'plp_number',
        ] as $path) {
            $trackingNumber = $this->normalizeShopeeTrackingNumber(data_get($response, $path));
            if ($trackingNumber !== '') {
                return $trackingNumber;
            }
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($response));
        foreach ($iterator as $key => $value) {
            $key = strtolower((string) $key);
            if (! str_contains($key, 'tracking') && $key !== 'plp_number') {
                continue;
            }

            $trackingNumber = $this->normalizeShopeeTrackingNumber($value);
            if ($trackingNumber !== '') {
                return $trackingNumber;
            }
        }

        return '';
    }

    private function normalizeShopeeTrackingNumber(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $trackingNumber = preg_replace('/\s+/', '', trim((string) $value)) ?: '';
        if (in_array(strtolower($trackingNumber), ['-', 'null', 'n/a', 'na'], true)) {
            return '';
        }

        return $trackingNumber;
    }

    private function shopeeShippingDocumentLookupPayload(array $orderPayload): array
    {
        unset($orderPayload['tracking_number']);

        return $orderPayload;
    }

    public function updateShopeeModelStockForAccount(string $accountKey, string $itemId, string $modelId, int $stock, ?string $idempotencyKey = null): array
    {
        $token = $this->activeShopeeTokenForAccount($accountKey);
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif untuk akun ini belum tersedia.'];
        }

        $body = [
            'item_id' => is_numeric($itemId) ? (int) $itemId : $itemId,
            'model' => [[
                'model_id' => is_numeric($modelId) ? (int) $modelId : $modelId,
                'normal_stock' => max(0, $stock),
            ]],
        ];
        $response = $this->shopeeSignedPostForAccount($accountKey, (int) $token->shop_id, (string) $token->access_token, '/api/v2/product/update_model', $body, $idempotencyKey);
        $ok = ($response['error'] ?? '') === '' && trim((string) ($response['error'] ?? '')) === '';

        return [
            'status' => $ok ? 'success' : 'error',
            'message' => $ok ? 'Live push stok Shopee berhasil dikirim.' : ($response['message'] ?? $response['error'] ?? 'Push stok Shopee gagal.'),
            'response' => $this->publicMarketplaceResponse($response),
        ];
    }

    public function updateTiktokStockForAccount(string $accountKey, string $productId, string $skuId, int $stock, ?string $warehouseId = null, ?string $idempotencyKey = null): array
    {
        try {
            $context = app(\App\Services\MarketplaceAccountRegistry::class)->tiktokContext($accountKey);
        } catch (\Throwable $exception) {
            return ['status' => 'error', 'message' => $exception->getMessage()];
        }

        $token = $this->activeTiktokTokenForAccount($accountKey);
        $shop = $this->tiktokShopForAccount($accountKey);
        $warehouseId = trim((string) ($warehouseId ?: $context['warehouse_id'] ?? ''));
        $shopCipher = trim((string) ($shop->cipher ?? $shop->shop_cipher ?? ''));
        if (! $token || trim((string) ($token->access_token ?? '')) === '' || ! $shop || trim((string) ($shop->shop_id ?? $shop->id ?? '')) === '' || $shopCipher === '' || $warehouseId === '') {
            return ['status' => 'error', 'message' => 'Token, identitas toko, cipher, atau warehouse TikTok belum tersedia.'];
        }

        $productId = trim($productId);
        $skuId = trim($skuId);
        $path = '/product/202309/products/'.$productId.'/inventory/update';
        $body = ['skus' => [['id' => $skuId, 'inventory' => [['warehouse_id' => $warehouseId, 'quantity' => max(0, $stock)]]]]];
        $bodyString = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query = [
            'app_key' => $context['app_key'],
            'access_token' => (string) $token->access_token,
            'shop_cipher' => $shopCipher,
            'timestamp' => time(),
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['app_secret'], $bodyString);

        try {
            $request = Http::timeout(45)->withHeaders([
                'x-tts-access-token' => (string) $token->access_token,
                ...($idempotencyKey ? ['X-Idempotency-Key' => $idempotencyKey] : []),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->withBody($bodyString, 'application/json')->post(rtrim((string) $context['api_host'], '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        } catch (ConnectionException) {
            return ['status' => 'error', 'message' => 'Koneksi ke TikTok gagal.'];
        }
        $response = $request->json();
        $ok = $request->successful() && is_array($response) && (int) ($response['code'] ?? -1) === 0;

        return [
            'status' => $ok ? 'success' : 'error',
            'message' => $ok ? 'Live push stok TikTok berhasil dikirim.' : (is_array($response) ? ($response['message'] ?? 'Push stok TikTok gagal.') : 'TikTok tidak mengembalikan JSON valid.'),
            'response' => is_array($response) ? $this->publicMarketplaceResponse($response) : [],
        ];
    }

    public function fetchShopeeOrderDetailForAccount(string $accountKey, string $orderSn): array
    {
        $token = $this->activeShopeeTokenForAccount($accountKey);
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif untuk akun ini belum tersedia.'];
        }

        $response = $this->shopeeSignedGetForAccount($accountKey, (int) $token->shop_id, (string) $token->access_token, '/api/v2/order/get_order_detail', [
            'order_sn_list' => trim($orderSn),
            'response_optional_fields' => 'order_status,item_list,recipient_address,package_list,shipping_carrier,update_time',
        ]);

        if (($response['error'] ?? '') !== '') {
            return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response];
        }

        $orders = data_get($response, 'response.order_list', []);
        $order = is_array($orders) ? ($orders[0] ?? null) : null;
        if (! is_array($order)) {
            return ['status' => 'error', 'message' => 'Detail order Shopee tidak ditemukan.', 'response' => $response];
        }

        return ['status' => 'success', 'order' => $order, 'response' => $response, 'account_key' => $accountKey];
    }

    public function fetchShopeeOrderSnListForAccount(string $accountKey, int $timeFrom, int $timeTo, ?string $orderStatus = null): array
    {
        $token = $this->activeShopeeTokenForAccount($accountKey);
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif untuk akun ini belum tersedia.', 'account_key' => $accountKey];
        }

        $cursor = '';
        $orders = [];
        do {
            $params = [
                'time_range_field' => 'update_time',
                'time_from' => $timeFrom,
                'time_to' => $timeTo,
                'page_size' => 50,
            ];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }
            if ($orderStatus) {
                $params['order_status'] = $orderStatus;
            }

            $response = $this->shopeeSignedGetForAccount($accountKey, (int) $token->shop_id, (string) $token->access_token, '/api/v2/order/get_order_list', $params);
            if (($response['error'] ?? '') !== '') {
                return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response, 'account_key' => $accountKey];
            }

            $orders = array_merge($orders, data_get($response, 'response.order_list', []));
            $more = (bool) data_get($response, 'response.more', false);
            $cursor = (string) data_get($response, 'response.next_cursor', '');
        } while ($more && $cursor !== '');

        return ['status' => 'success', 'orders' => $orders, 'account_key' => $accountKey];
    }

    public function fetchShopeeModelStockForAccount(string $accountKey, string $itemId, string $modelId): array
    {
        $cacheKey = $accountKey.'\0'.$itemId;
        if (isset($this->shopeeModelStockCache[$cacheKey])) {
            return $this->stockFromCachedShopeeModels($cacheKey, $modelId);
        }

        $token = $this->activeShopeeTokenForAccount($accountKey);
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif untuk akun ini belum tersedia.', 'account_key' => $accountKey];
        }

        $response = $this->shopeeSignedGetForAccount($accountKey, (int) $token->shop_id, (string) $token->access_token, '/api/v2/product/get_model_list', [
            'item_id' => is_numeric($itemId) ? (int) $itemId : $itemId,
        ]);
        if (($response['error'] ?? '') !== '') {
            return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response, 'account_key' => $accountKey];
        }

        $models = data_get($response, 'response.model', data_get($response, 'response.model_list', []));
        $this->shopeeModelStockCache[$cacheKey] = is_array($models) ? $models : [];

        return $this->stockFromCachedShopeeModels($cacheKey, $modelId, $response);
    }

    public function fetchShopeeOrderDetail(string $orderSn): array
    {
        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $response = $this->shopeeSignedGet('/api/v2/order/get_order_detail', (int) $token->shop_id, (string) $token->access_token, [
            'order_sn_list' => $orderSn,
            'response_optional_fields' => 'order_status,item_list,recipient_address,package_list,shipping_carrier,update_time',
        ]);

        if (($response['error'] ?? '') !== '') {
            return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response];
        }

        $orders = data_get($response, 'response.order_list', []);
        $order = is_array($orders) ? ($orders[0] ?? null) : null;
        if (! is_array($order)) {
            return ['status' => 'error', 'message' => 'Detail order Shopee tidak ditemukan.', 'response' => $response];
        }

        return ['status' => 'success', 'order' => $order, 'response' => $response];
    }

    public function fetchShopeeOrderDetails(array $orderSns): array
    {
        $orderSns = collect($orderSns)
            ->map(fn ($orderSn): string => trim((string) $orderSn))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($orderSns === []) {
            return ['status' => 'success', 'orders' => []];
        }

        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $orders = [];
        foreach (array_chunk($orderSns, 50) as $chunk) {
            $response = $this->shopeeSignedGet('/api/v2/order/get_order_detail', (int) $token->shop_id, (string) $token->access_token, [
                'order_sn_list' => implode(',', $chunk),
                'response_optional_fields' => 'order_status,item_list,recipient_address,package_list,shipping_carrier,update_time',
            ]);

            if (($response['error'] ?? '') !== '') {
                return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response];
            }

            foreach ((array) data_get($response, 'response.order_list', []) as $order) {
                if (! is_array($order)) {
                    continue;
                }

                $orderSn = trim((string) ($order['order_sn'] ?? ''));
                if ($orderSn !== '') {
                    $orders[$orderSn] = $order;
                }
            }
        }

        return ['status' => 'success', 'orders' => $orders];
    }

    public function fetchShopeeOrderSnList(int $timeFrom, int $timeTo, ?string $orderStatus = null): array
    {
        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $cursor = '';
        $orders = [];
        do {
            $params = [
                'time_range_field' => 'update_time',
                'time_from' => $timeFrom,
                'time_to' => $timeTo,
                'page_size' => 50,
            ];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }
            if ($orderStatus) {
                $params['order_status'] = $orderStatus;
            }

            $response = $this->shopeeSignedGet('/api/v2/order/get_order_list', (int) $token->shop_id, (string) $token->access_token, $params);
            if (($response['error'] ?? '') !== '') {
                return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response];
            }

            $orders = array_merge($orders, data_get($response, 'response.order_list', []));
            $more = (bool) data_get($response, 'response.more', false);
            $cursor = (string) data_get($response, 'response.next_cursor', '');
        } while ($more && $cursor !== '');

        return ['status' => 'success', 'orders' => $orders];
    }

    public function fetchShopeeModelStock(string $itemId, string $modelId): array
    {
        $cacheKey = (string) $itemId;
        if (isset($this->shopeeModelStockCache[$cacheKey])) {
            return $this->stockFromCachedShopeeModels($cacheKey, $modelId);
        }

        $token = $this->activeShopeeToken();
        if (! $token) {
            return ['status' => 'error', 'message' => 'Token Shopee aktif belum tersedia.'];
        }

        $response = $this->shopeeSignedGet('/api/v2/product/get_model_list', (int) $token->shop_id, (string) $token->access_token, [
            'item_id' => (int) $itemId,
        ]);

        if (($response['error'] ?? '') !== '') {
            return ['status' => 'error', 'message' => $response['message'] ?? $response['error'], 'response' => $response];
        }

        $models = data_get($response, 'response.model', data_get($response, 'response.model_list', []));
        $this->shopeeModelStockCache[$cacheKey] = is_array($models) ? $models : [];

        return $this->stockFromCachedShopeeModels($cacheKey, $modelId, $response);
    }

    private function stockFromCachedShopeeModels(string $cacheKey, string $modelId, array $response = []): array
    {
        foreach ($this->shopeeModelStockCache[$cacheKey] ?? [] as $model) {
            if ((string) ($model['model_id'] ?? '') !== (string) $modelId) {
                continue;
            }

            return [
                'status' => 'success',
                'stock' => $this->shopeeModelStock($model),
                'model' => $model,
                'response' => $response,
            ];
        }

        return ['status' => 'error', 'message' => 'Model Shopee tidak ditemukan pada response get_model_list.', 'response' => $response];
    }

    public function fetchTiktokOrderDetail(string $orderId): array
    {
        $context = $this->activeTiktokContext();
        if (($context['status'] ?? '') !== 'success') {
            return $context;
        }

        $path = '/order/202309/orders';
        $query = [
            'app_key' => $context['config']['app_key'],
            'access_token' => $context['access_token'],
            'shop_cipher' => $context['shop_cipher'],
            'timestamp' => time(),
            'ids' => $orderId,
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['config']['app_secret']);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => $context['access_token'],
                'Accept' => 'application/json',
            ])
            ->get($context['config']['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            return [
                'status' => 'error',
                'message' => is_array($payload) ? ($payload['message'] ?? 'Detail order TikTok gagal diambil.') : 'TikTok tidak mengembalikan JSON valid.',
                'http_status' => $response->status(),
                'response' => $payload,
            ];
        }

        $orders = data_get($payload, 'data.orders', []);
        $order = is_array($orders) ? ($orders[0] ?? null) : null;
        if (! is_array($order)) {
            $order = data_get($payload, 'data.order', data_get($payload, 'data'));
        }
        if (! is_array($order)) {
            return ['status' => 'error', 'message' => 'Detail order TikTok tidak ditemukan.', 'response' => $payload];
        }

        return ['status' => 'success', 'order' => $order, 'response' => $payload];
    }

    public function fetchTiktokOfficialShippingDocument(string $orderId, string $documentType = 'SHIPPING_LABEL', string $documentSize = 'A6', string $documentFormat = 'PDF'): array
    {
        $detail = $this->fetchTiktokOrderDetail($orderId);
        if (($detail['status'] ?? '') !== 'success') {
            return $detail;
        }

        $order = is_array($detail['order'] ?? null) ? $detail['order'] : [];
        $packageId = $this->tiktokPackageId($order);
        if ($packageId === '') {
            return [
                'status' => 'error',
                'message' => 'Package ID TikTok belum tersedia pada detail order. Dokumen resmi TikTok baru bisa diambil setelah paket dibuat/dikirim via TikTok Shipping.',
                'order' => $order,
            ];
        }

        $context = $this->activeTiktokContext();
        if (($context['status'] ?? '') !== 'success') {
            return $context;
        }

        $path = '/fulfillment/202309/packages/'.$packageId.'/shipping_documents';
        $query = [
            'app_key' => $context['config']['app_key'],
            'shop_cipher' => $context['shop_cipher'],
            'timestamp' => time(),
            'document_type' => $documentType,
            'document_size' => $documentSize,
            'document_format' => $documentFormat,
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['config']['app_secret']);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => $context['access_token'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->get($context['config']['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            return [
                'status' => 'error',
                'message' => is_array($payload) ? $this->humanizeTiktokShippingDocumentError($payload) : 'TikTok tidak mengembalikan JSON valid.',
                'http_status' => $response->status(),
                'response' => $payload,
            ];
        }

        $url = $this->firstDocumentUrl($payload);
        if ($url !== '') {
            return [
                'status' => 'success',
                'document' => [
                    'source' => 'url',
                    'url' => $url,
                    'mime_type' => $documentFormat === 'PDF' ? 'application/pdf' : 'application/octet-stream',
                    'filename' => 'tiktok-'.$orderId.'.'.strtolower($documentFormat),
                ],
                'response' => $payload,
            ];
        }

        $base64 = $this->firstBase64Document($payload);
        if ($base64 !== '') {
            return [
                'status' => 'success',
                'document' => [
                    'source' => 'base64',
                    'content_base64' => $base64,
                    'mime_type' => $documentFormat === 'PDF' ? 'application/pdf' : 'application/octet-stream',
                    'filename' => 'tiktok-'.$orderId.'.'.strtolower($documentFormat),
                ],
                'response' => $payload,
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Response TikTok berhasil, tetapi URL/base64 dokumen tidak ditemukan.',
            'response' => $payload,
        ];
    }

    public function fetchTiktokOrderList(int $timeFrom, int $timeTo, ?string $orderStatus = null): array
    {
        $context = $this->activeTiktokContext();
        if (($context['status'] ?? '') !== 'success') {
            return $context;
        }

        $path = '/order/202309/orders/search';
        $pageToken = '';
        $orders = [];

        do {
            $query = [
                'app_key' => $context['config']['app_key'],
                'access_token' => $context['access_token'],
                'shop_cipher' => $context['shop_cipher'],
                'timestamp' => time(),
                'page_size' => 50,
                'sort_field' => 'update_time',
                'sort_order' => 'ASC',
            ];
            if ($pageToken !== '') {
                $query['page_token'] = $pageToken;
            }

            $body = [
                'update_time_ge' => $timeFrom,
                'update_time_lt' => $timeTo,
            ];
            if ($orderStatus) {
                $body['order_status'] = $orderStatus;
            }

            $bodyString = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $query['sign'] = $this->generateTiktokSign($path, $query, (string) $context['config']['app_secret'], $bodyString);

            $response = Http::timeout(45)
                ->withHeaders([
                    'x-tts-access-token' => $context['access_token'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->withBody($bodyString, 'application/json')
                ->post($context['config']['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            $payload = $response->json();

            if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
                return [
                    'status' => 'error',
                    'message' => is_array($payload) ? ($payload['message'] ?? 'Order list TikTok gagal diambil.') : 'TikTok tidak mengembalikan JSON valid.',
                    'http_status' => $response->status(),
                    'response' => $payload,
                ];
            }

            $orders = array_merge($orders, data_get($payload, 'data.orders', []));
            $pageToken = (string) data_get($payload, 'data.next_page_token', '');
        } while ($pageToken !== '');

        return ['status' => 'success', 'orders' => $orders];
    }

    private function activeTiktokTokenForAccount(string $accountKey): ?object
    {
        $query = DB::table('tiktok_tokens')->whereRaw('COALESCE(is_active, true) = true');
        if (Schema::hasColumn('tiktok_tokens', 'account_key')) {
            $query->where('account_key', trim($accountKey));
        }

        return $query->orderByDesc('created_at')->first();
    }

    private function tiktokShopForAccount(string $accountKey): ?object
    {
        $query = DB::table('tiktok_shops')->orderByDesc('updated_at');
        if (Schema::hasColumn('tiktok_shops', 'account_key')) {
            $query->where('account_key', trim($accountKey));
        }

        return $query->first();
    }

    private function activeShopeeTokenForAccount(string $accountKey): ?object
    {
        $query = DB::table('shopee_tokens')->whereRaw('COALESCE(is_active, true) = true');
        if (Schema::hasColumn('shopee_tokens', 'account_key')) {
            $query->where('account_key', trim($accountKey));
        }

        return $query->orderByDesc('created_at')->first();
    }

    private function shopeeSignedPostForAccount(string $accountKey, int $shopId, string $accessToken, string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        try {
            $context = app(\App\Services\MarketplaceAccountRegistry::class)->shopeeContext($accountKey);
        } catch (\Throwable $exception) {
            return ['error' => 'invalid_context', 'message' => $exception->getMessage()];
        }

        $timestamp = time();
        $query = [
            'partner_id' => (int) $context['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
        ];
        $query['sign'] = $this->generateShopeeApiSign((int) $context['partner_id'], (string) $context['partner_key'], $path, $timestamp, $accessToken, $shopId);
        try {
            $request = Http::timeout(45)->acceptJson()->withHeaders($idempotencyKey ? ['X-Idempotency-Key' => $idempotencyKey] : [])->post(rtrim((string) $context['host'], '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $body);
        } catch (ConnectionException) {
            return ['error' => 'connection_failed', 'message' => 'Koneksi ke Shopee gagal.'];
        }
        $data = $request->json();
        if (! is_array($data)) {
            return ['error' => 'invalid_json', 'message' => 'Shopee tidak mengembalikan JSON valid.', '_http_status' => $request->status()];
        }

        return [...$data, '_http_status' => $request->status()];
    }

    private function shopeeSignedGetForAccount(string $accountKey, int $shopId, string $accessToken, string $path, array $params = []): array
    {
        try {
            $context = app(\App\Services\MarketplaceAccountRegistry::class)->shopeeContext($accountKey);
        } catch (\Throwable $exception) {
            return ['error' => 'invalid_context', 'message' => $exception->getMessage()];
        }

        $timestamp = time();
        $query = [
            'partner_id' => (int) $context['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            ...$params,
        ];
        $query['sign'] = $this->generateShopeeApiSign((int) $context['partner_id'], (string) $context['partner_key'], $path, $timestamp, $accessToken, $shopId);

        try {
            $response = Http::timeout(45)->acceptJson()->get(rtrim((string) $context['host'], '/').$path, $query);
        } catch (ConnectionException) {
            return ['error' => 'connection_failed', 'message' => 'Koneksi ke Shopee gagal.'];
        }
        $data = $response->json();
        if (! is_array($data)) {
            return ['error' => 'invalid_json', 'message' => 'Shopee tidak mengembalikan JSON valid.', '_http_status' => $response->status()];
        }

        return [...$data, '_http_status' => $response->status()];
    }

    private function activeShopeeToken(): ?object
    {
        $query = DB::table('shopee_tokens')
            ->whereRaw('COALESCE(is_active, true) = true');
        $accountKey = trim((string) config('shopee.account_key', ''));
        if ($accountKey !== '' && Schema::hasColumn('shopee_tokens', 'account_key')) {
            $query->where('account_key', $accountKey);
        }

        return $query->orderByDesc('created_at')->first();
    }

    private function shopeeSignedGet(string $path, int $shopId, string $accessToken, array $params = []): array
    {
        $config = config('shopee');
        $timestamp = time();
        $query = [
            'partner_id' => (int) $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            ...$params,
        ];
        $query['sign'] = $this->generateShopeeApiSign((int) $config['partner_id'], (string) $config['partner_key'], $path, $timestamp, $accessToken, $shopId);

        $response = Http::timeout(45)
            ->acceptJson()
            ->get($config['host'].$path, $query);
        $data = $response->json();

        if (! is_array($data)) {
            return [
                'error' => 'invalid_json',
                'message' => 'Shopee tidak mengembalikan JSON valid.',
                '_http_status' => $response->status(),
                '_body' => $response->body(),
            ];
        }

        return [...$data, '_http_status' => $response->status()];
    }

    private function shopeeSignedPost(string $path, int $shopId, string $accessToken, array $body = []): array
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

        $response = Http::timeout(60)
            ->acceptJson()
            ->post($config['host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $body);
        $data = $response->json();

        if (is_array($data)) {
            return [...$data, '_http_status' => $response->status()];
        }

        return [
            'error' => 'invalid_json',
            'message' => 'Shopee tidak mengembalikan JSON valid.',
            '_http_status' => $response->status(),
            '_path' => $path,
            '_body' => $response->body(),
        ];
    }

    private function shopeeSignedPostRaw(string $path, int $shopId, string $accessToken, array $body = []): array
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

        $response = Http::timeout(60)
            ->accept('*/*')
            ->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json')
            ->post($config['host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return [
            'http_status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'json' => $response->json(),
        ];
    }

    private function activeTiktokContext(): array
    {
        $token = DB::table('tiktok_tokens')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->orderByDesc('created_at')
            ->first();
        $shop = DB::table('tiktok_shops')->orderByDesc('updated_at')->first();
        if (! $token || trim((string) $token->access_token) === '') {
            return ['status' => 'error', 'message' => 'Token TikTok aktif belum tersedia.'];
        }

        $shopCipher = trim((string) ($shop->cipher ?? $shop->shop_cipher ?? ''));
        if ($shopCipher === '') {
            return ['status' => 'error', 'message' => 'shop_cipher TikTok belum tersedia.'];
        }

        return [
            'status' => 'success',
            'access_token' => (string) $token->access_token,
            'shop_id' => trim((string) ($shop->shop_id ?? $shop->id ?? '')),
            'shop_cipher' => $shopCipher,
            'config' => config('tiktok'),
        ];
    }

    private function catalogResult(bool $ok, string $message, array $data, array $request, array $response): array
    {
        return compact('ok', 'message', 'data', 'request', 'response');
    }

    private function publicMarketplaceResponse(array $response): array
    {
        return array_filter(
            $response,
            fn (string|int $key): bool => ! is_string($key) || ! str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function successfulShopeeResponse(array $response): bool
    {
        $status = (int) ($response['_http_status'] ?? 0);

        return $status >= 200 && $status < 300;
    }

    private function marketplaceFailureMessage(array $response, string $fallback): string
    {
        foreach ([$response['message'] ?? null, $response['error'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '' && strtolower(trim($candidate)) !== 'success') {
                return trim($candidate);
            }
        }

        return $fallback;
    }

    private function generateShopeeApiSign(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp.$accessToken.$shopId, $partnerKey);
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

    private function firstShopeeShippingDocumentParameter(array $response): ?array
    {
        $candidates = [
            data_get($response, 'response.result_list.0'),
            data_get($response, 'response.result.0'),
            data_get($response, 'response.0'),
            data_get($response, 'result_list.0'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function shopeeShippingDocumentReady(array $response): bool
    {
        $rows = data_get($response, 'response.result_list', data_get($response, 'response.result', []));
        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            $status = strtoupper((string) ($row['status'] ?? $row['document_status'] ?? $row['shipping_document_status'] ?? ''));
            if (in_array($status, ['READY', 'SUCCESS', 'DONE', 'COMPLETED'], true)) {
                return true;
            }
        }

        return false;
    }

    private function shopeeBatchFailMessage(array $response): string
    {
        $rows = data_get($response, 'response.result_list', []);
        if (! is_array($rows)) {
            return '';
        }

        foreach ($rows as $row) {
            $message = trim((string) ($row['fail_message'] ?? ''));
            $error = trim((string) ($row['fail_error'] ?? ''));
            if ($message !== '') {
                return $error !== '' ? $error.': '.$message : $message;
            }
        }

        return '';
    }

    private function isShopeeAlreadyShippedPrintError(array $response): bool
    {
        $message = strtolower(implode(' ', array_filter([
            (string) ($response['error'] ?? ''),
            (string) ($response['message'] ?? ''),
            $this->shopeeBatchFailMessage($response),
        ])));

        return str_contains($message, 'package_can_not_print')
            || str_contains($message, 'parcel has been shipped')
            || str_contains($message, 'package can not print');
    }

    private function isShopeeTrackingNumberInvalidError(array $response): bool
    {
        $message = strtolower(implode(' ', array_filter([
            (string) ($response['error'] ?? ''),
            (string) ($response['message'] ?? ''),
            $this->shopeeBatchFailMessage($response),
            is_array($response['response'] ?? null) ? $this->shopeeBatchFailMessage($response['response']) : '',
            (string) data_get($response, 'response.error', ''),
            (string) data_get($response, 'response.message', ''),
        ])));

        return str_contains($message, 'tracking_number_invalid')
            || str_contains($message, 'tracking number is invalid');
    }

    private function normalizeOfficialDocumentResponse(array $download, string $filename, array $meta = []): array
    {
        $json = $download['json'] ?? null;
        if (is_array($json) && (($json['error'] ?? '') !== '')) {
            return ['status' => 'error', 'message' => $json['message'] ?? $json['error'], 'response' => $json, ...$meta];
        }

        if (is_array($json)) {
            $url = $this->firstDocumentUrl($json);
            if ($url !== '') {
                return ['status' => 'success', 'document' => ['source' => 'url', 'url' => $url, 'mime_type' => 'application/pdf', 'filename' => $filename], 'response' => $json, ...$meta];
            }

            $base64 = $this->firstBase64Document($json);
            if ($base64 !== '') {
                return ['status' => 'success', 'document' => ['source' => 'base64', 'content_base64' => $base64, 'mime_type' => 'application/pdf', 'filename' => $filename], 'response' => $json, ...$meta];
            }
        }

        $body = (string) ($download['body'] ?? '');
        if ($body !== '') {
            return [
                'status' => 'success',
                'document' => [
                    'source' => 'base64',
                    'content_base64' => base64_encode($body),
                    'mime_type' => $this->responseMimeType($download['headers'] ?? []),
                    'filename' => $filename,
                ],
                ...$meta,
            ];
        }

        return ['status' => 'error', 'message' => 'Dokumen resmi berhasil diproses, tetapi file dokumen kosong.', ...$meta];
    }

    private function responseMimeType(array $headers): string
    {
        $contentType = $headers['Content-Type'][0] ?? $headers['content-type'][0] ?? '';
        return trim(explode(';', (string) $contentType)[0]) ?: 'application/pdf';
    }

    private function firstDocumentUrl(array $payload): string
    {
        foreach (['doc_url', 'document_url', 'shipping_document_url', 'url', 'file_url', 'download_url'] as $key) {
            $value = data_get($payload, 'data.'.$key, data_get($payload, 'response.'.$key, data_get($payload, $key)));
            if (is_string($value) && str_starts_with($value, 'http')) {
                return $value;
            }
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($payload));
        foreach ($iterator as $value) {
            if (is_string($value) && str_starts_with($value, 'http') && preg_match('/(pdf|document|label|shipping)/i', $value)) {
                return $value;
            }
        }

        return '';
    }

    private function firstBase64Document(array $payload): string
    {
        foreach (['document', 'file', 'content', 'pdf', 'shipping_document'] as $key) {
            $value = data_get($payload, 'data.'.$key, data_get($payload, 'response.'.$key, data_get($payload, $key)));
            if (is_string($value) && strlen($value) > 100) {
                return preg_replace('/^data:[^;]+;base64,/', '', $value);
            }
        }

        return '';
    }

    private function tiktokPackageId(array $order): string
    {
        foreach ([
            'packages.0.id',
            'packages.0.package_id',
            'package_list.0.id',
            'package_list.0.package_id',
            'package_id',
            'fulfillment_packages.0.id',
            'fulfillment_packages.0.package_id',
            'delivery_packages.0.id',
            'delivery_packages.0.package_id',
            'logistics_packages.0.id',
            'logistics_packages.0.package_id',
        ] as $path) {
            $value = trim((string) data_get($order, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function humanizeTiktokShippingDocumentError(array $payload): string
    {
        $message = (string) ($payload['message'] ?? 'Dokumen resmi TikTok gagal diambil.');
        $code = (int) ($payload['code'] ?? 0);
        $lower = mb_strtolower($message);

        if ($code === 21042102 || str_contains($lower, 'pickup') || str_contains($lower, 'picked')) {
            return 'Dokumen TikTok tidak bisa dicetak dari API karena paket sudah di-pickup. TikTok hanya mengizinkan dokumen diambil sebelum pickup; untuk cetak ulang gunakan Seller Center TikTok.';
        }

        if ($code === 21042101 || str_contains($lower, 'cancellation') || str_contains($lower, 'refund')) {
            return 'Dokumen TikTok tidak bisa dicetak karena order sedang/ sudah masuk proses cancel atau refund.';
        }

        return $message;
    }

    private function shopeeModelStock(array $model): int
    {
        $stockInfo = $model['stock_info_v2'] ?? $model['stock_info'] ?? null;
        if (is_array($stockInfo)) {
            foreach (['seller_stock', 'summary_info'] as $key) {
                $value = $stockInfo[$key] ?? null;
                if (is_array($value)) {
                    if (isset($value['total_available_stock'])) {
                        return (int) $value['total_available_stock'];
                    }
                    if (isset($value[0]['stock'])) {
                        return (int) $value[0]['stock'];
                    }
                }
            }
        }

        return (int) ($model['stock'] ?? $model['normal_stock'] ?? 0);
    }
}
