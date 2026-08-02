<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MarketplaceAutoSyncController;
use App\Http\Controllers\MarketplaceImportController;
use App\Http\Controllers\MarketplaceWebhookController;
use App\Http\Controllers\MobileProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OmnichannelController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SyncRuntimeController;
use App\Http\Controllers\StbMappingSyncController;
use Illuminate\Support\Facades\Route;

Route::get('health', fn () => response()->json(['status' => 'ok', 'service' => 'agnishop-api']));
Route::get('shopee/callback', [OmnichannelController::class, 'shopeeCallback']);
Route::get('tiktok/callback', [OmnichannelController::class, 'tiktokCallback']);
Route::get('tiktok-callback', [OmnichannelController::class, 'tiktokCallback']);
Route::post('omnichannel/{action}', [OmnichannelController::class, 'tokenAction'])
    ->where('action', 'connect-shopee(-agnishopbjm|-gitacollectionbjm)?|connect-tiktok(-agnishopbjm)?|auth-shopee(-agnishopbjm|-gitacollectionbjm)?|auth-tiktok(-agnishopbjm)?|get-token-shopee(-agnishopbjm|-gitacollectionbjm)?|get-token-tiktok(-agnishopbjm)?|refresh-token-shopee(-agnishopbjm|-gitacollectionbjm)?|refresh-token-tiktok(-agnishopbjm)?|get-auth-shop-tiktok(-agnishopbjm)?');

Route::get('omnichannel/dashboard', [OmnichannelController::class, 'dashboard']);
Route::get('get-shopee-items', [OmnichannelController::class, 'shopeeItems']);
Route::get('get-tiktok-items', [OmnichannelController::class, 'tiktokItems']);
Route::get('get-stock-master', [OmnichannelController::class, 'stockMaster']);
Route::get('product-variant-analysis', [OmnichannelController::class, 'productVariantAnalysis']);
Route::post('product-variant-analysis/confirm', [OmnichannelController::class, 'confirmProductVariantAnalysisIssue']);
Route::get('product-variant-image-anomalies', [OmnichannelController::class, 'imageVariantAnomalies']);
Route::post('product-variant-image-anomalies/sync-tiktok-from-shopee', [OmnichannelController::class, 'syncTiktokImagesFromShopee']);
Route::get('sku-mapping', [OmnichannelController::class, 'skuMapping']);
Route::post('sku-mapping', [OmnichannelController::class, 'saveSkuMapping']);
Route::post('sku-mapping/sync-marketplaces', [OmnichannelController::class, 'syncMarketplaceCaches']);
Route::post('sku-mapping/update-marketplace-sku', [OmnichannelController::class, 'updateSkuMappingMarketplaceSku']);
Route::post('sku-mapping/update-marketplace-variant-sku', [OmnichannelController::class, 'updateMarketplaceVariantSku']);
Route::post('sku-mapping/bulk-update-empty-shopee-variant-skus', [OmnichannelController::class, 'bulkUpdateShopeeEmptyVariantSkus']);
Route::post('sku-mapping/prepare-missing-variant', [OmnichannelController::class, 'prepareMissingVariant']);
Route::get('tiktok/bulk-missing-variants', [OmnichannelController::class, 'bulkTiktokMissingVariantsPreview']);
Route::post('tiktok/bulk-missing-variants/submit', [OmnichannelController::class, 'bulkSubmitTiktokMissingVariants']);
Route::get('tiktok/variant-reconciliation/products', [OmnichannelController::class, 'tiktokVariantReconciliationProducts']);
Route::get('tiktok/variant-reconciliation/preview', [OmnichannelController::class, 'tiktokVariantReconciliationPreview']);
Route::post('tiktok/variant-reconciliation/submit', [OmnichannelController::class, 'submitTiktokVariantReconciliation']);
Route::post('tiktok-variant/action', [OmnichannelController::class, 'tiktokVariantAction']);
Route::post('tiktok/delete-variant', [OmnichannelController::class, 'tiktokDeleteVariant']);
Route::post('tiktok/submit-generated-payload', [OmnichannelController::class, 'tiktokSubmitGeneratedPayload']);
Route::post('tiktok/get-product', [OmnichannelController::class, 'tiktokGetProduct']);
Route::get('tiktok/get-product-context', [OmnichannelController::class, 'tiktokGetProductContext']);
Route::post('shopee/api-test', [OmnichannelController::class, 'shopeeApiTest']);
Route::get('shopee/api-test-context', [OmnichannelController::class, 'shopeeApiTestContext']);
Route::post('shopee/add-variant', [OmnichannelController::class, 'shopeeAddVariant']);
Route::post('shopee/delete-variant', [OmnichannelController::class, 'shopeeDeleteVariant']);
Route::get('runtime/stb-status', [SyncRuntimeController::class, 'stbStatus']);
Route::post('runtime/stb-mapping-sync', [StbMappingSyncController::class, 'import']);
Route::get('marketplace/auto-sync', [MarketplaceAutoSyncController::class, 'dashboard']);
Route::get('marketplace/auto-sync/runtime-status', [SyncRuntimeController::class, 'status']);
Route::get('marketplace/auto-sync/bridge-status', [SyncRuntimeController::class, 'bridgeStatus']);
Route::get('marketplace/auto-sync/runtime-readiness', [SyncRuntimeController::class, 'readiness']);
Route::get('marketplace/auto-sync/runtime-events', [SyncRuntimeController::class, 'events']);
Route::post('marketplace/auto-sync/runtime-heartbeat', [SyncRuntimeController::class, 'heartbeat']);
Route::post('marketplace/auto-sync/runtime-settings', [SyncRuntimeController::class, 'updateSettings']);
Route::post('marketplace/auto-sync/runtime-online-backup-tick', [SyncRuntimeController::class, 'onlineBackupTick']);
Route::post('marketplace/auto-sync/backup-runner/dry-run', [SyncRuntimeController::class, 'backupRunnerDryRun']);
Route::post('marketplace/auto-sync/backup-runner/run', [SyncRuntimeController::class, 'backupRunnerRun']);
Route::post('marketplace/auto-sync/backup-runner/scheduler-tick', [SyncRuntimeController::class, 'schedulerTick']);
Route::get('marketplace/auto-sync/webhook-logs', [MarketplaceAutoSyncController::class, 'webhookLogs']);
Route::get('marketplace/auto-sync/sync-logs', [MarketplaceAutoSyncController::class, 'syncLogs']);
Route::get('marketplace/auto-sync/safety-check', [MarketplaceAutoSyncController::class, 'safety']);
Route::get('marketplace/auto-sync/order-sync', [MarketplaceAutoSyncController::class, 'orderSync']);
Route::get('marketplace/shipping-labels/orders', [MarketplaceAutoSyncController::class, 'shippingLabelOrders']);
Route::get('marketplace/shipping-labels/order-detail', [MarketplaceAutoSyncController::class, 'shippingLabelOrderDetail']);
Route::post('marketplace/shipping-labels/official-document', [MarketplaceAutoSyncController::class, 'shippingLabelOfficialDocument']);
Route::post('marketplace/shipping-labels/mark-printed', [MarketplaceAutoSyncController::class, 'markShippingLabelsPrinted']);
Route::get('marketplace/auto-sync/stock-anomalies', [MarketplaceAutoSyncController::class, 'stockAnomalies']);
Route::get('marketplace/auto-sync/sku-change-history', [MarketplaceAutoSyncController::class, 'skuChangeHistory']);
Route::get('marketplace/auto-sync/order-watchdog', [MarketplaceAutoSyncController::class, 'orderWatchdog']);
Route::get('marketplace/auto-sync/reconciliation-report', [MarketplaceAutoSyncController::class, 'reconciliationReport']);
Route::get('marketplace/auto-sync/queue-dashboard', [MarketplaceAutoSyncController::class, 'queueDashboard']);
Route::post('marketplace/auto-sync/stock-anomalies/sync', [MarketplaceAutoSyncController::class, 'syncStockAnomaly']);
Route::post('marketplace/auto-sync/stock-anomalies/refresh-products', [MarketplaceAutoSyncController::class, 'refreshStockAnomalyProducts']);
Route::get('marketplace/auto-sync/order-sync/export', [MarketplaceAutoSyncController::class, 'exportOrderSync']);
Route::get('marketplace/auto-sync/order-sync/{id}', [MarketplaceAutoSyncController::class, 'orderSyncDetail'])->whereNumber('id');
Route::post('marketplace/auto-sync/order-sync/{id}/retry', [MarketplaceAutoSyncController::class, 'retryOrderSync'])->whereNumber('id');
Route::post('marketplace/auto-sync/run-safety-check', [MarketplaceAutoSyncController::class, 'runSafetyCheck']);
Route::post('marketplace/auto-sync/sync-shopee-to-tiktok', [MarketplaceAutoSyncController::class, 'syncShopeeToTiktok']);
Route::post('marketplace/auto-sync/instant-check', [MarketplaceAutoSyncController::class, 'instantCheck']);
Route::post('marketplace/auto-sync/retry-open-issues', [MarketplaceAutoSyncController::class, 'retryOpenIssues']);
Route::post('marketplace/auto-sync/bulk-update-empty-skus', [MarketplaceAutoSyncController::class, 'bulkUpdateEmptySkus']);
Route::post('marketplace/auto-sync/poll-shopee-orders', [MarketplaceAutoSyncController::class, 'pollShopeeOrders']);
Route::post('marketplace/auto-sync/poll-tiktok-orders', [MarketplaceAutoSyncController::class, 'pollTiktokOrders']);
Route::get('marketplace/import/shopee-gita/mass-update', [MarketplaceImportController::class, 'downloadShopeeGitaMassUpdate']);
Route::get('marketplace/import/shopee-gita/mass-update/{type}', [MarketplaceImportController::class, 'downloadShopeeGitaMassUpdateFile'])
    ->where('type', 'basic-info|sales-info|media-info|shipping-info|dts-info|republish-items');
Route::post('marketplace/import/manual-stock-sync', [MarketplaceImportController::class, 'manualStockSync']);
Route::get('marketplace/import/lazada/mass-update', [MarketplaceImportController::class, 'downloadLazadaMassUpdate']);
Route::get('marketplace/import/lazada/advanced-update', [MarketplaceImportController::class, 'downloadLazadaAdvancedUpdate']);
Route::post('webhooks/shopee', [MarketplaceWebhookController::class, 'shopee']);
Route::post('webhooks/tiktok', [MarketplaceWebhookController::class, 'tiktok']);

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::apiResource('products', ProductController::class);
Route::apiResource('categories', CategoryController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/items', [CartController::class, 'addItem']);
    Route::put('cart/items/{item}', [CartController::class, 'updateItem']);
    Route::delete('cart/items/{item}', [CartController::class, 'removeItem']);

    Route::post('orders', [OrderController::class, 'checkout']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
});
Route::get('pos/stock-master-products', [PosController::class, 'stockMasterProducts']);
Route::post('pos/offline-orders', [PosController::class, 'checkout']);

// Mobile Product Management Routes
Route::prefix('mobile')->group(function () {
    Route::get('products', [MobileProductController::class, 'index']);
    Route::get('products/detail', [MobileProductController::class, 'show']);
    Route::post('products/update-stock-price', [MobileProductController::class, 'updateStockPrice']);
    Route::post('products/update-cost-price', [MobileProductController::class, 'updateCostPrice']);
    Route::get('products/profit-calculation', [MobileProductController::class, 'profitCalculation']);
});
