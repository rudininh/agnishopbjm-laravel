<?php

$bool = static fn (string $key, bool $default = false): bool => filter_var(env($key, $default), FILTER_VALIDATE_BOOLEAN);
$int = static fn (string $key, int $default, int $min = 1, int $max = 1440): int => max($min, min($max, (int) env($key, $default)));

return [
    'mode' => 'stb-sync-worker',
    'sync_worker' => $bool('STB_SYNC_WORKER', false),
    'enable_frontend' => $bool('ENABLE_FRONTEND', true),
    'cache_marketplace_images' => $bool('CACHE_MARKETPLACE_IMAGES', ! $bool('STB_SYNC_WORKER', false)),
    'status_url' => trim((string) env('STB_STATUS_URL', '')),
    'mapping_sync_url' => trim((string) env('STB_MAPPING_SYNC_URL', '')),
    'mapping_sync_token' => trim((string) env('STB_MAPPING_SYNC_TOKEN', '')),
    'mapping_sync_enabled' => $bool('STB_MAPPING_SYNC_ENABLED', false),
    'mapping_sync_minutes' => $int('STB_MAPPING_SYNC_INTERVAL_MINUTES', 15, 1, 60),
    'mapping_sync_preserve_stock' => $bool('STB_MAPPING_SYNC_PRESERVE_STOCK', true),
    'token_sync_enabled' => $bool('STB_TOKEN_SYNC_ENABLED', false),
    'token_sync_url' => rtrim(trim((string) env('STB_TOKEN_SYNC_URL', '')), '/'),
    'token_sync_token' => trim((string) env('STB_TOKEN_SYNC_TOKEN', '')),
    'token_sync_minutes' => $int('STB_TOKEN_SYNC_INTERVAL_MINUTES', 5, 1, 60),
    'token_sync_timeout_seconds' => $int('STB_TOKEN_SYNC_TIMEOUT_SECONDS', 15, 3, 60),

    'features' => [
        'auto_browser' => $bool('ENABLE_AUTO_BROWSER', true),
        'order_sync' => $bool('ENABLE_ORDER_SYNC', true),
        'marketplace_sync' => $bool('ENABLE_MARKETPLACE_SYNC', true),
        'stock_analysis' => $bool('ENABLE_STOCK_ANALYSIS', true),
        'bulk_sku' => $bool('ENABLE_BULK_SKU', true),
    ],

    'intervals' => [
        'order_sync_minutes' => $int('ORDER_SYNC_INTERVAL_MINUTES', 5, 1, 60),
        'safety_check_minutes' => $int('SAFETY_CHECK_INTERVAL_MINUTES', 15, 1, 180),
        'marketplace_lite_minutes' => $int('FULL_MARKETPLACE_SYNC_INTERVAL_MINUTES', 60, 5, 60),
    ],

    'worker' => [
        'hours' => $int('ORDER_SYNC_LOOKBACK_HOURS', 24, 1, 72),
        'retry_attempts' => $int('STB_SYNC_RETRY_ATTEMPTS', 2, 1, 5),
        'retry_sleep_seconds' => $int('STB_SYNC_RETRY_SLEEP_SECONDS', 3, 0, 60),
        'order_product_refresh_limit' => $int('STB_ORDER_PRODUCT_REFRESH_LIMIT', 10, 1, 50),
        'heartbeat_timeout_minutes' => $int('STB_HEARTBEAT_TIMEOUT_MINUTES', 3, 1, 60),
        'supervisor_program' => env('STB_SUPERVISOR_PROGRAM', 'agnishop-worker'),
    ],
];
