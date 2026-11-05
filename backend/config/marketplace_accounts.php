<?php

return [
    'accounts' => [
        'shopee-agnishopbjm' => [
            'name' => 'Shopee AgniShopBJM',
            'channel' => 'shopee',
            'enabled' => true,
            'connect_action' => 'auth-shopee-agnishopbjm',
            'use_primary_app' => false,
            'credentials' => [
                'partner_id' => (int) env('SHOPEE_PARTNER_ID', 2013107),
                'partner_key' => trim((string) env('SHOPEE_PARTNER_KEY', '')),
                'host' => rtrim(trim((string) env('SHOPEE_HOST', 'https://partner.shopeemobile.com')), '/'),
                'redirect_url' => trim((string) env('SHOPEE_REDIRECT_URL', env('APP_URL', 'http://localhost:8000').'/api/shopee/callback')),
            ],
            'required_env' => [
                'SHOPEE_PARTNER_ID',
                'SHOPEE_PARTNER_KEY',
                'SHOPEE_REDIRECT_URL',
            ],
        ],
        'tiktok-agnishopbjm' => [
            'name' => 'TikTok AgniShopBJM',
            'channel' => 'tiktok',
            'enabled' => true,
            'connect_action' => 'auth-tiktok-agnishopbjm',
            'use_primary_app' => false,
            'credentials' => [
                'app_key' => trim((string) env('TIKTOK_APP_KEY', '')),
                'app_secret' => trim((string) env('TIKTOK_APP_SECRET', '')),
                'auth_host' => rtrim(trim((string) env('TIKTOK_AUTH_HOST', 'https://auth.tiktok-shops.com')), '/'),
                'api_host' => rtrim(trim((string) env('TIKTOK_API_HOST', 'https://open-api.tiktokglobalshop.com')), '/'),
                'redirect_url' => trim((string) env('TIKTOK_REDIRECT_URL', env('APP_URL', 'http://localhost:8000').'/api/tiktok/callback')),
                'warehouse_id' => trim((string) env('TIKTOK_DEFAULT_WAREHOUSE_ID', '')),
            ],
            'required_env' => [
                'TIKTOK_APP_KEY',
                'TIKTOK_APP_SECRET',
                'TIKTOK_REDIRECT_URL',
                'TIKTOK_DEFAULT_WAREHOUSE_ID',
            ],
        ],
        'shopee-gitacollectionbjm' => [
            'name' => 'Shopee GitaCollectionBJM',
            'channel' => 'shopee',
            'enabled' => filter_var(env('SHOPEE_GITA_ENABLED', true), FILTER_VALIDATE_BOOL),
            'connect_action' => 'auth-shopee-gitacollectionbjm',
            'use_primary_app' => filter_var(env('SHOPEE_GITA_USE_PRIMARY_APP', false), FILTER_VALIDATE_BOOL),
            'credentials' => [
                'partner_id' => (int) env('SHOPEE_GITA_PARTNER_ID', env('GITASHOPCOLLECTIONBJM_SHOPEE_PARTNER_ID', 0)),
                'partner_key' => trim((string) env('SHOPEE_GITA_PARTNER_KEY', env('GITASHOPCOLLECTIONBJM_SHOPEE_PARTNER_KEY', ''))),
                'host' => rtrim(trim((string) env('SHOPEE_GITA_HOST', 'https://partner.shopeemobile.com')), '/'),
                'redirect_url' => trim((string) env('SHOPEE_GITA_REDIRECT_URL', '')),
            ],
            'required_env' => [
                'SHOPEE_GITA_PARTNER_ID',
                'SHOPEE_GITA_PARTNER_KEY',
                'SHOPEE_GITA_REDIRECT_URL',
            ],
        ],
    ],
];
