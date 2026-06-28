<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class OmnichannelController extends Controller
{
    private const MARKETPLACE_ACCOUNTS = [
        'shopee-agnishopbjm' => [
            'channel' => 'shopee',
            'name' => 'Shopee AgniShopBJM',
        ],
        'shopee-gitacollectionbjm' => [
            'channel' => 'shopee',
            'name' => 'Shopee GitaCollectionBJM',
        ],
        'tiktok-agnishopbjm' => [
            'channel' => 'tiktok',
            'name' => 'TikTok AgniShopBJM',
        ],
    ];
    private const SHOPEE_ACCESS_TOKEN_REFRESH_BUFFER_MINUTES = 15;
    private const TIKTOK_ACCESS_TOKEN_REFRESH_BUFFER_MINUTES = 15;
    private const SHOPEE_REFRESH_TOKEN_VALID_DAYS = 365;

    public function dashboard(): JsonResponse
    {
        $this->ensureShopeeAuthColumns();
        $this->normalizeActiveMarketplaceTokens();

        return response()->json([
            'summary' => [
                'stock_master' => $this->tableCount('stock_master'),
                'shopee_products' => $this->tableCount('shopee_product'),
                'shopee_variants' => $this->tableCount('shopee_product_model'),
                'tiktok_products' => Schema::hasTable('tiktok_products')
                    ? DB::table('tiktok_products')->whereRaw('COALESCE(is_active, true) = true')->distinct('product_id')->count('product_id')
                    : 0,
                'tiktok_skus' => Schema::hasTable('tiktok_products')
                    ? DB::table('tiktok_products')->whereRaw('COALESCE(is_active, true) = true')->count()
                    : 0,
                'sku_mappings' => $this->tableCount('sku_mapping'),
                'shopee_tokens' => $this->tableCount('shopee_tokens'),
                'tiktok_tokens' => $this->tableCount('tiktok_tokens'),
            ],
            'tokens' => [
                'shopee' => $this->latestShopeeTokens()[0] ?? null,
                'tiktok' => $this->latestTokenPreview('tiktok_tokens'),
            ],
            'token_rows' => [
                'shopee' => $this->latestShopeeTokens(),
                'tiktok' => $this->latestTiktokTokens(),
            ],
            'database' => $this->databaseInfo(),
        ]);
    }

    public function productVariantAnalysis(Request $request): JsonResponse
    {
        $this->ensureProductVariantAnalysisTables();

        $channel = (string) $request->query('channel', 'all');
        if (! in_array($channel, ['all', 'shopee', 'tiktok'], true)) {
            $channel = 'all';
        }

        $catalog = [
            'shopee' => [],
            'tiktok' => [],
        ];
        $issues = [];

        if ($channel === 'all' || $channel === 'shopee') {
            $catalog['shopee'] = $this->shopeeProductsForVariantAnalysis();
            array_push($issues, ...$this->marketplaceProductVariantIssues('shopee', $catalog['shopee']));
        }

        if ($channel === 'all' || $channel === 'tiktok') {
            $catalog['tiktok'] = $this->tiktokProductsForVariantAnalysis();
            array_push($issues, ...$this->marketplaceProductVariantIssues('tiktok', $catalog['tiktok']));
        }

        $severityOrder = ['high' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, function (array $left, array $right) use ($severityOrder): int {
            $severityCompare = ($severityOrder[$left['severity']] ?? 99) <=> ($severityOrder[$right['severity']] ?? 99);
            if ($severityCompare !== 0) {
                return $severityCompare;
            }

            $channelCompare = strcmp((string) $left['channel'], (string) $right['channel']);
            if ($channelCompare !== 0) {
                return $channelCompare;
            }

            return strcmp((string) $left['title'], (string) $right['title']);
        });

        $confirmedIssueIds = $this->confirmedProductVariantAnalysisIssueIds();
        $allIssueCount = count($issues);
        $issues = array_values(array_filter(
            $issues,
            fn (array $issue) => ! isset($confirmedIssueIds[(string) ($issue['id'] ?? '')])
        ));
        $confirmedIssueCount = $allIssueCount - count($issues);

        $countWhere = static fn (array $rows, string $key, string $value): int => count(array_filter(
            $rows,
            fn (array $row) => ($row[$key] ?? null) === $value
        ));

        return response()->json([
            'status' => 'ok',
            'summary' => [
                'total_issues' => count($issues),
                'high' => $countWhere($issues, 'severity', 'high'),
                'warning' => $countWhere($issues, 'severity', 'warning'),
                'info' => $countWhere($issues, 'severity', 'info'),
                'duplicate_products' => $countWhere($issues, 'type', 'duplicate_product'),
                'duplicate_variants' => $countWhere($issues, 'type', 'duplicate_variant') + $countWhere($issues, 'type', 'duplicate_seller_sku'),
                'variant_anomalies' => $countWhere($issues, 'type', 'variant_anomaly'),
                'confirmed_not_anomaly' => $confirmedIssueCount,
                'shopee_products' => count($catalog['shopee']),
                'tiktok_products' => count($catalog['tiktok']),
                'last_shopee_sync_at' => Schema::hasTable('shopee_sync_logs') ? DB::table('shopee_sync_logs')->max('synced_at') : null,
                'last_tiktok_sync_at' => Schema::hasTable('tiktok_sync_logs') ? DB::table('tiktok_sync_logs')->max('synced_at') : null,
            ],
            'issues' => $issues,
        ]);
    }

    public function confirmProductVariantAnalysisIssue(Request $request): JsonResponse
    {
        $this->ensureProductVariantAnalysisTables();

        $data = $request->validate([
            'issue_id' => ['required', 'string', 'max:160'],
            'channel' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', 'string', 'max:80'],
            'product_name' => ['nullable', 'string', 'max:500'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:1000'],
            'issue' => ['nullable', 'array'],
        ]);

        $issueId = trim((string) $data['issue_id']);
        $issue = $data['issue'] ?? [];
        $productIds = $data['product_ids'] ?? ($issue['product_ids'] ?? []);
        $productIds = array_values(array_filter(array_map(
            fn ($productId) => trim((string) $productId),
            is_array($productIds) ? $productIds : []
        ), fn (string $productId) => $productId !== ''));

        DB::table('product_variant_analysis_confirmations')->updateOrInsert(
            ['issue_id' => $issueId],
            [
                'channel' => trim((string) ($data['channel'] ?? ($issue['channel'] ?? ''))) ?: null,
                'type' => trim((string) ($data['type'] ?? ($issue['type'] ?? ''))) ?: null,
                'product_name' => mb_substr(trim((string) ($data['product_name'] ?? ($issue['product_name'] ?? ''))), 0, 500) ?: null,
                'product_ids' => json_encode($productIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'not_anomaly',
                'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 1000) ?: null,
                'issue_snapshot' => json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'confirmed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Temuan sudah ditandai sebagai bukan anomali.',
            'issue_id' => $issueId,
        ]);
    }

    public function imageVariantAnomalies(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $type = trim((string) $request->query('type', ''));
        $search = mb_strtolower(trim((string) $request->query('search', '')));
        $perPage = max(1, min(100, (int) $request->query('per_page', 30)));
        $page = max(1, (int) $request->query('page', 1));

        $rows = DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product as sp', function ($join) {
                $join->on('sp.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->whereRaw('COALESCE(sp.is_active, true) = true');
            })
            ->leftJoin('shopee_product_model as spm', function ($join) {
                $join->on('spm.item_id', '=', 'sp.item_id')
                    ->on('spm.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, model_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NOT NULL GROUP BY item_id, model_id) as spmi'), function ($join) {
                $join->on('spmi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->on('spmi.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NULL GROUP BY item_id) as spi'), function ($join) {
                $join->on('spi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->leftJoin('tiktok_products as tp', function ($join) {
                $join->on('tp.product_id', '=', DB::raw("COALESCE(NULLIF(map.tiktok_product_id, ''), NULLIF(sm.tiktok_product_id, ''))"))
                    ->on('tp.sku_id', '=', DB::raw("COALESCE(NULLIF(map.tiktok_sku_id, ''), NULLIF(sm.tiktok_sku, ''))"))
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->leftJoin(DB::raw("(
                SELECT DISTINCT ON (LOWER(TRIM(seller_sku)))
                    product_id,
                    product_name,
                    sku_id,
                    sku_name,
                    seller_sku,
                    image_url,
                    updated_at
                FROM tiktok_products
                WHERE COALESCE(is_active, true) = true
                  AND seller_sku IS NOT NULL
                  AND TRIM(seller_sku) <> ''
                ORDER BY LOWER(TRIM(seller_sku)), updated_at DESC, id DESC
            ) as tps"), function ($join) {
                $join->on(
                    DB::raw('LOWER(TRIM(tps.seller_sku))'),
                    '=',
                    DB::raw("LOWER(TRIM(COALESCE(NULLIF(map.seller_sku, ''), NULLIF(sm.internal_sku, ''), NULLIF(sm.tiktok_seller_sku, ''), NULLIF(sm.shopee_seller_sku, ''))))")
                );
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->select(
                'sm.id',
                'sm.internal_sku',
                'sm.product_name',
                'sm.variant_name',
                'sm.updated_at',
                'map.seller_sku as mapped_seller_sku',
                'map.internal_image_url',
                'map.shopee_image_url',
                'map.tiktok_image_url',
                'map.shopee_item_id',
                'map.shopee_model_id',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id',
                'sm.shopee_product_id',
                'sm.shopee_sku',
                'sm.shopee_seller_sku',
                'sm.tiktok_product_id',
                'sm.tiktok_sku',
                'sm.tiktok_seller_sku',
                'sp.name as shopee_product_name',
                'spm.name as shopee_variant_name',
                'spm.model_sku as shopee_model_sku',
                'spmi.image_url as shopee_model_image_url',
                'spi.image_url as shopee_product_image_url',
                'tp.product_name as tiktok_product_name',
                'tp.sku_name as tiktok_variant_name',
                'tp.seller_sku as tiktok_seller_sku_actual',
                'tp.image_url as tiktok_actual_image_url',
                'tps.product_id as tiktok_sku_match_product_id',
                'tps.product_name as tiktok_sku_match_product_name',
                'tps.sku_id as tiktok_sku_match_sku_id',
                'tps.sku_name as tiktok_sku_match_variant_name',
                'tps.seller_sku as tiktok_sku_match_seller_sku',
                'tps.image_url as tiktok_sku_match_image_url'
            )
            ->orderBy('sm.product_name')
            ->orderBy('sm.variant_name')
            ->get();

        $items = $rows
            ->map(function ($row): array {
                $shopeeImage = $this->firstFilledImage([
                    $row->shopee_model_image_url,
                    $row->shopee_image_url,
                    $row->shopee_product_image_url,
                ]);
                $tiktokImage = $this->firstFilledImage([
                    $row->tiktok_actual_image_url,
                    $row->tiktok_sku_match_image_url,
                    $row->tiktok_image_url,
                ]);
                $hasShopeeIdentity = $this->filledString($row->shopee_model_id ?? null)
                    || $this->filledString($row->shopee_sku ?? null)
                    || $this->filledString($row->shopee_model_sku ?? null);
                $hasTiktokIdentity = $this->filledString($row->tiktok_sku_id ?? null)
                    || $this->filledString($row->tiktok_sku ?? null)
                    || $this->filledString($row->tiktok_variant_name ?? null)
                    || $this->filledString($row->tiktok_sku_match_sku_id ?? null)
                    || $this->filledString($row->tiktok_sku_match_seller_sku ?? null);

                $issueType = $this->imageVariantIssueType($hasShopeeIdentity, $hasTiktokIdentity, $shopeeImage, $tiktokImage);
                $suggestedSource = $shopeeImage !== '' ? 'shopee' : ($tiktokImage !== '' ? 'tiktok' : ($this->filledString($row->internal_image_url ?? null) ? 'internal' : null));

                return [
                    'stock_master_id' => (int) $row->id,
                    'sku' => $this->bestSkuMappingSkuValue([
                        $row->mapped_seller_sku,
                        $row->internal_sku,
                        $row->shopee_seller_sku,
                        $row->shopee_model_sku,
                        $row->tiktok_seller_sku_actual,
                        $row->tiktok_sku_match_seller_sku,
                        $row->tiktok_seller_sku,
                    ]),
                    'product_name' => $row->product_name ?: ($row->shopee_product_name ?: ($row->tiktok_product_name ?: $row->tiktok_sku_match_product_name)),
                    'variant_name' => $row->variant_name ?: ($row->shopee_variant_name ?: ($row->tiktok_variant_name ?: $row->tiktok_sku_match_variant_name)),
                    'issue_type' => $issueType,
                    'severity' => $issueType === 'image_url_mismatch' ? 'warning' : 'error',
                    'message' => $this->imageVariantIssueMessage($issueType),
                    'suggested_source' => $suggestedSource,
                    'internal_image_url' => trim((string) ($row->internal_image_url ?? '')),
                    'shopee' => [
                        'product_id' => $row->shopee_item_id ?: $row->shopee_product_id,
                        'model_id' => $row->shopee_model_id ?: $row->shopee_sku,
                        'variant_name' => $row->shopee_variant_name ?: $row->variant_name,
                        'seller_sku' => $row->shopee_model_sku ?: $row->shopee_seller_sku,
                        'image_url' => $shopeeImage,
                    ],
                    'tiktok' => [
                        'product_id' => $row->mapped_tiktok_product_id ?: ($row->tiktok_product_id ?: $row->tiktok_sku_match_product_id),
                        'sku_id' => $row->tiktok_sku_id ?: ($row->tiktok_sku ?: $row->tiktok_sku_match_sku_id),
                        'variant_name' => $row->tiktok_variant_name ?: ($row->tiktok_sku_match_variant_name ?: $row->variant_name),
                        'seller_sku' => $row->tiktok_seller_sku_actual ?: ($row->tiktok_sku_match_seller_sku ?: $row->tiktok_seller_sku),
                        'image_url' => $tiktokImage,
                    ],
                    'updated_at' => $row->updated_at,
                ];
            })
            ->filter(fn (array $item): bool => $item['issue_type'] !== 'matched')
            ->values();

        $summary = [
            'total_anomalies' => $items->count(),
            'missing_shopee_image' => $items->where('issue_type', 'missing_shopee_image')->count(),
            'missing_tiktok_image' => $items->where('issue_type', 'missing_tiktok_image')->count(),
            'image_url_mismatch' => $items->where('issue_type', 'image_url_mismatch')->count(),
            'incomplete_mapping' => $items->where('issue_type', 'incomplete_mapping')->count(),
        ];

        if ($type !== '') {
            $items = $items->where('issue_type', $type)->values();
        }

        if ($search !== '') {
            $items = $items->filter(function (array $item) use ($search): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $item['sku'] ?? '',
                    $item['product_name'] ?? '',
                    $item['variant_name'] ?? '',
                    $item['shopee']['product_id'] ?? '',
                    $item['tiktok']['product_id'] ?? '',
                ]));

                return str_contains($haystack, $search);
            })->values();
        }

        $total = $items->count();
        $pagedItems = $items->forPage($page, $perPage)->values();

        return response()->json([
            'summary' => $summary,
            'items' => $pagedItems,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function syncTiktokImagesFromShopee(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'dry_run' => ['nullable'],
            'force' => ['nullable'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'seller_sku' => ['nullable', 'string', 'max:150'],
        ]);

        $dryRun = $this->boolString($data['dry_run'] ?? false) === 'true';
        $force = $this->boolString($data['force'] ?? false) === 'true';
        $limit = isset($data['limit']) ? (int) $data['limit'] : 10;
        $offset = isset($data['offset']) ? max(0, (int) $data['offset']) : 0;
        $sellerSkuFilter = trim((string) ($data['seller_sku'] ?? ''));
        if (! $dryRun) {
            $this->autoRefreshMarketplaceTokens();
        }

        $context = $this->resolveTiktokGetProductContext(['version' => '202509']);
        $accessToken = trim((string) ($context['access_token'] ?? ''));
        $shopId = trim((string) ($context['shop_id'] ?? ''));
        $shopCipher = trim((string) ($context['shop_cipher'] ?? ''));
        abort_if($accessToken === '', 422, 'Token TikTok belum aktif. Jalankan login/refresh token dulu.');
        abort_if($shopId === '' || $shopCipher === '', 422, 'Shop TikTok belum lengkap. Jalankan Get Auth Shop dulu.');

        $candidateResult = $this->tiktokImageSyncCandidatesFromShopee(
            $sellerSkuFilter !== '' ? null : $limit,
            $force,
            $sellerSkuFilter !== '' ? 0 : $offset
        );
        if ($sellerSkuFilter !== '') {
            $filteredCandidates = array_values(array_filter(
                $candidateResult['items'],
                fn (array $item): bool => $this->sameExactMarketplaceSku($item['seller_sku'] ?? '', $sellerSkuFilter)
            ));
            $candidateResult['items'] = array_slice($filteredCandidates, $offset, $limit);
            $candidateResult['has_more'] = ($offset + $limit) < count($filteredCandidates);
        }
        $candidates = $candidateResult['items'];
        $skippedReasons = $candidateResult['skipped_reasons'];
        $skippedDetails = $candidateResult['skipped_details'];

        $result = [
            'status' => 'ok',
            'message' => 'Tidak ada gambar TikTok yang perlu diproses dari Shopee.',
            'dry_run' => $dryRun,
            'force' => $force,
            'checked' => (int) $candidateResult['checked'],
            'batch_limit' => $limit,
            'offset' => $offset,
            'has_more' => (bool) ($candidateResult['has_more'] ?? false),
            'matched' => count($candidates),
            'products' => 0,
            'updated_products' => 0,
            'updated_variants' => 0,
            'skipped' => array_sum($skippedReasons),
            'failed' => 0,
            'warnings' => 0,
            'skipped_reasons' => $skippedReasons,
            'skipped_details' => array_slice($skippedDetails, 0, 50),
            'failure_reasons' => [],
            'warning_reasons' => [],
            'details' => [],
        ];

        if ($candidates === []) {
            return response()->json($result);
        }

        $config = $this->tiktokConfig();
        $shop = (object) [
            'shop_id' => $shopId,
            'shop_cipher' => $shopCipher,
            'cipher' => $shopCipher,
        ];
        $uploadCache = [];
        $now = now();
        $productGroups = collect($candidates)->groupBy('tiktok_product_id');
        $result['products'] = $productGroups->count();

        foreach ($productGroups as $productId => $group) {
            $productId = trim((string) $productId);
            $groupRows = $group->values()->all();
            $productDetail = null;
            $requestPayload = null;
            $responsePayload = null;

            try {
                $detail = $this->fetchTiktokProductDetail(
                    $config,
                    $accessToken,
                    $shop,
                    $productId,
                    $config['api_host'].'/product/202309/products/'
                );
                abort_if(! is_array($detail), 422, 'Detail produk TikTok belum bisa dibaca untuk menjaga SKU lain tidak berubah.');
                $productDetail = is_array($detail['product'] ?? null) ? $detail['product'] : $detail;

                $skuImageUpdates = [];
                foreach ($groupRows as $candidate) {
                    $skuId = trim((string) ($candidate['tiktok_sku_id'] ?? ''));
                    $sourceImageUrl = trim((string) ($candidate['shopee_variant_image_url'] ?? ''));

                    if ($skuId === '' || $sourceImageUrl === '') {
                        continue;
                    }

                    if ($dryRun) {
                        $skuImageUpdates[$skuId] = [
                            'uri' => $sourceImageUrl,
                            'source_image_url' => $sourceImageUrl,
                            'candidate' => $candidate,
                        ];
                        continue;
                    }

                    $uploadResult = $this->uploadTiktokImageForShopeeSync($accessToken, $sourceImageUrl, $uploadCache);
                    $uploadedUri = trim((string) ($uploadResult['uri'] ?? ''));
                    if (($uploadResult['ok'] ?? false) !== true || $uploadedUri === '') {
                        $message = $uploadResult['message'] ?? 'Upload gambar Shopee ke TikTok gagal.';
                        $result['failed']++;
                        $this->addTiktokImageSyncReason($result, 'failure_reasons', $message, [
                            'stock_master_id' => $candidate['stock_master_id'] ?? null,
                            'product_id' => $productId,
                            'sku_id' => $skuId,
                            'seller_sku' => $candidate['seller_sku'] ?? null,
                        ]);
                        $result['details'][] = [
                            'status' => 'error',
                            'stock_master_id' => $candidate['stock_master_id'] ?? null,
                            'product_id' => $productId,
                            'sku_id' => $skuId,
                            'seller_sku' => $candidate['seller_sku'] ?? null,
                            'message' => $message,
                        ];
                        $this->recordTiktokImageSyncAction($candidate, 'error', null, $uploadResult, $message);
                        continue;
                    }

                    $skuImageUpdates[$skuId] = [
                        'uri' => $uploadedUri,
                        'source_image_url' => $sourceImageUrl,
                        'candidate' => $candidate,
                    ];
                }

                $productImageUris = [];
                foreach ($this->shopeeProductMainImageUrlsForTiktokSync($groupRows) as $sourceImageUrl) {
                    if ($dryRun) {
                        $productImageUris[] = $sourceImageUrl;
                        continue;
                    }

                    $uploadResult = $this->uploadTiktokImageForShopeeSync($accessToken, $sourceImageUrl, $uploadCache);
                    $uploadedUri = trim((string) ($uploadResult['uri'] ?? ''));
                    if (($uploadResult['ok'] ?? false) === true && $uploadedUri !== '') {
                        $productImageUris[] = $uploadedUri;
                    }
                }

                $skuBuild = $this->buildTiktokImagePartialEditSkuRows(
                    $productDetail,
                    $skuImageUpdates,
                    $this->tiktokDeletedVariantIdsForProduct($productId)
                );
                $foundSkuIds = array_flip($skuBuild['found_sku_ids']);
                foreach ($skuImageUpdates as $skuId => $update) {
                    $skuId = trim((string) $skuId);
                    if (! isset($foundSkuIds[$skuId])) {
                        $candidate = $update['candidate'] ?? [];
                        $message = 'SKU TikTok target tidak ditemukan di detail terbaru atau tidak punya atribut gambar varian.';
                        $result['failed']++;
                        $this->addTiktokImageSyncReason($result, 'failure_reasons', $message, [
                            'stock_master_id' => $candidate['stock_master_id'] ?? null,
                            'product_id' => $productId,
                            'sku_id' => $skuId,
                            'seller_sku' => $candidate['seller_sku'] ?? null,
                        ]);
                        $result['details'][] = [
                            'status' => 'error',
                            'stock_master_id' => $candidate['stock_master_id'] ?? null,
                            'product_id' => $productId,
                            'sku_id' => $skuId,
                            'seller_sku' => $candidate['seller_sku'] ?? null,
                            'message' => $message,
                        ];
                        $this->recordTiktokImageSyncAction($candidate, 'error', null, null, $message);
                        unset($skuImageUpdates[$skuId]);
                    }
                }

                $variantPayload = null;
                $productPayload = null;
                $variantRequestPayload = null;
                $variantResponsePayload = null;
                $productRequestPayload = null;
                $productResponsePayload = null;
                $productImageUris = array_values(array_unique(array_filter($productImageUris)));
                if ($productImageUris !== []) {
                    $productPayload = [
                        'save_mode' => 'LISTING',
                        'main_images' => array_map(
                            fn (string $uri): array => ['uri' => $uri],
                            array_slice($productImageUris, 0, 9)
                        ),
                    ];
                }
                if ($skuImageUpdates !== []) {
                    $variantPayload = [
                        'save_mode' => 'LISTING',
                        'skus' => $skuBuild['rows'],
                    ];
                }

                if ($productPayload === null && $variantPayload === null) {
                    $result['skipped'] += count($groupRows);
                    $result['details'][] = [
                        'status' => 'skipped',
                        'product_id' => $productId,
                        'message' => 'Tidak ada gambar valid yang bisa dikirim untuk produk TikTok ini.',
                    ];
                    continue;
                }

                $tiktokPath = '/product/202509/products/'.$productId.'/partial_edit';

                if ($variantPayload !== null) {
                    $variantRequestPayload = $this->summarizeTiktokImageSyncRequest($tiktokPath, $variantPayload);
                    $variantResponsePayload = $dryRun
                        ? ['code' => 0, 'message' => 'Dry run. Request varian belum dikirim.']
                        : $this->summarizeTiktokImageSyncResponse($this->submitTiktokPartialEditPayload($tiktokPath, $variantPayload, $context));

                    if ((int) ($variantResponsePayload['code'] ?? -1) !== 0) {
                        $message = $variantResponsePayload['message'] ?? 'TikTok menolak update gambar varian.';
                        $result['failed'] += count($skuImageUpdates);
                        $result['details'][] = [
                            'status' => 'error',
                            'product_id' => $productId,
                            'message' => $message,
                        ];

                        foreach ($skuImageUpdates as $update) {
                            $candidate = $update['candidate'] ?? [];
                            $this->addTiktokImageSyncReason($result, 'failure_reasons', $message, [
                                'stock_master_id' => $candidate['stock_master_id'] ?? null,
                                'product_id' => $productId,
                                'sku_id' => $candidate['tiktok_sku_id'] ?? null,
                                'seller_sku' => $candidate['seller_sku'] ?? null,
                            ]);
                            $this->recordTiktokImageSyncAction($candidate, 'error', $variantRequestPayload, $variantResponsePayload, $message);
                        }

                        continue;
                    }

                    if (! $dryRun) {
                        $verification = $this->verifyTiktokSkuImageUpdates(
                            $config,
                            $accessToken,
                            $shop,
                            $productId,
                            $skuImageUpdates
                        );

                        foreach ($verification['failed'] as $skuId => $failure) {
                            $update = $skuImageUpdates[$skuId] ?? [];
                            $candidate = $update['candidate'] ?? [];
                            $message = $failure['message'] ?? 'TikTok menerima request, tetapi gambar varian belum berubah setelah verifikasi.';
                            $result['failed']++;
                            $this->addTiktokImageSyncReason($result, 'failure_reasons', $message, [
                                'stock_master_id' => $candidate['stock_master_id'] ?? null,
                                'product_id' => $productId,
                                'sku_id' => $skuId,
                                'seller_sku' => $candidate['seller_sku'] ?? null,
                            ]);
                            $result['details'][] = [
                                'status' => 'error',
                                'stock_master_id' => $candidate['stock_master_id'] ?? null,
                                'product_id' => $productId,
                                'sku_id' => $skuId,
                                'seller_sku' => $candidate['seller_sku'] ?? null,
                                'message' => $message,
                            ];
                            $this->recordTiktokImageSyncAction(
                                $candidate,
                                'error',
                                $variantRequestPayload,
                                [...$variantResponsePayload, 'verification' => $failure],
                                $message
                            );
                            unset($skuImageUpdates[$skuId]);
                        }
                    }
                }

                if ($productPayload !== null) {
                    $productRequestPayload = $this->summarizeTiktokImageSyncRequest($tiktokPath, $productPayload);
                    $productResponsePayload = $dryRun
                        ? ['code' => 0, 'message' => 'Dry run. Request main image belum dikirim.']
                        : $this->summarizeTiktokImageSyncResponse($this->submitTiktokPartialEditPayload($tiktokPath, $productPayload, $context));

                    if ((int) ($productResponsePayload['code'] ?? -1) === 0) {
                        $result['updated_products']++;
                    } else {
                        $message = $productResponsePayload['message'] ?? 'Main image produk TikTok belum berhasil dikirim.';
                        $result['warnings']++;
                        $this->addTiktokImageSyncReason($result, 'warning_reasons', $message, [
                            'product_id' => $productId,
                        ]);
                        $result['details'][] = [
                            'status' => 'warning',
                            'product_id' => $productId,
                            'message' => $message,
                        ];
                    }
                }

                $requestPayload = $variantRequestPayload ?? $productRequestPayload;
                $responsePayload = $variantResponsePayload ?? $productResponsePayload;

                foreach ($skuImageUpdates as $skuId => $update) {
                    $skuId = trim((string) $skuId);
                    $candidate = $update['candidate'] ?? [];
                    $sourceImageUrl = trim((string) ($update['source_image_url'] ?? ''));
                    $result['updated_variants']++;
                    $result['details'][] = [
                        'status' => $dryRun ? 'dry_run' : 'ok',
                        'stock_master_id' => $candidate['stock_master_id'] ?? null,
                        'product_id' => $productId,
                        'sku_id' => $skuId,
                        'seller_sku' => $candidate['seller_sku'] ?? null,
                        'source_image_url' => $sourceImageUrl,
                        'message' => $dryRun ? 'Dry run gambar siap dikirim.' : 'Gambar TikTok diperbarui mengikuti Shopee.',
                    ];

                    if (! $dryRun) {
                        DB::table('tiktok_products')
                            ->where('product_id', (string) $productId)
                            ->where('sku_id', (string) $skuId)
                            ->update([
                                'image_url' => $sourceImageUrl ?: null,
                                'updated_at' => $now,
                            ]);

                        if (! empty($candidate['stock_master_id'])) {
                            DB::table('sku_mappings')->updateOrInsert(
                                ['stock_master_id' => (int) $candidate['stock_master_id']],
                                [
                                    'shopee_item_id' => $candidate['shopee_item_id'] ?? null,
                                    'shopee_model_id' => $candidate['shopee_model_id'] ?? null,
                                    'tiktok_product_id' => (string) $productId,
                                    'tiktok_sku_id' => (string) $skuId,
                                    'tiktok_sku_name' => $candidate['tiktok_sku_name'] ?? null,
                                    'seller_sku' => $candidate['seller_sku'] ?? null,
                                    'shopee_image_url' => $sourceImageUrl ?: null,
                                    'tiktok_image_url' => $sourceImageUrl ?: null,
                                    'updated_at' => $now,
                                    'created_at' => $now,
                                ]
                            );
                        }
                    }

                    $this->recordTiktokImageSyncAction(
                        $candidate,
                        $dryRun ? 'dry_run' : 'ok',
                        $requestPayload,
                        $responsePayload,
                        $dryRun ? 'Dry run gambar TikTok dari Shopee.' : 'Gambar TikTok diperbarui mengikuti Shopee.'
                    );
                }
            } catch (\Throwable $exception) {
                $message = $exception->getMessage();
                $result['failed'] += count($groupRows);
                $result['details'][] = [
                    'status' => 'error',
                    'product_id' => $productId,
                    'message' => $message,
                ];

                foreach ($groupRows as $candidate) {
                    $this->addTiktokImageSyncReason($result, 'failure_reasons', $message, [
                        'stock_master_id' => $candidate['stock_master_id'] ?? null,
                        'product_id' => $productId,
                        'sku_id' => $candidate['tiktok_sku_id'] ?? null,
                        'seller_sku' => $candidate['seller_sku'] ?? null,
                    ]);
                    $this->recordTiktokImageSyncAction(
                        $candidate,
                        'error',
                        $requestPayload,
                        $responsePayload,
                        $message
                    );
                }
            }
        }

        $result['details'] = array_slice($result['details'], 0, 100);
        $result['failure_reasons'] = array_values($result['failure_reasons']);
        $result['warning_reasons'] = array_values($result['warning_reasons']);
        $result['status'] = $result['failed'] > 0 ? 'warning' : 'ok';
        $result['message'] = $dryRun
            ? 'Preview sinkron gambar TikTok dari Shopee selesai.'
            : 'Sinkron gambar TikTok dari Shopee selesai diproses.';

        return response()->json($result, $result['status'] === 'warning' ? 207 : 200);
    }

    private function tiktokImageSyncCandidatesFromShopee(?int $limit = null, bool $force = false, int $offset = 0): array
    {
        $tiktokBySellerSku = "(
            SELECT product_id, product_name, sku_id, sku_name, seller_sku, image_url, updated_at
            FROM (
                SELECT
                    product_id,
                    product_name,
                    sku_id,
                    sku_name,
                    seller_sku,
                    image_url,
                    updated_at,
                    id,
                    COUNT(*) OVER (PARTITION BY LOWER(TRIM(seller_sku))) as seller_sku_count,
                    ROW_NUMBER() OVER (PARTITION BY LOWER(TRIM(seller_sku)) ORDER BY updated_at DESC, id DESC) as row_number
                FROM tiktok_products
                WHERE COALESCE(is_active, true) = true
                  AND seller_sku IS NOT NULL
                  AND TRIM(seller_sku) <> ''
            ) ranked_tiktok_skus
            WHERE seller_sku_count = 1
              AND row_number = 1
        ) as tps";

        $query = DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product as sp', function ($join) {
                $join->on('sp.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->whereRaw('COALESCE(sp.is_active, true) = true');
            })
            ->leftJoin('shopee_product_model as spm', function ($join) {
                $join->on('spm.item_id', '=', 'sp.item_id')
                    ->on('spm.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, model_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NOT NULL GROUP BY item_id, model_id) as spmi'), function ($join) {
                $join->on('spmi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->on('spmi.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NULL GROUP BY item_id) as spi'), function ($join) {
                $join->on('spi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->leftJoin('tiktok_products as tp', function ($join) {
                $join->on('tp.product_id', '=', DB::raw("COALESCE(NULLIF(map.tiktok_product_id, ''), NULLIF(sm.tiktok_product_id, ''))"))
                    ->on('tp.sku_id', '=', DB::raw("COALESCE(NULLIF(map.tiktok_sku_id, ''), NULLIF(sm.tiktok_sku, ''))"))
                    ->whereRaw('COALESCE(tp.is_active, true) = true');
            })
            ->leftJoin(DB::raw($tiktokBySellerSku), function ($join) {
                $join->on(
                    DB::raw('LOWER(TRIM(tps.seller_sku))'),
                    '=',
                    DB::raw("LOWER(TRIM(COALESCE(NULLIF(spm.model_sku, ''), NULLIF(sm.shopee_seller_sku, ''), NULLIF(map.seller_sku, ''), NULLIF(sm.internal_sku, ''))))")
                );
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->select(
                'sm.id as stock_master_id',
                'sm.internal_sku',
                'sm.product_name',
                'sm.variant_name',
                'map.seller_sku as mapped_seller_sku',
                'map.shopee_item_id',
                'map.shopee_model_id',
                'map.shopee_image_url',
                'map.tiktok_image_url as mapped_tiktok_image_url',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id',
                'map.tiktok_sku_name',
                'sm.shopee_product_id',
                'sm.shopee_sku',
                'sm.shopee_seller_sku',
                'sm.tiktok_product_id',
                'sm.tiktok_sku',
                'sm.tiktok_seller_sku',
                'sp.name as shopee_product_name',
                'spm.name as shopee_variant_name',
                'spm.model_sku as shopee_model_sku',
                'spmi.image_url as shopee_model_image_url',
                'spi.image_url as shopee_product_image_url',
                'tp.product_id as mapped_actual_tiktok_product_id',
                'tp.product_name as mapped_actual_tiktok_product_name',
                'tp.sku_id as mapped_actual_tiktok_sku_id',
                'tp.sku_name as mapped_actual_tiktok_sku_name',
                'tp.seller_sku as mapped_actual_tiktok_seller_sku',
                'tp.image_url as mapped_actual_tiktok_image_url',
                'tps.product_id as sku_match_tiktok_product_id',
                'tps.product_name as sku_match_tiktok_product_name',
                'tps.sku_id as sku_match_tiktok_sku_id',
                'tps.sku_name as sku_match_tiktok_sku_name',
                'tps.seller_sku as sku_match_tiktok_seller_sku',
                'tps.image_url as sku_match_tiktok_image_url'
            )
            ->orderBy('sm.product_name')
            ->orderBy('sm.variant_name');

        $rows = $query->get();
        $items = [];
        $blockedTargets = [];
        $skippedReasons = [
            'missing_shopee_sku' => 0,
            'missing_shopee_image' => 0,
            'missing_exact_tiktok_sku' => 0,
            'conflicting_target_image' => 0,
            'already_synced' => 0,
        ];
        $skippedDetails = [];

        foreach ($rows as $row) {
            $shopeeSellerSku = $this->firstFilledText([
                $row->shopee_model_sku,
                $row->shopee_seller_sku,
                $row->mapped_seller_sku,
                $row->internal_sku,
            ]);
            $shopeeSellerSkuKey = $this->exactMarketplaceSkuKey($shopeeSellerSku);
            if ($shopeeSellerSkuKey === '') {
                $skippedReasons['missing_shopee_sku']++;
                continue;
            }

            $sourceImageUrl = $this->firstFilledImage([
                $row->shopee_model_image_url,
                $row->shopee_image_url,
                $row->shopee_product_image_url,
            ]);
            if ($sourceImageUrl === '') {
                $skippedReasons['missing_shopee_image']++;
                $skippedDetails[] = [
                    'stock_master_id' => (int) $row->stock_master_id,
                    'seller_sku' => $shopeeSellerSku,
                    'reason' => 'Gambar Shopee kosong.',
                ];
                continue;
            }

            $target = null;
            if ($this->sameExactMarketplaceSku($shopeeSellerSku, $row->mapped_actual_tiktok_seller_sku ?? null)) {
                $target = [
                    'product_id' => trim((string) ($row->mapped_actual_tiktok_product_id ?? '')),
                    'product_name' => $row->mapped_actual_tiktok_product_name ?? null,
                    'sku_id' => trim((string) ($row->mapped_actual_tiktok_sku_id ?? '')),
                    'sku_name' => $row->mapped_actual_tiktok_sku_name ?? null,
                    'seller_sku' => trim((string) ($row->mapped_actual_tiktok_seller_sku ?? '')),
                    'image_url' => $row->mapped_actual_tiktok_image_url ?? null,
                    'source' => 'mapped',
                ];
            } elseif ($this->sameExactMarketplaceSku($shopeeSellerSku, $row->sku_match_tiktok_seller_sku ?? null)) {
                $target = [
                    'product_id' => trim((string) ($row->sku_match_tiktok_product_id ?? '')),
                    'product_name' => $row->sku_match_tiktok_product_name ?? null,
                    'sku_id' => trim((string) ($row->sku_match_tiktok_sku_id ?? '')),
                    'sku_name' => $row->sku_match_tiktok_sku_name ?? null,
                    'seller_sku' => trim((string) ($row->sku_match_tiktok_seller_sku ?? '')),
                    'image_url' => $row->sku_match_tiktok_image_url ?? null,
                    'source' => 'seller_sku',
                ];
            }

            if (! $target || $target['product_id'] === '' || $target['sku_id'] === '') {
                $skippedReasons['missing_exact_tiktok_sku']++;
                continue;
            }

            if (! $force && $this->sameSyncedImageReference($sourceImageUrl, $target['image_url'] ?? null)) {
                $skippedReasons['already_synced']++;
                continue;
            }

            $targetKey = $target['product_id'].'|'.$target['sku_id'];
            if (isset($blockedTargets[$targetKey])) {
                $skippedReasons['conflicting_target_image']++;
                continue;
            }

            $item = [
                'stock_master_id' => (int) $row->stock_master_id,
                'product_name' => $row->product_name ?: ($row->shopee_product_name ?: $target['product_name']),
                'variant_name' => $row->variant_name ?: ($row->shopee_variant_name ?: $target['sku_name']),
                'seller_sku' => $shopeeSellerSku,
                'shopee_item_id' => $row->shopee_item_id ?: $row->shopee_product_id,
                'shopee_model_id' => $row->shopee_model_id ?: $row->shopee_sku,
                'shopee_variant_image_url' => $sourceImageUrl,
                'shopee_product_image_url' => trim((string) ($row->shopee_product_image_url ?? '')),
                'tiktok_product_id' => $target['product_id'],
                'tiktok_sku_id' => $target['sku_id'],
                'tiktok_sku_name' => $target['sku_name'],
                'tiktok_current_image_url' => $target['image_url'],
                'match_source' => $target['source'],
            ];

            if (isset($items[$targetKey])) {
                $existingImage = trim((string) ($items[$targetKey]['shopee_variant_image_url'] ?? ''));
                if ($existingImage !== $sourceImageUrl) {
                    unset($items[$targetKey]);
                    $blockedTargets[$targetKey] = true;
                    $skippedReasons['conflicting_target_image']++;
                    $skippedDetails[] = [
                        'product_id' => $target['product_id'],
                        'sku_id' => $target['sku_id'],
                        'seller_sku' => $shopeeSellerSku,
                        'reason' => 'Satu SKU TikTok cocok ke lebih dari satu gambar Shopee.',
                    ];
                }

                continue;
            }

            $items[$targetKey] = $item;
        }

        $items = array_values($items);
        $offset = max(0, $offset);
        $hasMore = $limit !== null && $limit > 0 && ($offset + $limit) < count($items);
        if ($limit !== null && $limit > 0) {
            $items = array_slice($items, $offset, $limit);
        }

        return [
            'checked' => $rows->count(),
            'items' => $items,
            'has_more' => $hasMore,
            'skipped_reasons' => $skippedReasons,
            'skipped_details' => $skippedDetails,
        ];
    }

    private function shopeeProductMainImageUrlsForTiktokSync(array $candidates): array
    {
        $itemIds = collect($candidates)
            ->pluck('shopee_item_id')
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => preg_match('/^\d+$/', $value) === 1)
            ->unique()
            ->values()
            ->all();

        if (count($itemIds) > 1) {
            return [];
        }

        $urls = [];
        if ($itemIds !== []) {
            $urls = DB::table('shopee_product_image')
                ->whereIn('item_id', array_map('intval', $itemIds))
                ->whereNull('model_id')
                ->whereNotNull('image_url')
                ->orderBy('id')
                ->pluck('image_url')
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if ($urls === []) {
            $urls = collect($candidates)
                ->pluck('shopee_variant_image_url')
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return array_slice($urls, 0, 9);
    }

    private function uploadTiktokImageForShopeeSync(string $accessToken, string $sourceImageUrl, array &$uploadCache): array
    {
        $sourceImageUrl = trim($sourceImageUrl);
        if ($sourceImageUrl === '') {
            return [
                'ok' => false,
                'message' => 'Gambar Shopee kosong.',
            ];
        }

        $cacheKey = sha1($sourceImageUrl);
        if (array_key_exists($cacheKey, $uploadCache)) {
            return $uploadCache[$cacheKey];
        }

        $uploadCache[$cacheKey] = $this->uploadTiktokProductImage((object) [], $accessToken, $sourceImageUrl, 'MAIN_IMAGE');

        return $uploadCache[$cacheKey];
    }

    private function buildTiktokImagePartialEditSkuRows(array $productDetail, array $imageUpdatesBySkuId, array $excludedSkuIds = []): array
    {
        $rows = [];
        $foundSkuIds = [];
        $productId = trim((string) ($productDetail['id'] ?? $productDetail['product_id'] ?? ''));
        $excludedKeys = collect($excludedSkuIds)
            ->map(fn (mixed $value): string => $this->normalizeSkuMatchValue($value))
            ->filter()
            ->flip()
            ->all();

        foreach ($this->normalizeTiktokSkuList($productDetail) as $sku) {
            if (! is_array($sku)) {
                continue;
            }

            $skuId = trim((string) ($sku['id'] ?? $sku['sku_id'] ?? ''));
            if ($skuId === '') {
                continue;
            }

            if (isset($excludedKeys[$this->normalizeSkuMatchValue($skuId)])) {
                continue;
            }

            $row = $this->buildTiktokPartialEditSkuKeepRow($sku, null, $productId);
            $salesAttributes = data_get($sku, 'sales_attributes', data_get($sku, 'sale_attributes', []));
            if (is_array($salesAttributes) && $salesAttributes !== []) {
                if (isset($imageUpdatesBySkuId[$skuId])) {
                    $uploadedUri = (string) ($imageUpdatesBySkuId[$skuId]['uri'] ?? '');
                    $salesAttributes = $this->withTiktokSalesAttributeImageUri(
                        $salesAttributes,
                        $uploadedUri
                    );
                    if (trim($uploadedUri) !== '') {
                        $row['sku_img'] = ['uri' => trim($uploadedUri)];
                    }
                    $foundSkuIds[] = $skuId;
                }

                $row['sales_attributes'] = $this->sanitizeTiktokSalesAttributesForPartialEdit($salesAttributes);
            }

            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'found_sku_ids' => array_values(array_unique($foundSkuIds)),
        ];
    }

    private function withTiktokSalesAttributeImageUri(array $salesAttributes, string $uploadedUri): array
    {
        $uploadedUri = trim($uploadedUri);
        if ($uploadedUri === '') {
            return $salesAttributes;
        }

        $targetIndex = null;
        foreach ($salesAttributes as $index => $attribute) {
            if (is_array($attribute) && is_array(data_get($attribute, 'sku_img'))) {
                $targetIndex = $index;
                break;
            }
        }

        if ($targetIndex === null) {
            foreach ($salesAttributes as $index => $attribute) {
                if (is_array($attribute)) {
                    $targetIndex = $index;
                    break;
                }
            }
        }

        if ($targetIndex === null || ! is_array($salesAttributes[$targetIndex] ?? null)) {
            return $salesAttributes;
        }

        $salesAttributes[$targetIndex]['sku_img'] = ['uri' => $uploadedUri];

        return $salesAttributes;
    }

    private function sanitizeTiktokSalesAttributesForPartialEdit(array $salesAttributes): array
    {
        $rows = [];
        foreach ($salesAttributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $row = [];
            foreach (['id', 'name', 'value_id', 'value_name'] as $key) {
                $value = $attribute[$key] ?? null;
                if ($value !== null && trim((string) $value) !== '') {
                    $row[$key] = (string) $value;
                }
            }

            $skuImgUri = trim((string) data_get($attribute, 'sku_img.uri', ''));
            if ($skuImgUri !== '') {
                $row['sku_img'] = ['uri' => $skuImgUri];
            }

            $supplementaryImages = data_get($attribute, 'supplementary_sku_images', []);
            if (is_array($supplementaryImages) && $supplementaryImages !== [] && isset($row['sku_img'])) {
                $row['supplementary_sku_images'] = collect($supplementaryImages)
                    ->map(fn ($image): string => trim((string) data_get($image, 'uri', $image)))
                    ->filter()
                    ->map(fn (string $uri): array => ['uri' => $uri])
                    ->values()
                    ->all();
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function summarizeTiktokImageSyncRequest(string $path, array $payload): array
    {
        $skuIds = collect($payload['skus'] ?? [])
            ->map(fn ($sku): string => trim((string) data_get($sku, 'id', '')))
            ->filter()
            ->values()
            ->all();

        $imageSkuIds = collect($payload['skus'] ?? [])
            ->filter(function ($sku): bool {
                if (! is_array($sku)) {
                    return false;
                }

                foreach ((array) data_get($sku, 'sales_attributes', []) as $attribute) {
                    if (trim((string) data_get($attribute, 'sku_img.uri', '')) !== '') {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn ($sku): string => trim((string) data_get($sku, 'id', '')))
            ->filter()
            ->values()
            ->all();

        return [
            'method' => 'POST',
            'path' => $path,
            'save_mode' => $payload['save_mode'] ?? null,
            'main_image_count' => is_array($payload['main_images'] ?? null) ? count($payload['main_images']) : 0,
            'sku_count' => count($skuIds),
            'sku_ids' => array_slice($skuIds, 0, 50),
            'image_sku_ids' => array_slice($imageSkuIds, 0, 50),
        ];
    }

    private function summarizeTiktokImageSyncResponse(array $response): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : null;

        return array_filter([
            'code' => $response['code'] ?? null,
            'message' => $response['message'] ?? null,
            'request_id' => data_get($response, 'request_id'),
            'data' => $data !== null && strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') <= 5000
                ? $data
                : null,
            '_http_status' => $response['_http_status'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function sameSyncedImageReference(mixed $left, mixed $right): bool
    {
        $left = trim((string) ($left ?? ''));
        $right = trim((string) ($right ?? ''));

        return $left !== '' && $right !== '' && $left === $right;
    }

    private function verifyTiktokSkuImageUpdates(array $config, string $accessToken, object $shop, string $productId, array $skuImageUpdates): array
    {
        $expected = [];
        foreach ($skuImageUpdates as $skuId => $update) {
            $skuId = trim((string) $skuId);
            $uri = trim((string) ($update['uri'] ?? ''));
            if ($skuId !== '' && $uri !== '') {
                $expected[$skuId] = $uri;
            }
        }

        if ($expected === []) {
            return ['ok' => [], 'failed' => []];
        }

        $lastError = null;
        $currentUris = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                usleep(800000);
            }

            try {
                $detail = $this->fetchTiktokProductDetail(
                    $config,
                    $accessToken,
                    $shop,
                    $productId,
                    $config['api_host'].'/product/202309/products/'
                );
                $productDetail = is_array($detail['product'] ?? null) ? $detail['product'] : $detail;
                $currentUris = $this->tiktokSkuImageUriMap($productDetail);
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                continue;
            }

            $allMatched = true;
            foreach ($expected as $skuId => $expectedUri) {
                if (($currentUris[$skuId] ?? '') !== $expectedUri) {
                    $allMatched = false;
                    break;
                }
            }

            if ($allMatched) {
                break;
            }
        }

        $ok = [];
        $failed = [];
        foreach ($expected as $skuId => $expectedUri) {
            $currentUri = $currentUris[$skuId] ?? '';
            if ($currentUri === $expectedUri) {
                $ok[$skuId] = true;
                continue;
            }

            $failed[$skuId] = [
                'message' => $lastError
                    ? 'Verifikasi gambar varian TikTok gagal: '.$lastError
                    : 'TikTok membalas Success, tetapi gambar varian belum berubah saat verifikasi. Biasanya ini terjadi karena gambar varian dibatasi/ditahan review oleh TikTok pada produk dengan banyak gambar atau varian.',
                'expected_uri' => $expectedUri,
                'current_uri' => $currentUri !== '' ? $currentUri : null,
            ];
        }

        return ['ok' => $ok, 'failed' => $failed];
    }

    private function tiktokSkuImageUriMap(array $productDetail): array
    {
        $uris = [];
        foreach ($this->normalizeTiktokSkuList($productDetail) as $sku) {
            if (! is_array($sku)) {
                continue;
            }

            $skuId = trim((string) ($sku['id'] ?? $sku['sku_id'] ?? ''));
            if ($skuId === '') {
                continue;
            }

            $uri = trim((string) data_get($sku, 'sku_img.uri', ''));
            $salesAttributes = data_get($sku, 'sales_attributes', data_get($sku, 'sale_attributes', []));
            if (is_array($salesAttributes)) {
                foreach ($salesAttributes as $attribute) {
                    $candidate = trim((string) data_get($attribute, 'sku_img.uri', ''));
                    if ($candidate !== '') {
                        $uri = $candidate;
                        break;
                    }
                }
            }

            $uris[$skuId] = $uri;
        }

        return $uris;
    }

    private function addTiktokImageSyncReason(array &$result, string $bucket, string $message, array $context = []): void
    {
        if (! isset($result[$bucket]) || ! is_array($result[$bucket])) {
            $result[$bucket] = [];
        }

        $message = trim($message) !== '' ? trim($message) : 'Tanpa pesan detail.';
        if (mb_strlen($message) > 300) {
            $message = mb_substr($message, 0, 300).'...';
        }

        if (! isset($result[$bucket][$message])) {
            $result[$bucket][$message] = [
                'message' => $message,
                'count' => 0,
                'samples' => [],
            ];
        }

        $result[$bucket][$message]['count']++;

        $sample = array_filter([
            'stock_master_id' => $context['stock_master_id'] ?? null,
            'product_id' => isset($context['product_id']) ? (string) $context['product_id'] : null,
            'sku_id' => isset($context['sku_id']) ? (string) $context['sku_id'] : null,
            'seller_sku' => $context['seller_sku'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');

        if ($sample !== [] && count($result[$bucket][$message]['samples']) < 5) {
            $result[$bucket][$message]['samples'][] = $sample;
        }
    }

    private function recordTiktokImageSyncAction(array $candidate, string $status, ?array $requestPayload = null, ?array $responsePayload = null, ?string $message = null): void
    {
        if (! Schema::hasTable('sku_variant_actions') || empty($candidate['stock_master_id'])) {
            return;
        }

        DB::table('sku_variant_actions')->updateOrInsert(
            [
                'stock_master_id' => (int) $candidate['stock_master_id'],
                'target_channel' => 'tiktok',
                'action_type' => 'sync_image_from_shopee',
            ],
            [
                'source_channel' => 'shopee',
                'payload' => json_encode([
                    'seller_sku' => $candidate['seller_sku'] ?? null,
                    'product_id' => isset($candidate['tiktok_product_id']) ? (string) $candidate['tiktok_product_id'] : null,
                    'sku_id' => isset($candidate['tiktok_sku_id']) ? (string) $candidate['tiktok_sku_id'] : null,
                    'source_image_url' => $candidate['shopee_variant_image_url'] ?? null,
                    'request' => $requestPayload,
                    'response' => $responsePayload,
                    'message' => $message,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $status,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function firstFilledImage(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function firstFilledText(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function exactMarketplaceSkuKey(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }

    private function sameExactMarketplaceSku(mixed $left, mixed $right): bool
    {
        $leftKey = $this->exactMarketplaceSkuKey($left);
        $rightKey = $this->exactMarketplaceSkuKey($right);

        return $leftKey !== '' && $rightKey !== '' && $leftKey === $rightKey;
    }

    private function imageVariantIssueType(bool $hasShopeeIdentity, bool $hasTiktokIdentity, string $shopeeImage, string $tiktokImage): string
    {
        if (! $hasShopeeIdentity || ! $hasTiktokIdentity) {
            return 'incomplete_mapping';
        }

        if ($shopeeImage === '' && $tiktokImage !== '') {
            return 'missing_shopee_image';
        }

        if ($tiktokImage === '' && $shopeeImage !== '') {
            return 'missing_tiktok_image';
        }

        return 'matched';
    }

    private function imageVariantIssueMessage(string $issueType): string
    {
        return match ($issueType) {
            'missing_shopee_image' => 'Gambar varian Shopee kosong, TikTok punya gambar.',
            'missing_tiktok_image' => 'Gambar varian TikTok kosong, Shopee punya gambar.',
            'image_url_mismatch' => 'Gambar varian Shopee dan TikTok berbeda.',
            'incomplete_mapping' => 'Mapping varian Shopee/TikTok belum lengkap.',
            default => 'Gambar varian sudah sama.',
        };
    }

    private function ensureProductVariantAnalysisTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS product_variant_analysis_confirmations (
                id BIGSERIAL PRIMARY KEY,
                issue_id TEXT NOT NULL,
                channel TEXT NULL,
                type TEXT NULL,
                product_name TEXT NULL,
                product_ids TEXT NULL,
                status TEXT NOT NULL DEFAULT 'not_anomaly',
                note TEXT NULL,
                issue_snapshot TEXT NULL,
                confirmed_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS product_variant_analysis_confirmations_issue_id_idx ON product_variant_analysis_confirmations (issue_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS product_variant_analysis_confirmations_status_idx ON product_variant_analysis_confirmations (status)");
    }

    private function confirmedProductVariantAnalysisIssueIds(): array
    {
        if (! Schema::hasTable('product_variant_analysis_confirmations')) {
            return [];
        }

        return DB::table('product_variant_analysis_confirmations')
            ->where('status', 'not_anomaly')
            ->pluck('issue_id')
            ->filter()
            ->mapWithKeys(fn ($issueId) => [(string) $issueId => true])
            ->all();
    }

    private function shopeeProductsForVariantAnalysis(): array
    {
        if (! Schema::hasTable('shopee_product')) {
            return [];
        }

        $products = DB::table('shopee_product')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('item_id', 'shop_id', 'name', 'status', 'stock', 'updated_at')
            ->get();

        $modelsByItem = Schema::hasTable('shopee_product_model')
            ? DB::table('shopee_product_model')
                ->select('item_id', 'model_id', 'name', 'stock', 'price', 'updated_at')
                ->get()
                ->groupBy(fn ($row) => trim((string) ($row->item_id ?? '')))
            : collect();

        return $products
            ->map(function ($product) use ($modelsByItem): array {
                $itemId = trim((string) ($product->item_id ?? ''));
                $variants = $modelsByItem
                    ->get($itemId, collect())
                    ->map(fn ($model): array => [
                        'id' => trim((string) ($model->model_id ?? '')),
                        'name' => trim((string) ($model->name ?? '')),
                        'seller_sku' => null,
                        'stock' => (int) ($model->stock ?? 0),
                        'price' => (int) ($model->price ?? 0),
                        'updated_at' => $model->updated_at ?? null,
                    ])
                    ->values()
                    ->all();

                return [
                    'channel' => 'shopee',
                    'id' => $itemId,
                    'shop_id' => trim((string) ($product->shop_id ?? '')),
                    'name' => trim((string) ($product->name ?? '')),
                    'status' => $product->status ?? null,
                    'stock' => (int) ($product->stock ?? 0),
                    'updated_at' => $product->updated_at ?? null,
                    'variants' => $variants,
                ];
            })
            ->values()
            ->all();
    }

    private function tiktokProductsForVariantAnalysis(): array
    {
        if (! Schema::hasTable('tiktok_products')) {
            return [];
        }

        $rows = DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('product_id', 'product_name', 'sku_id', 'sku_name', 'seller_sku', 'stock_qty', 'price', 'updated_at', 'product_status', 'audit_status')
            ->get();

        return $rows
            ->groupBy(fn ($row) => trim((string) ($row->product_id ?? '')))
            ->map(function ($group, string $productId): array {
                $first = $group->first();
                $variants = $group
                    ->map(fn ($sku): array => [
                        'id' => trim((string) ($sku->sku_id ?? '')),
                        'name' => trim((string) ($sku->sku_name ?? '')),
                        'seller_sku' => trim((string) ($sku->seller_sku ?? '')) ?: null,
                        'stock' => (int) ($sku->stock_qty ?? 0),
                        'price' => (int) ($sku->price ?? 0),
                        'updated_at' => $sku->updated_at ?? null,
                    ])
                    ->values()
                    ->all();

                return [
                    'channel' => 'tiktok',
                    'id' => $productId,
                    'shop_id' => null,
                    'name' => trim((string) ($first->product_name ?? '')),
                    'status' => trim((string) ($first->product_status ?? ($first->audit_status ?? ''))) ?: null,
                    'stock' => array_sum(array_map(fn (array $variant) => (int) ($variant['stock'] ?? 0), $variants)),
                    'updated_at' => $group->max('updated_at'),
                    'variants' => $variants,
                ];
            })
            ->values()
            ->all();
    }

    private function marketplaceProductVariantIssues(string $channel, array $products): array
    {
        return [
            ...$this->duplicateMarketplaceProductIssues($channel, $products),
            ...$this->duplicateMarketplaceVariantIssues($channel, $products),
            ...$this->variantNameAnomalyIssues($channel, $products),
        ];
    }

    private function duplicateMarketplaceProductIssues(string $channel, array $products): array
    {
        $groups = [];
        foreach ($products as $product) {
            $key = $this->normalizeSkuMatchValue($product['name'] ?? '');
            if ($key === '') {
                continue;
            }

            $groups[$key][] = $product;
        }

        $issues = [];
        foreach ($groups as $nameKey => $group) {
            if (count($group) < 2) {
                continue;
            }

            $variantSets = [];
            $sellerSkuSets = [];
            foreach ($group as $product) {
                $variantSets[] = $this->analysisVariantKeys($product['variants'] ?? [], 'name');
                $sellerSkuSets[] = $this->analysisVariantKeys($product['variants'] ?? [], 'seller_sku');
            }

            $variantOverlap = $this->valuesRepeatedAcrossSets($variantSets);
            $sellerSkuOverlap = $this->valuesRepeatedAcrossSets($sellerSkuSets);
            $severity = count($variantOverlap) > 0 || count($sellerSkuOverlap) > 0 ? 'high' : 'warning';

            $issues[] = [
                'id' => $this->analysisIssueId($channel, 'duplicate_product', [$nameKey, array_column($group, 'id')]),
                'channel' => $channel,
                'type' => 'duplicate_product',
                'type_label' => 'Produk double',
                'severity' => $severity,
                'title' => 'Nama produk sama di beberapa '.($channel === 'shopee' ? 'item Shopee' : 'produk TikTok'),
                'description' => count($variantOverlap) > 0 || count($sellerSkuOverlap) > 0
                    ? 'Nama produk sama dan sebagian varian/kode variasi juga berulang. Kemungkinan besar ini produk double.'
                    : 'Nama produk sama, tetapi varian belum terlihat berulang. Tetap perlu dicek karena bisa membuat mapping bercabang.',
                'action_plan' => $this->analysisActionPlan('duplicate_product', $channel, [
                    'has_variant_overlap' => count($variantOverlap) > 0,
                    'has_seller_sku_overlap' => count($sellerSkuOverlap) > 0,
                ]),
                'product_name' => $group[0]['name'] ?? '',
                'product_ids' => array_values(array_map(fn (array $product) => $product['id'], $group)),
                'products' => array_map(fn (array $product): array => $this->analysisProductSummary($product), $group),
                'variants' => [],
                'evidence' => [
                    'product_count' => count($group),
                    'overlap_variant_count' => count($variantOverlap),
                    'overlap_seller_sku_count' => count($sellerSkuOverlap),
                    'overlap_variants' => array_slice($variantOverlap, 0, 12),
                    'overlap_seller_skus' => array_slice($sellerSkuOverlap, 0, 12),
                ],
            ];
        }

        return $issues;
    }

    private function duplicateMarketplaceVariantIssues(string $channel, array $products): array
    {
        $issues = [];

        foreach ($products as $product) {
            $variantGroups = [];
            $sellerSkuGroups = [];

            foreach (($product['variants'] ?? []) as $variant) {
                $variantKey = $this->normalizeSkuMatchValue($variant['name'] ?? '');
                if ($variantKey !== '' && $variantKey !== 'default') {
                    $variantGroups[$variantKey][] = $variant;
                }

                $sellerSkuKey = $this->normalizeSkuMatchValue($variant['seller_sku'] ?? '');
                if ($sellerSkuKey !== '') {
                    $sellerSkuGroups[$sellerSkuKey][] = $variant;
                }
            }

            foreach ($variantGroups as $variantKey => $variants) {
                if (count($variants) < 2) {
                    continue;
                }

                $issues[] = [
                    'id' => $this->analysisIssueId($channel, 'duplicate_variant', [$product['id'] ?? '', $variantKey]),
                    'channel' => $channel,
                    'type' => 'duplicate_variant',
                    'type_label' => 'Varian double',
                    'severity' => 'warning',
                    'title' => 'Nama varian berulang dalam satu produk',
                    'description' => 'Ada lebih dari satu varian dengan nama yang sama setelah dinormalisasi. Ini rawan salah sinkron stok.',
                    'action_plan' => $this->analysisActionPlan('duplicate_variant', $channel),
                    'product_name' => $product['name'] ?? '',
                    'product_ids' => [$product['id'] ?? ''],
                    'products' => [$this->analysisProductSummary($product)],
                    'variants' => array_map(fn (array $variant): array => $this->analysisVariantSummary($variant), $variants),
                    'evidence' => [
                        'variant_name_key' => $variantKey,
                        'duplicate_count' => count($variants),
                    ],
                ];
            }

            if ($channel !== 'tiktok') {
                continue;
            }

            foreach ($sellerSkuGroups as $sellerSkuKey => $variants) {
                if (count($variants) < 2) {
                    continue;
                }

                $issues[] = [
                    'id' => $this->analysisIssueId($channel, 'duplicate_seller_sku', [$product['id'] ?? '', $sellerSkuKey]),
                    'channel' => $channel,
                    'type' => 'duplicate_seller_sku',
                    'type_label' => 'Seller SKU double',
                    'severity' => 'high',
                    'title' => 'Seller SKU sama dipakai beberapa varian TikTok',
                    'description' => 'Ini bukan berarti nama variannya sama. Artinya kolom Seller SKU/kode penjual pada beberapa varian aktif berisi nilai yang sama, sehingga pencocokan varian bisa tidak pasti.',
                    'action_plan' => $this->analysisActionPlan('duplicate_seller_sku', $channel, [
                        'suggestions' => $this->analysisSellerSkuSuggestions($product, $variants),
                    ]),
                    'product_name' => $product['name'] ?? '',
                    'product_ids' => [$product['id'] ?? ''],
                    'products' => [$this->analysisProductSummary($product)],
                    'variants' => array_map(fn (array $variant): array => $this->analysisVariantSummary($variant), $variants),
                    'evidence' => [
                        'seller_sku_key' => $sellerSkuKey,
                        'duplicate_count' => count($variants),
                    ],
                ];
            }
        }

        return $issues;
    }

    private function variantNameAnomalyIssues(string $channel, array $products): array
    {
        $issues = [];
        $ignoredTokens = ['color', 'colour', 'default', 'kode', 'model', 'motif', 'no', 'nomor', 'seri', 'sku', 'variant', 'varian', 'warna'];

        foreach ($products as $product) {
            $variants = array_values(array_filter(
                $product['variants'] ?? [],
                fn (array $variant) => $this->normalizeSkuMatchValue($variant['name'] ?? '') !== ''
                    && $this->normalizeSkuMatchValue($variant['name'] ?? '') !== 'default'
            ));

            $variantCount = count($variants);
            if ($variantCount < 4) {
                continue;
            }

            $variantTokens = [];
            $tokenCounts = [];

            foreach ($variants as $index => $variant) {
                $tokens = preg_split('/\s+/', $this->normalizeSkuMatchValue($variant['name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $tokens = array_values(array_unique(array_filter($tokens, function (string $token) use ($ignoredTokens): bool {
                    return strlen($token) >= 3
                        && ! ctype_digit($token)
                        && ! in_array($token, $ignoredTokens, true);
                })));
                $variantTokens[$index] = $tokens;

                foreach ($tokens as $token) {
                    $tokenCounts[$token] = ($tokenCounts[$token] ?? 0) + 1;
                }
            }

            arsort($tokenCounts);
            $dominantToken = null;
            $dominantCount = 0;
            foreach ($tokenCounts as $token => $count) {
                if ($count >= 3 && ($count / max(1, $variantCount)) >= 0.65) {
                    $dominantToken = (string) $token;
                    $dominantCount = (int) $count;
                    break;
                }
            }

            if ($dominantToken === null) {
                continue;
            }

            $outliers = [];
            $nearMissTokens = [];
            foreach ($variants as $index => $variant) {
                if (in_array($dominantToken, $variantTokens[$index] ?? [], true)) {
                    continue;
                }

                $outliers[] = $variant;
                foreach (($variantTokens[$index] ?? []) as $token) {
                    if (abs(strlen($token) - strlen($dominantToken)) <= 2 && levenshtein($token, $dominantToken) <= 2) {
                        $nearMissTokens[] = $token;
                    }
                }
            }

            $maxOutliers = max(1, min(3, (int) floor($variantCount * 0.35)));
            if ($outliers === [] || count($outliers) > $maxOutliers) {
                continue;
            }

            $issues[] = [
                'id' => $this->analysisIssueId($channel, 'variant_anomaly', [$product['id'] ?? '', $dominantToken, array_column($outliers, 'id')]),
                'channel' => $channel,
                'type' => 'variant_anomaly',
                'type_label' => 'Anomali varian',
                'severity' => count($outliers) <= 2 ? 'warning' : 'info',
                'title' => 'Nama varian keluar dari pola mayoritas',
                'description' => 'Mayoritas varian memakai pola "'.$dominantToken.'", tetapi ada varian yang tidak mengikuti pola itu. Cek apakah ini typo, salah upload, atau memang varian berbeda.',
                'action_plan' => $this->analysisActionPlan('variant_anomaly', $channel, [
                    'dominant_token' => $dominantToken,
                    'near_miss_tokens' => array_values(array_unique($nearMissTokens)),
                ]),
                'product_name' => $product['name'] ?? '',
                'product_ids' => [$product['id'] ?? ''],
                'products' => [$this->analysisProductSummary($product)],
                'variants' => array_map(fn (array $variant): array => $this->analysisVariantSummary($variant), $outliers),
                'evidence' => [
                    'dominant_token' => $dominantToken,
                    'dominant_count' => $dominantCount,
                    'variant_count' => $variantCount,
                    'near_miss_tokens' => array_values(array_unique($nearMissTokens)),
                ],
            ];
        }

        return $issues;
    }

    private function analysisVariantKeys(array $variants, string $field): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $variant) => $this->normalizeSkuMatchValue($variant[$field] ?? ''),
            $variants
        ), fn (string $key) => $key !== '' && $key !== 'default')));
    }

    private function valuesRepeatedAcrossSets(array $sets): array
    {
        $counts = [];
        foreach ($sets as $set) {
            foreach (array_unique($set) as $value) {
                if ($value === '') {
                    continue;
                }

                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        return array_values(array_keys(array_filter($counts, fn (int $count) => $count > 1)));
    }

    private function analysisProductSummary(array $product): array
    {
        $variants = $product['variants'] ?? [];

        return [
            'id' => $product['id'] ?? '',
            'name' => $product['name'] ?? '',
            'status' => $product['status'] ?? null,
            'shop_id' => $product['shop_id'] ?? null,
            'variant_count' => count($variants),
            'stock_total' => array_sum(array_map(fn (array $variant) => (int) ($variant['stock'] ?? 0), $variants)),
            'updated_at' => $product['updated_at'] ?? null,
        ];
    }

    private function analysisVariantSummary(array $variant): array
    {
        return [
            'id' => $variant['id'] ?? '',
            'name' => $variant['name'] ?? '',
            'seller_sku' => $variant['seller_sku'] ?? null,
            'stock' => (int) ($variant['stock'] ?? 0),
            'price' => (int) ($variant['price'] ?? 0),
            'updated_at' => $variant['updated_at'] ?? null,
        ];
    }

    private function analysisActionPlan(string $type, string $channel, array $context = []): array
    {
        $marketplace = $channel === 'shopee' ? 'Shopee' : 'TikTok';

        if ($type === 'duplicate_product') {
            return [
                'title' => 'Solusi produk double',
                'summary' => 'Pilih satu produk utama, lalu nonaktifkan atau rapikan produk duplikat.',
                'steps' => [
                    'Buka semua produk terkait di '.$marketplace.' dan tentukan produk utama yang akan dipertahankan.',
                    'Bandingkan varian, stok, harga, foto, dan SKU mapping. Pastikan stok yang benar ada di produk utama.',
                    'Jika produk lain benar-benar duplikat, nonaktifkan/hapus produk duplikat di '.$marketplace.'.',
                    'Jika keduanya memang produk berbeda, ubah nama produk atau pola varian supaya tidak identik.',
                    'Jalankan Sync '.$marketplace.', lalu Refresh Analisa sampai temuan hilang.',
                ],
                'note' => ($context['has_variant_overlap'] ?? false) || ($context['has_seller_sku_overlap'] ?? false)
                    ? 'Prioritas tinggi karena ada overlap varian/kode. Cek mapping sebelum menghapus produk.'
                    : 'Nama produk sama belum tentu salah, tapi tetap perlu dibuat jelas agar mapping tidak bercabang.',
            ];
        }

        if ($type === 'duplicate_variant') {
            return [
                'title' => 'Solusi varian double',
                'summary' => 'Pastikan setiap nama varian dalam satu produk unik dan mewakili stok yang benar.',
                'steps' => [
                    'Buka produk tersebut di '.$marketplace.' dan cek varian yang namanya sama.',
                    'Jika salah satu hanya duplikat, pindahkan stok bila perlu lalu hapus/nonaktifkan varian duplikat.',
                    'Jika sebenarnya varian berbeda, ubah nama varian agar spesifik, misalnya tambah warna, ukuran, atau tipe.',
                    'Cek lagi SKU mapping/stock master supaya hanya menunjuk ke varian yang benar.',
                    'Jalankan Sync '.$marketplace.', lalu Refresh Analisa.',
                ],
            ];
        }

        if ($type === 'duplicate_seller_sku') {
            return [
                'title' => 'Solusi Seller SKU double',
                'summary' => 'Isi Seller SKU tiap varian dengan kode unik, jangan pakai kode umum seperti 1 untuk banyak varian.',
                'steps' => [
                    'Buka produk di TikTok Seller Center, masuk ke daftar variasi/SKU.',
                    'Ubah kolom Seller SKU/kode penjual pada setiap varian mengikuti daftar SKU disarankan di bawah.',
                    'Pola SKU disarankan mengikuti penulisan SKU valid yang sudah ada di database, lalu disesuaikan dengan nama varian.',
                    'Simpan perubahan di TikTok, lalu jalankan Sync TikTok di aplikasi ini.',
                    'Refresh Analisa dan pastikan temuan Seller SKU double hilang.',
                ],
                'suggestions' => $context['suggestions'] ?? [],
                'examples' => $context['suggestions'] ?? [],
                'note' => 'Kalau Seller SKU tidak dipakai untuk mapping, risikonya lebih kecil. Tapi untuk sinkron stok yang aman, kode tetap sebaiknya unik.',
            ];
        }

        if ($type === 'variant_anomaly') {
            $dominantToken = (string) ($context['dominant_token'] ?? '');
            $nearMissTokens = $context['near_miss_tokens'] ?? [];
            $patternText = $dominantToken !== '' ? 'pola mayoritas "'.$dominantToken.'"' : 'pola mayoritas varian';

            return [
                'title' => 'Solusi anomali varian',
                'summary' => 'Cek varian yang keluar dari pola: typo, salah upload, atau memang perlu dipisah.',
                'steps' => [
                    'Bandingkan varian yang ditandai dengan '.$patternText.'.',
                    'Jika ini typo penamaan, perbaiki nama varian di '.$marketplace.' agar konsisten.',
                    'Jika ini salah upload varian, hapus/nonaktifkan varian tersebut atau pindahkan ke produk yang benar.',
                    'Jika varian memang sah tetapi beda tipe, pertimbangkan memisahkan produk atau memperjelas nama produk.',
                    'Jalankan Sync '.$marketplace.', lalu Refresh Analisa.',
                ],
                'note' => count($nearMissTokens) > 0
                    ? 'Ada indikasi typo mirip: '.implode(', ', array_slice($nearMissTokens, 0, 5)).'.'
                    : 'Tidak semua anomali berarti salah. Ini penanda untuk dicek manual.',
            ];
        }

        return [
            'title' => 'Solusi',
            'summary' => 'Cek data marketplace, rapikan sumber masalah, lalu sinkronkan ulang.',
            'steps' => [
                'Buka produk terkait di marketplace.',
                'Perbaiki produk, varian, atau kode yang ditandai.',
                'Jalankan sync dan refresh analisa.',
            ],
        ];
    }

    private function analysisSellerSkuSuggestions(array $product, array $variants): array
    {
        $referenceRows = $this->analysisSellerSkuReferenceRows();
        $prefix = $this->analysisSellerSkuPrefix($product, $variants, $referenceRows);
        $used = [];

        return array_values(array_filter(array_map(function (array $variant) use ($prefix, $referenceRows, &$used): ?array {
            $variantName = trim((string) ($variant['name'] ?? ''));
            if ($variantName === '') {
                return null;
            }

            $variantCode = $this->analysisVariantSkuCodePart($variantName, $prefix, $referenceRows);
            if ($variantCode === '') {
                return null;
            }

            $suggestedSku = $prefix.$variantCode;
            $baseSku = $suggestedSku;
            $counter = 2;
            while (isset($used[$this->normalizeSkuMatchValue($suggestedSku)])) {
                $suggestedSku = $baseSku.'-'.$counter;
                $counter++;
            }
            $used[$this->normalizeSkuMatchValue($suggestedSku)] = true;

            return [
                'variant_name' => $variantName,
                'current_sku' => trim((string) ($variant['seller_sku'] ?? '')),
                'suggested_sku' => $suggestedSku,
            ];
        }, $variants)));
    }

    private function analysisSellerSkuReferenceRows()
    {
        if (! Schema::hasTable('tiktok_products')) {
            return collect();
        }

        return DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->whereNotNull('seller_sku')
            ->where('seller_sku', '<>', '')
            ->select('product_id', 'product_name', 'sku_name', 'seller_sku')
            ->get()
            ->filter(fn ($row) => ! $this->analysisSellerSkuIsGeneric($row->seller_sku ?? ''))
            ->values();
    }

    private function analysisSellerSkuPrefix(array $product, array $variants, $referenceRows): string
    {
        $currentSkus = array_values(array_unique(array_filter(array_map(
            fn (array $variant) => trim((string) ($variant['seller_sku'] ?? '')),
            $variants
        ), fn (string $sku) => ! $this->analysisSellerSkuIsGeneric($sku))));

        $currentPrefix = $this->analysisBestPrefixFromSkus($currentSkus);
        if ($currentPrefix !== '') {
            return $currentPrefix;
        }

        $productNameTokens = $this->analysisSkuNameTokens($product['name'] ?? '');
        $variantKeys = array_flip(array_map(
            fn (array $variant) => $this->normalizeSkuMatchValue($this->analysisVariantBaseName($variant['name'] ?? '')),
            $variants
        ));

        $prefixScores = [];
        foreach ($referenceRows as $row) {
            $prefix = $this->analysisPrefixFromSellerSku((string) ($row->seller_sku ?? ''));
            if ($prefix === '') {
                continue;
            }

            $rowNameTokens = $this->analysisSkuNameTokens($row->product_name ?? '');
            $sharedProductTokens = count(array_intersect($productNameTokens, $rowNameTokens));
            $rowVariantKey = $this->normalizeSkuMatchValue($this->analysisVariantBaseName($row->sku_name ?? ''));
            $variantScore = $rowVariantKey !== '' && isset($variantKeys[$rowVariantKey]) ? 5 : 0;

            $score = $sharedProductTokens + $variantScore;
            if ((string) ($row->product_id ?? '') === (string) ($product['id'] ?? '')) {
                $score += 20;
            }

            if ($score <= 0) {
                continue;
            }

            $prefixScores[$prefix] = ($prefixScores[$prefix] ?? 0) + $score;
        }

        if ($prefixScores !== []) {
            arsort($prefixScores);

            return (string) array_key_first($prefixScores);
        }

        return $this->analysisFallbackProductSkuPrefix($product['name'] ?? '', $product['id'] ?? '');
    }

    private function analysisBestPrefixFromSkus(array $skus): string
    {
        $prefixCounts = [];
        foreach ($skus as $sku) {
            $prefix = $this->analysisPrefixFromSellerSku($sku);
            if ($prefix !== '') {
                $prefixCounts[$prefix] = ($prefixCounts[$prefix] ?? 0) + 1;
            }
        }

        if ($prefixCounts === []) {
            return '';
        }

        arsort($prefixCounts);

        return (string) array_key_first($prefixCounts);
    }

    private function analysisPrefixFromSellerSku(string $sku): string
    {
        $sku = trim($sku);
        if ($this->analysisSellerSkuIsGeneric($sku)) {
            return '';
        }

        $parts = array_values(array_filter(explode('-', $sku), fn (string $part) => trim($part) !== ''));
        if (count($parts) >= 3) {
            return strtoupper($parts[0].'-'.$parts[1].'-');
        }

        if (count($parts) === 2) {
            return strtoupper($parts[0].'-');
        }

        return '';
    }

    private function analysisVariantSkuCodePart(string $variantName, string $prefix, $referenceRows): string
    {
        $variantBaseName = $this->analysisVariantBaseName($variantName);
        $variantKey = $this->normalizeSkuMatchValue($variantBaseName);
        $fallback = $this->analysisSkuCodePart($variantBaseName);
        $bestSuffix = '';
        $bestScore = -1;

        foreach ($referenceRows as $row) {
            $rowVariantKey = $this->normalizeSkuMatchValue($this->analysisVariantBaseName($row->sku_name ?? ''));
            if ($rowVariantKey === '' || $rowVariantKey !== $variantKey) {
                continue;
            }

            $sku = trim((string) ($row->seller_sku ?? ''));
            $rowPrefix = $this->analysisPrefixFromSellerSku($sku);
            $suffix = $this->analysisSellerSkuSuffix($sku, $prefix !== '' ? $prefix : $rowPrefix);
            if ($suffix === '') {
                $suffix = $this->analysisSellerSkuSuffix($sku, $rowPrefix);
            }
            if ($suffix === '') {
                continue;
            }
            if (strlen(str_replace('-', '', $suffix)) > max(12, strlen(str_replace('-', '', $fallback)) + 8)) {
                continue;
            }

            $score = 10;
            if ($prefix !== '' && str_starts_with(strtoupper($sku), strtoupper($prefix))) {
                $score += 10;
            }
            $score -= max(0, strlen(str_replace('-', '', $suffix)) - strlen(str_replace('-', '', $fallback)));

            if ($score > $bestScore) {
                $bestSuffix = $suffix;
                $bestScore = $score;
            }
        }

        return $bestSuffix !== '' ? $bestSuffix : $fallback;
    }

    private function analysisSellerSkuSuffix(string $sku, string $prefix): string
    {
        $sku = strtoupper(trim($sku));
        $prefix = strtoupper(trim($prefix));
        if ($sku === '' || $prefix === '' || ! str_starts_with($sku, $prefix)) {
            return '';
        }

        return trim(substr($sku, strlen($prefix)), '-');
    }

    private function analysisVariantBaseName(mixed $value): string
    {
        $name = trim((string) $value);
        $name = preg_split('/[\/|]/', $name, 2)[0] ?? $name;
        $name = preg_replace('/\b(polos|premium|pouch)\b/i', ' ', $name) ?: $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?: $name);
    }

    private function analysisSkuNameTokens(mixed $value): array
    {
        $ignored = ['agni', 'by', 'daily', 'empat', 'hijab', 'instan', 'logo', 'metal', 'motif', 'packing', 'polos', 'pouch', 'premium', 'segi', 'superfashion'];
        $tokens = preg_split('/\s+/', $this->normalizeSkuMatchValue($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($tokens, fn (string $token) => strlen($token) >= 3 && ! in_array($token, $ignored, true))));
    }

    private function analysisFallbackProductSkuPrefix(mixed $productName, mixed $productId): string
    {
        $tokens = $this->analysisSkuNameTokens($productName);
        $knownPrefixes = [
            'azara' => 'AZR-HJP-',
            'zaryta' => 'ZARYTA-',
            'zannoo' => 'ZANOO-',
            'zanoo' => 'ZANOO-',
        ];

        foreach ($tokens as $token) {
            if (isset($knownPrefixes[$token])) {
                return $knownPrefixes[$token];
            }
        }

        if ($tokens !== []) {
            $first = strtoupper($tokens[0]);
            if (strlen($first) > 8) {
                $first = substr($first, 0, 8);
            }

            return $first.'-';
        }

        $productCode = preg_replace('/\D+/', '', (string) $productId) ?: 'PROD';

        return 'TT-'.substr($productCode, -6).'-';
    }

    private function analysisSellerSkuIsGeneric(mixed $value): bool
    {
        $sku = strtoupper(trim((string) $value));
        if ($sku === '') {
            return true;
        }

        $normalized = $this->normalizeSkuMatchValue($sku);
        if (in_array($normalized, ['-', '0', '00', '1', '01', '2', '02', 'SKU', 'DEFAULT', 'NONE', 'NULL'], true)) {
            return true;
        }

        return ctype_digit($normalized) && strlen($normalized) <= 3;
    }

    private function analysisSkuCodePart(string $value): string
    {
        $normalized = strtoupper($this->normalizeSkuMatchValue($value));
        $normalized = preg_replace('/[^A-Z0-9]+/', '-', $normalized) ?: '';
        $normalized = trim($normalized, '-');

        return substr($normalized, 0, 40);
    }

    private function analysisIssueId(string $channel, string $type, array $parts): string
    {
        return $channel.'-'.$type.'-'.substr(sha1(json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: implode('|', $parts)), 0, 12);
    }

    public function shopeeItems(Request $request): JsonResponse
    {
        $this->ensureShopeeProductTables();

        $syncResult = null;

        if ($request->boolean('sync')) {
            $itemId = (int) $request->query('item_id', 0);
            $syncResult = $itemId > 0
                ? $this->syncShopeeProductToDatabase($itemId)
                : $this->syncShopeeProductsToDatabase();
        }

        return $this->shopeeItemsResponse($syncResult);
    }

    private function shopeeItemsResponse(?array $syncResult = null): JsonResponse
    {
        $shopNames = $this->shopeeShopNames();
        $products = DB::table('shopee_product')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select(
                'item_id',
                'shop_id',
                'name',
                'stock',
                'price_min',
                'price_max',
                'price_before_discount',
                'sold',
                'liked_count',
                'rating',
                'status',
                'create_time',
                'update_time',
                'updated_at'
            )
            ->orderBy('name')
            ->get();

        $models = DB::table('shopee_product_model')
            ->select('item_id', 'model_id', 'name', 'model_sku', 'price', 'original_price', 'stock', 'updated_at')
            ->orderBy('name')
            ->get()
            ->groupBy('item_id');

        $productImages = DB::table('shopee_product_image')
            ->select('item_id', 'image_url', 'created_at', 'id')
            ->whereNotNull('image_url')
            ->whereNull('model_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->first()->image_url);

        $modelImages = DB::table('shopee_product_image')
            ->select('item_id', 'model_id', 'image_url', 'created_at', 'id')
            ->whereNotNull('image_url')
            ->whereNotNull('model_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('item_id')
            ->map(fn ($rows) => $rows->groupBy('model_id')->map(fn ($modelRows) => $modelRows->first()->image_url));

        $lastSyncAt = DB::table('shopee_sync_logs')->latest('synced_at')->value('synced_at')
            ?: DB::table('shopee_product')->max('updated_at');

        return response()->json([
            'status' => $syncResult['status'] ?? 'ok',
            'message' => $syncResult['message'] ?? ($lastSyncAt ? 'Data Shopee dari cache database.' : 'Belum ada cache produk Shopee. Klik Sinkronkan Produk.'),
            'count' => $products->count(),
            'last_sync_at' => $lastSyncAt,
            'sync' => [
                'status' => $syncResult['status'] ?? 'cached',
                'message' => $syncResult['message'] ?? ($lastSyncAt ? 'Terakhir sinkron: '.$lastSyncAt : 'Belum pernah sinkron.'),
                'last_sync_at' => $lastSyncAt,
                ...($syncResult ?? []),
            ],
            'items' => $products->map(fn ($item, int $index) => [
                'no' => $index + 1,
                'item_id' => (string) $item->item_id,
                'shop_id' => $item->shop_id ? (string) $item->shop_id : null,
                'shop_name' => $shopNames[(string) $item->shop_id] ?? 'Shopee',
                'image_url' => $productImages[$item->item_id] ?? null,
                'nama' => $item->name,
                'sku' => (string) $item->item_id,
                'stok' => (int) ($item->stock ?? 0),
                'price_min' => (int) ($item->price_min ?? 0),
                'price_max' => (int) ($item->price_max ?? 0),
                'price_before_discount' => (int) ($item->price_before_discount ?? 0),
                'harga' => $this->formatRupiah((int) ($item->price_min ?? $item->price_max ?? 0)),
                'sales' => (int) ($item->sold ?? 0),
                'likes' => (int) ($item->liked_count ?? 0),
                'rating' => (float) ($item->rating ?? 0),
                'status' => $item->status,
                'is_live' => $this->isLiveShopeeStatus($item->status),
                'created_at' => $item->create_time,
                'updated_at' => $item->update_time ?: $item->updated_at,
                'models' => ($models[$item->item_id] ?? collect())->map(fn ($model) => [
                    'model_id' => (string) $model->model_id,
                    'name' => $model->name,
                    'model_sku' => $model->model_sku ?? null,
                    'kode_variasi' => $this->shopeeModelVariationCode((string) $item->item_id, $model),
                    'price' => (int) ($model->price ?? 0),
                    'original_price' => (int) ($model->original_price ?? $model->price ?? 0),
                    'stock' => (int) ($model->stock ?? 0),
                    'image_url' => $modelImages[$item->item_id][$model->model_id] ?? null,
                    'fallback_image_url' => $productImages[$item->item_id] ?? null,
                    'updated_at' => $model->updated_at,
                ])->values(),
            ])->values(),
        ]);
    }

    private function syncShopeeProductsToDatabase(): array
    {
        $this->ensureShopeeAuthColumns();
        $this->normalizeActiveMarketplaceTokens();

        if (! Schema::hasTable('shopee_tokens')) {
            return [
                'status' => 'error',
                'message' => 'Tabel token Shopee belum tersedia.',
                'accounts' => [],
            ];
        }

        $tokens = $this->activeShopeeTokensForSync();

        if ($tokens->isEmpty()) {
            return [
                'status' => 'error',
                'message' => 'Belum ada token Shopee aktif. Jalankan AUTH / REFRESH Shopee dari dashboard dulu.',
                'accounts' => [],
            ];
        }

        $accounts = [];
        $productCount = 0;
        $variantCount = 0;
        $inactiveCount = 0;
        $removedVariantCount = 0;
        $config = $this->shopeeConfig();

        foreach ($tokens as $token) {
            $shopId = (int) $token->shop_id;
            $accessToken = (string) $token->access_token;
            $accountName = $token->account_name ?: 'Shopee';

            try {
                $itemIds = $this->fetchShopeeItemIds($config, $shopId, $accessToken);
                $baseItems = [];

                foreach (array_chunk($itemIds, 50) as $chunk) {
                    $baseItems = array_merge($baseItems, $this->fetchShopeeBaseInfo($config, $shopId, $accessToken, $chunk));
                }

                foreach ($baseItems as $baseItem) {
                    $modelPayload = $this->fetchShopeeModelList($config, $shopId, $accessToken, (int) ($baseItem['item_id'] ?? 0));
                    $models = data_get($modelPayload, 'model', []);
                    $tierVariations = data_get($modelPayload, 'tier_variation', []);
                    $variantCount += max(1, count($models));
                    $removedVariantCount += $this->storeShopeeProductPayload($baseItem, $models, $tierVariations, $shopId);
                }

                $deactivatedForShop = $this->deactivateShopeeProductsMissingFromSync($shopId, $itemIds);
                $inactiveCount += $deactivatedForShop;
                $productCount += count($baseItems);
                $accounts[] = [
                    'status' => 'ok',
                    'account_key' => $token->account_key,
                    'account_name' => $accountName,
                    'shop_id' => (string) $shopId,
                    'products' => count($baseItems),
                    'deactivated_products' => $deactivatedForShop,
                ];
            } catch (\Throwable $exception) {
                $accounts[] = [
                    'status' => 'error',
                    'account_key' => $token->account_key,
                    'account_name' => $accountName,
                    'shop_id' => (string) $shopId,
                    'products' => 0,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $hasError = collect($accounts)->contains(fn ($account) => ($account['status'] ?? '') === 'error');
        $message = $productCount.' produk Shopee dan '.$variantCount.' varian berhasil disinkronkan ke database.';
        if ($inactiveCount > 0) {
            $message .= ' '.$inactiveCount.' produk lama dinonaktifkan dari cache.';
        }
        if ($removedVariantCount > 0) {
            $message .= ' '.$removedVariantCount.' varian lama dihapus dari cache.';
        }

        if ($hasError && $productCount === 0) {
            $message = collect($accounts)->firstWhere('status', 'error')['message'] ?? 'Gagal mengambil data Shopee.';
        }

        if ($productCount > 0) {
            DB::table('shopee_sync_logs')->insert([
                'status' => $hasError ? 'partial' : 'ok',
                'message' => $message,
                'product_count' => $productCount,
                'variant_count' => $variantCount,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'status' => $hasError ? ($productCount ? 'partial' : 'error') : 'ok',
            'message' => $message,
            'products' => $productCount,
            'variants' => $variantCount,
            'deactivated_products' => $inactiveCount,
            'removed_variants' => $removedVariantCount,
            'accounts' => $accounts,
            'last_sync_at' => now()->toDateTimeString(),
        ];
    }

    private function deactivateShopeeProductsMissingFromSync(int $shopId, array $activeItemIds): int
    {
        if (! Schema::hasTable('shopee_product')) {
            return 0;
        }

        $activeItemIds = array_values(array_unique(array_filter(array_map(
            fn ($itemId) => (int) $itemId,
            $activeItemIds
        ), fn (int $itemId) => $itemId > 0)));

        $query = DB::table('shopee_product')
            ->where('shop_id', $shopId)
            ->whereRaw('COALESCE(is_active, true) = true');

        if ($activeItemIds !== []) {
            $query->whereNotIn('item_id', $activeItemIds);
        }

        return $query->update([
            'is_active' => DB::raw('false'),
            'status' => DB::raw("COALESCE(status, 'REMOVED')"),
            'updated_at' => now(),
        ]);
    }

    private function activeShopeeTokensForSync()
    {
        $tokens = DB::table('shopee_tokens')
            ->whereRaw('is_active = true')
            ->whereNotNull('shop_id')
            ->whereNotNull('access_token')
            ->orderBy('account_name')
            ->get();

        foreach ($tokens as $token) {
            if (! $this->shopeeAccessTokenNeedsRefresh($token)) {
                continue;
            }

            $account = $this->resolveAccount((string) ($token->account_key ?: 'shopee-agnishopbjm'), 'shopee');
            $this->refreshShopeeToken($account);
        }

        return DB::table('shopee_tokens')
            ->whereRaw('is_active = true')
            ->whereNotNull('shop_id')
            ->whereNotNull('access_token')
            ->orderBy('account_name')
            ->get()
            ->reject(fn ($token) => $this->shopeeAccessTokenIsExpired($token))
            ->values();
    }

    private function fetchShopeeItemIds(array $config, int $shopId, string $accessToken): array
    {
        $ids = [];
        $offset = 0;
        $pageSize = 100;
        $statuses = ['NORMAL', 'UNLIST'];

        foreach ($statuses as $status) {
            $offset = 0;

            do {
                $response = $this->shopeeSignedGet($config, '/api/v2/product/get_item_list', $shopId, $accessToken, [
                    'offset' => $offset,
                    'page_size' => $pageSize,
                    'item_status' => $status,
                ]);

                $items = data_get($response, 'response.item', []);
                foreach ($items as $item) {
                    if (! empty($item['item_id'])) {
                        $ids[(string) $item['item_id']] = (int) $item['item_id'];
                    }
                }

                $hasNextPage = (bool) data_get($response, 'response.has_next_page', false);
                $offset = (int) data_get($response, 'response.next_offset', $offset + $pageSize);
            } while ($hasNextPage);
        }

        return array_values($ids);
    }

    private function fetchShopeeBaseInfo(array $config, int $shopId, string $accessToken, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $response = $this->shopeeSignedGet($config, '/api/v2/product/get_item_base_info', $shopId, $accessToken, [
            'item_id_list' => implode(',', $itemIds),
            'need_tax_info' => 'false',
            'need_complaint_policy' => 'false',
        ]);

        return data_get($response, 'response.item_list', []);
    }

    private function fetchShopeeModelList(array $config, int $shopId, string $accessToken, int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $response = $this->shopeeSignedGet($config, '/api/v2/product/get_model_list', $shopId, $accessToken, [
            'item_id' => $itemId,
        ]);

        return data_get($response, 'response', []);
    }

    private function shopeeSignedGet(array $config, string $path, int $shopId, string $accessToken, array $params = []): array
    {
        $timestamp = time();
        $query = [
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->generateShopeeApiSign($config['partner_id'], $config['partner_key'], $path, $timestamp, $accessToken, $shopId),
            ...$params,
        ];

        $response = Http::timeout(45)->acceptJson()->get($config['host'].$path, $query);
        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException('Shopee tidak mengembalikan JSON valid untuk '.$path.'.');
        }

        if (($data['error'] ?? '') !== '') {
            throw new \RuntimeException(($data['message'] ?? $data['error']).' ['.$path.']');
        }

        return $data;
    }

    private function shopeeSignedPost(array $config, string $path, int $shopId, string $accessToken, array $payload): array
    {
        $timestamp = time();
        $query = [
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->generateShopeeApiSign($config['partner_id'], $config['partner_key'], $path, $timestamp, $accessToken, $shopId),
        ];

        $response = Http::timeout(45)
            ->acceptJson()
            ->post($config['host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), $payload);
        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException('Shopee tidak mengembalikan JSON valid untuk '.$path.'.');
        }

        return [
            ...$data,
            '_http_status' => $response->status(),
            '_request' => [
                'method' => 'POST',
                'path' => $path,
                'query' => [
                    ...$query,
                    'access_token' => $accessToken !== '' ? '[hidden]' : '',
                    'sign' => '[hidden]',
                ],
                'body' => $payload,
            ],
        ];
    }

    private function uploadShopeeProductImage(array $config, string $sourceImageUrl): array
    {
        $absolutePath = $this->resolveUploadImagePath($sourceImageUrl);
        if ($absolutePath === null) {
            return [
                'ok' => false,
                'message' => 'Gambar sumber belum bisa dibaca untuk diupload ke Shopee.',
                'source_image' => $sourceImageUrl,
            ];
        }

        $path = '/api/v2/media_space/upload_image';
        $timestamp = time();
        $query = [
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $this->generateShopeeSign($config['partner_id'], $config['partner_key'], $path, $timestamp),
        ];

        $response = Http::timeout(45)
            ->attach('image', file_get_contents($absolutePath), basename($absolutePath))
            ->post($config['host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), [
                'scene' => 'normal',
            ]);

        $payload = $response->json();
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'message' => 'Shopee tidak mengembalikan JSON valid saat upload gambar.',
                'http_status' => $response->status(),
                'response_body' => $response->body(),
                'source_image' => $sourceImageUrl,
            ];
        }

        if (($payload['error'] ?? '') !== '') {
            return [
                'ok' => false,
                'message' => $payload['message'] ?? 'Gagal upload gambar ke Shopee.',
                'response' => $payload,
                'source_image' => $sourceImageUrl,
            ];
        }

        $imageId = trim((string) (
            data_get($payload, 'response.image_info.image_id')
            ?: data_get($payload, 'response.image_info_list.0.image_info.image_id')
            ?: data_get($payload, 'response.image_info_list.0.image_id')
            ?: ''
        ));
        $imageUrl = trim((string) (
            data_get($payload, 'response.image_info.image_url_list.0.image_url')
            ?: data_get($payload, 'response.image_info_list.0.image_info.image_url_list.0.image_url')
            ?: ''
        ));

        if ($imageId === '') {
            return [
                'ok' => false,
                'message' => 'Upload gambar Shopee berhasil dipanggil, tetapi image_id tidak ditemukan.',
                'response' => $payload,
                'source_image' => $sourceImageUrl,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Gambar berhasil diupload ke Shopee.',
            'image_id' => $imageId,
            'image_url' => $imageUrl ?: null,
            'response' => $payload,
            'source_image' => $sourceImageUrl,
        ];
    }

    private function normalizeShopeeAddVariantRows(array $rows, string $itemId = ''): array
    {
        $variants = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $variantName = trim((string) ($row['variant_name'] ?? ''));
            if ($variantName === '') {
                continue;
            }

            $price = $this->normalizePositiveInt($row['price'] ?? 0);
            $stock = $this->normalizeNonNegativeInt($row['stock'] ?? 0);
            $sellerSku = trim((string) ($row['seller_sku'] ?? ''));
            if ($itemId !== '' && ! $this->shopeeSellerSkuLooksGenerated($sellerSku, $itemId) && ! $this->sellerSkuLooksInternal($sellerSku)) {
                $sellerSku = $this->buildShopeeTemplateSellerSku($itemId, $variantName);
            }

            if ($sellerSku === '') {
                $tiktokSkuId = trim((string) ($row['tiktok_sku_id'] ?? ''));
                $sellerSku = $tiktokSkuId !== '' ? 'TT-'.$tiktokSkuId : '';
            }

            $variants[] = [
                'stock_master_id' => isset($row['stock_master_id']) && is_numeric($row['stock_master_id'])
                    ? (int) $row['stock_master_id']
                    : null,
                'variant_name' => $variantName,
                'seller_sku' => $sellerSku,
                'price' => $price > 0 ? $price : 1,
                'stock' => $stock,
                'image_url' => trim((string) ($row['image_url'] ?? '')),
                'tiktok_product_id' => trim((string) ($row['tiktok_product_id'] ?? '')),
                'tiktok_sku_id' => trim((string) ($row['tiktok_sku_id'] ?? '')),
            ];
        }

        return $variants;
    }

    private function shopeeSellerSkuLooksGenerated(string $sellerSku, string $itemId): bool
    {
        $sellerSku = trim($sellerSku);
        if ($sellerSku === '') {
            return false;
        }

        return str_starts_with(strtoupper($sellerSku), 'INT-'.trim($itemId).'-');
    }

    private function sellerSkuLooksInternal(string $sellerSku): bool
    {
        return str_starts_with(strtoupper(trim($sellerSku)), 'INT-');
    }

    private function buildShopeeTemplateSellerSku(string $itemId, string $variantName): string
    {
        return 'INT-'.trim($itemId).'-'.$this->sanitizeSkuFragment($variantName);
    }

    private function shopeeModelVariationCode(string $itemId, object $model): string
    {
        $modelSku = trim((string) ($model->model_sku ?? ''));
        if (str_starts_with(strtoupper($modelSku), 'INT-')) {
            return $modelSku;
        }

        return $this->buildShopeeTemplateSellerSku($itemId, (string) ($model->name ?? 'VARIAN'));
    }

    private function tiktokSkuVariationCode(string $productId, object $sku): string
    {
        $sellerSku = trim((string) ($sku->seller_sku ?? ''));
        if (str_starts_with(strtoupper($sellerSku), 'INT-')) {
            return $sellerSku;
        }

        return 'INT-'.trim($productId).'-'.$this->sanitizeSkuFragment((string) ($sku->sku_name ?? 'VARIAN'));
    }

    private function buildShopeeTierOption(string $variantName, bool $useStandardiseTier, string $imageId = '', string $sourceImageUrl = ''): array
    {
        if ($useStandardiseTier) {
            return array_filter([
                'variation_option_id' => 0,
                'variation_option_name' => $variantName,
                'image_id' => $imageId !== '' ? $imageId : null,
                '_source_image_url' => $imageId === '' && $sourceImageUrl !== '' ? $sourceImageUrl : null,
            ], fn ($value) => $value !== null);
        }

        return array_filter([
            'option' => $variantName,
            'image' => $imageId !== '' ? ['image_id' => $imageId] : null,
            '_source_image_url' => $imageId === '' && $sourceImageUrl !== '' ? $sourceImageUrl : null,
        ], fn ($value) => $value !== null);
    }

    private function buildShopeeAddModel(array $variant, int|array $optionIndex, string $sellerStockLocationId = '', ?float $modelWeight = null): array
    {
        $sellerStock = [
            'stock' => $variant['stock'],
        ];
        if ($sellerStockLocationId !== '') {
            $sellerStock['location_id'] = $sellerStockLocationId;
        }

        $tierIndex = is_array($optionIndex)
            ? array_map('intval', $optionIndex)
            : [(int) $optionIndex];

        $model = [
            'tier_index' => $tierIndex,
            'original_price' => $variant['price'],
            'seller_stock' => [$sellerStock],
        ];

        if (($variant['seller_sku'] ?? '') !== '') {
            $model['model_sku'] = mb_substr((string) $variant['seller_sku'], 0, 100);
        }

        if ($modelWeight !== null && $modelWeight > 0) {
            $model['weight'] = $modelWeight;
        }

        return $model;
    }

    private function shopeeTierOptionList(array $tier, bool $useStandardiseTier): array
    {
        $options = $useStandardiseTier
            ? data_get($tier, 'variation_option_list', [])
            : data_get($tier, 'option_list', []);

        return is_array($options) ? $options : [];
    }

    private function shopeeTierOptionName(mixed $option, bool $useStandardiseTier): string
    {
        return trim((string) ($useStandardiseTier
            ? data_get($option, 'variation_option_name')
            : data_get($option, 'option')));
    }

    private function shopeeTierName(array $tier, bool $useStandardiseTier): string
    {
        return trim((string) ($useStandardiseTier
            ? (data_get($tier, 'variation_name') ?: data_get($tier, 'name'))
            : data_get($tier, 'name')));
    }

    private function resolveShopeeAddVariantTierIndex(array $activeTierVariation, bool $useStandardiseTier, array $requestedVariants): int
    {
        $requestedKeys = [];
        foreach ($requestedVariants as $variant) {
            $key = $this->normalizeSkuMatchValue($variant['variant_name'] ?? '');
            if ($key !== '') {
                $requestedKeys[$key] = true;
            }
        }

        $bestTierIndex = 0;
        $bestMatchCount = -1;
        $largestOptionCount = -1;
        $bestTierNameScore = -1;
        foreach ($activeTierVariation as $tierIndex => $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $options = $this->shopeeTierOptionList($tier, $useStandardiseTier);
            $tierNameKey = $this->normalizeSkuMatchValue($this->shopeeTierName($tier, $useStandardiseTier));
            $tierNameScore = preg_match('/\b(warna|color|colour|varian|variant)\b/i', $tierNameKey) ? 1 : 0;
            $matchCount = 0;
            foreach ($options as $option) {
                $key = $this->normalizeSkuMatchValue($this->shopeeTierOptionName($option, $useStandardiseTier));
                if ($key !== '' && isset($requestedKeys[$key])) {
                    $matchCount += 1;
                }
            }

            $optionCount = count($options);
            if (
                $matchCount > $bestMatchCount
                || ($matchCount === $bestMatchCount && $tierNameScore > $bestTierNameScore)
                || ($matchCount === $bestMatchCount && $tierNameScore === $bestTierNameScore && $optionCount > $largestOptionCount)
            ) {
                $bestTierIndex = (int) $tierIndex;
                $bestMatchCount = $matchCount;
                $bestTierNameScore = $tierNameScore;
                $largestOptionCount = $optionCount;
            }
        }

        return $bestTierIndex;
    }

    private function defaultShopeeTierIndexes(array $models, int $tierCount): array
    {
        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $tierIndex = array_map('intval', $this->normalizeShopeeIndexList($model['tier_index'] ?? []));
            if (count($tierIndex) === $tierCount) {
                return $tierIndex;
            }
        }

        return array_fill(0, $tierCount, 0);
    }

    private function splitShopeeVariantTierValues(string $variantName, int $tierCount, int $targetTierIndex): array
    {
        $values = array_fill(0, $tierCount, '');
        if ($tierCount > 1 && str_contains($variantName, ',')) {
            $parts = array_map('trim', explode(',', $variantName));
            if (count($parts) === $tierCount && collect($parts)->every(fn ($part) => $part !== '')) {
                return array_values($parts);
            }
        }

        $values[$targetTierIndex] = trim($variantName);
        return $values;
    }

    private function fallbackShopeeTierOptionName(array $tierOptions, int $index, bool $useStandardiseTier): string
    {
        $option = $tierOptions[$index] ?? $tierOptions[0] ?? null;
        return $this->shopeeTierOptionName($option, $useStandardiseTier);
    }

    private function buildShopeeUpdateModel(array $variant, array $existingModel): array
    {
        $model = [
            'model_id' => (int) $existingModel['model_id'],
            'model_sku' => mb_substr((string) (
                ($variant['seller_sku'] ?? '') !== ''
                    ? $variant['seller_sku']
                    : ($existingModel['model_sku'] ?? '')
            ), 0, 100),
        ];

        $preOrder = data_get($existingModel, 'pre_order');
        if (is_array($preOrder) && array_key_exists('is_pre_order', $preOrder)) {
            $model['pre_order'] = [
                'is_pre_order' => (bool) ($preOrder['is_pre_order'] ?? false),
            ];
            if (($model['pre_order']['is_pre_order'] ?? false) && isset($preOrder['days_to_ship'])) {
                $model['pre_order']['days_to_ship'] = (int) $preOrder['days_to_ship'];
            }
        }

        return $model;
    }

    private function rebuildShopeeAddModelsFromFreshTier(array $plannedVariants, array $modelPayload, string $sellerStockLocationId = '', ?float $modelWeight = null): ?array
    {
        $standardiseTierVariation = data_get($modelPayload, 'standardise_tier_variation', []);
        $tierVariation = data_get($modelPayload, 'tier_variation', []);
        $useStandardiseTier = is_array($standardiseTierVariation) && $standardiseTierVariation !== [];
        $activeTierVariation = $useStandardiseTier ? $standardiseTierVariation : $tierVariation;

        if (! is_array($activeTierVariation) || $activeTierVariation === [] || count($activeTierVariation) > 2) {
            return null;
        }

        $tierCount = count($activeTierVariation);
        $targetTierIndex = (int) ($plannedVariants[0]['target_tier_index'] ?? 0);
        $optionIndexByTierName = [];
        foreach ($activeTierVariation as $tierIndex => $tier) {
            if (! is_array($tier)) {
                return null;
            }

            $optionIndexByTierName[$tierIndex] = [];
            foreach ($this->shopeeTierOptionList($tier, $useStandardiseTier) as $index => $option) {
                $name = $this->shopeeTierOptionName($option, $useStandardiseTier);
                $key = $this->normalizeSkuMatchValue($name);
                if ($key !== '') {
                    $optionIndexByTierName[$tierIndex][$key] = (int) $index;
                }
            }
        }

        $modelList = [];
        $updatedPlannedVariants = [];

        foreach ($plannedVariants as $variant) {
            $defaultTierIndexes = $variant['default_tier_indexes'] ?? array_fill(0, $tierCount, 0);
            $tierIndex = array_map('intval', is_array($defaultTierIndexes) ? $defaultTierIndexes : []);
            if (count($tierIndex) !== $tierCount) {
                $tierIndex = array_fill(0, $tierCount, 0);
            }

            $tierValues = $variant['tier_values'] ?? $this->splitShopeeVariantTierValues((string) ($variant['variant_name'] ?? ''), $tierCount, $targetTierIndex);
            foreach ($tierValues as $currentTierIndex => $tierValue) {
                $tierValueKey = $this->normalizeSkuMatchValue($tierValue);
                if ($tierValueKey === '') {
                    continue;
                }

                if (! array_key_exists($tierValueKey, $optionIndexByTierName[$currentTierIndex] ?? [])) {
                    return null;
                }

                $tierIndex[$currentTierIndex] = $optionIndexByTierName[$currentTierIndex][$tierValueKey];
            }

            $modelList[] = $this->buildShopeeAddModel($variant, $tierIndex, $sellerStockLocationId, $modelWeight);
            $updatedPlannedVariants[] = [
                ...$variant,
                'tier_index' => $tierIndex,
            ];
        }

        return [
            'model_list' => $modelList,
            'planned_variants' => $updatedPlannedVariants,
        ];
    }

    private function normalizePositiveInt(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        $numeric = (int) preg_replace('/[^\d]/', '', (string) $value);
        return max(0, $numeric);
    }

    private function normalizeNonNegativeInt(mixed $value): int
    {
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return max(0, (int) preg_replace('/[^\d]/', '', (string) $value));
    }

    private function resolveShopeeApiTestContext(array $data = []): array
    {
        $this->ensureShopeeAuthColumns();
        $this->normalizeActiveMarketplaceTokens();

        $shopId = trim((string) ($data['shop_id'] ?? ''));
        $accessToken = trim((string) ($data['access_token'] ?? ''));
        $accountKey = trim((string) ($data['account_key'] ?? ''));
        $accountName = '';

        if ($shopId === '' || $accessToken === '' || $accountKey !== '') {
            $tokens = $this->activeShopeeTokensForSync();
            $token = null;

            if ($accountKey !== '') {
                $token = $tokens->first(fn ($row) => trim((string) ($row->account_key ?? '')) === $accountKey);
            }

            if (! $token && $shopId !== '') {
                $token = $tokens->first(fn ($row) => trim((string) ($row->shop_id ?? '')) === $shopId);
            }

            if (! $token) {
                $token = $tokens->first();
            }

            if ($token) {
                $shopId = $shopId !== '' ? $shopId : trim((string) ($token->shop_id ?? ''));
                $accessToken = $accessToken !== '' ? $accessToken : trim((string) ($token->access_token ?? ''));
                $accountKey = trim((string) ($token->account_key ?? $accountKey));
                $accountName = trim((string) ($token->account_name ?? ''));
            }
        }

        return [
            'account_key' => $accountKey ?: null,
            'account_name' => $accountName ?: null,
            'shop_id' => $shopId,
            'access_token' => $accessToken,
        ];
    }

    private function boolString(mixed $value): string
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($normalized === null) {
            return trim((string) $value) === '1' ? 'true' : 'false';
        }

        return $normalized ? 'true' : 'false';
    }

    private function storeShopeeProductPayload(array $item, array $models, array $tierVariations, int $shopId): int
    {
        $itemId = (int) ($item['item_id'] ?? 0);

        if ($itemId <= 0) {
            return 0;
        }

        $priceMin = $this->shopeePrice($this->shopeePriceInfoValue($item['price_info'] ?? null, 'current_price', $item['price_min'] ?? 0));
        $priceMax = $this->shopeePrice($this->shopeePriceInfoValue($item['price_info'] ?? null, 'original_price', $item['price_max'] ?? $priceMin));
        $stock = $this->shopeeStock($item);
        $now = now();

        DB::table('shopee_product')->updateOrInsert(
            ['item_id' => $itemId],
            [
                'shop_id' => $shopId,
                'name' => $item['item_name'] ?? '',
                'description' => $item['description'] ?? null,
                'category_id' => $this->toInt($item['category_id'] ?? null),
                'price_min' => $priceMin,
                'price_max' => max($priceMin, $priceMax),
                'price_before_discount' => $this->shopeePrice($item['price_before_discount'] ?? null),
                'currency' => $item['currency'] ?? null,
                'stock' => $stock,
                'sold' => $this->toInt($item['sold'] ?? null),
                'liked_count' => $this->toInt($item['liked_count'] ?? null),
                'rating' => (float) ($item['rating_star'] ?? 0),
                'historical_sold' => $this->toInt($item['historical_sold'] ?? null),
                'status' => $item['item_status'] ?? null,
                'create_time' => $this->timestampToDate($item['create_time'] ?? null) ?? $now,
                'update_time' => $this->timestampToDate($item['update_time'] ?? null) ?? $now,
                'is_active' => DB::raw('true'),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $this->storeShopeeImages($itemId, null, data_get($item, 'image.image_url_list', []));

        if ($models === []) {
            $models = [[
                'model_id' => 0,
                'model_name' => 'Tanpa Varian',
                'model_sku' => $item['item_sku'] ?? '',
                'price_info' => [['current_price' => $priceMin]],
                'stock' => $stock,
            ]];
        }

        foreach ($models as $model) {
            $this->storeShopeeModelPayload($itemId, (string) ($item['item_name'] ?? ''), $model);
            $this->storeShopeeImages($itemId, (string) ($model['model_id'] ?? '0'), $this->shopeeModelImageUrls($model, $tierVariations));
        }

        return $this->deleteShopeeModelsMissingFromSync($itemId, $models);
    }

    private function syncShopeeProductToDatabase(int $itemId): array
    {
        $this->ensureShopeeAuthColumns();
        $this->normalizeActiveMarketplaceTokens();

        $product = DB::table('shopee_product')->where('item_id', $itemId)->first();
        if (! $product) {
            return [
                'status' => 'error',
                'message' => 'Produk Shopee tidak ditemukan di cache lokal.',
                'products' => 0,
                'variants' => 0,
                'mode' => 'product',
                'item_id' => (string) $itemId,
            ];
        }

        $shopId = (int) ($product->shop_id ?? 0);
        $token = $this->activeShopeeTokensForSync()
            ->first(fn ($candidate) => (int) ($candidate->shop_id ?? 0) === $shopId);

        if (! $token) {
            return [
                'status' => 'error',
                'message' => 'Token Shopee aktif untuk toko produk ini tidak ditemukan.',
                'products' => 0,
                'variants' => 0,
                'mode' => 'product',
                'item_id' => (string) $itemId,
            ];
        }

        try {
            $config = $this->shopeeConfig();
            $baseItems = $this->fetchShopeeBaseInfo($config, $shopId, (string) $token->access_token, [$itemId]);

            if ($baseItems === []) {
                DB::table('shopee_product')->where('item_id', $itemId)->update([
                    'is_active' => DB::raw('false'),
                    'status' => DB::raw("COALESCE(status, 'REMOVED')"),
                    'updated_at' => now(),
                ]);

                return [
                    'status' => 'ok',
                    'message' => 'Produk Shopee tidak lagi ditemukan dan dinonaktifkan dari cache.',
                    'products' => 0,
                    'variants' => 0,
                    'deactivated_products' => 1,
                    'mode' => 'product',
                    'item_id' => (string) $itemId,
                    'last_sync_at' => now()->toDateTimeString(),
                ];
            }

            $modelPayload = $this->fetchShopeeModelList($config, $shopId, (string) $token->access_token, $itemId);
            $models = data_get($modelPayload, 'model', []);
            $removedVariants = $this->storeShopeeProductPayload(
                $baseItems[0],
                $models,
                data_get($modelPayload, 'tier_variation', []),
                $shopId
            );
            $variantCount = max(1, count($models));
            $message = 'Produk Shopee dipilih berhasil disinkronkan: '.$variantCount.' varian aktif.';
            if ($removedVariants > 0) {
                $message .= ' '.$removedVariants.' varian lama dihapus dari cache.';
            }

            DB::table('shopee_sync_logs')->insert([
                'status' => 'ok',
                'message' => $message,
                'product_count' => 1,
                'variant_count' => $variantCount,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'ok',
                'message' => $message,
                'products' => 1,
                'variants' => $variantCount,
                'removed_variants' => $removedVariants,
                'mode' => 'product',
                'item_id' => (string) $itemId,
                'last_sync_at' => now()->toDateTimeString(),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
                'products' => 0,
                'variants' => 0,
                'mode' => 'product',
                'item_id' => (string) $itemId,
            ];
        }
    }

    private function deleteShopeeModelsMissingFromSync(int $itemId, array $models): int
    {
        $activeModelIds = collect($models)
            ->map(fn (array $model) => (string) ($model['model_id'] ?? '0'))
            ->unique()
            ->values()
            ->all();

        $staleModelIds = DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->whereNotIn('model_id', $activeModelIds)
            ->pluck('model_id')
            ->map(fn ($modelId) => (string) $modelId)
            ->all();

        if ($staleModelIds === []) {
            return 0;
        }

        DB::table('shopee_product_image')
            ->where('item_id', $itemId)
            ->whereIn('model_id', $staleModelIds)
            ->delete();

        return DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->whereIn('model_id', $staleModelIds)
            ->delete();
    }

    private function storeShopeeModelPayload(int $itemId, string $itemName, array $model): void
    {
        $modelId = (string) ($model['model_id'] ?? '0');
        $modelName = (string) ($model['model_name'] ?? $model['name'] ?? 'Tanpa Varian');
        $modelSku = (string) ($model['model_sku'] ?? '');
        $price = $this->shopeePrice($this->shopeePriceInfoValue($model['price_info'] ?? null, 'current_price', $model['price'] ?? 0));
        $originalPrice = $this->shopeePrice($this->shopeePriceInfoValue($model['price_info'] ?? null, 'original_price', $model['price_before_discount'] ?? $price));
        $originalPrice = max($price, $originalPrice);
        $stock = $this->shopeeModelStock($model);
        $now = now();

        DB::table('shopee_product_model')->updateOrInsert(
            ['model_id' => $modelId, 'item_id' => $itemId],
            [
                'name' => $modelName,
                'model_sku' => $modelSku !== '' ? $modelSku : null,
                'price' => $price,
                'original_price' => $originalPrice,
                'stock' => $stock,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $modelSkuIsShared = $modelSku !== '' && DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->where('model_sku', $modelSku)
            ->where('model_id', '<>', $modelId)
            ->exists();
        $skuFragment = $modelSku !== '' && ! $modelSkuIsShared ? $modelSku : $modelName;
        $internalSku = str_starts_with(strtoupper($skuFragment), 'INT-')
            ? $skuFragment
            : 'INT-'.$itemId.'-'.$this->sanitizeSkuFragment($skuFragment);
        $stockValues = [
            'internal_sku' => $internalSku,
            'shopee_product_id' => (string) $itemId,
            'shopee_sku' => $modelId,
            'shopee_seller_sku' => $modelSku !== '' ? $modelSku : null,
            'product_name' => $itemName,
            'variant_name' => $modelName,
            'stock_qty' => $stock,
            'is_hidden_from_mapping' => DB::raw('false'),
            'hidden_from_mapping_reason' => null,
            'hidden_from_mapping_at' => null,
            'hidden_from_mapping_by' => null,
            'updated_at' => $now,
        ];

        $existingStock = DB::table('stock_master')
            ->where('internal_sku', $internalSku)
            ->first()
            ?: DB::table('stock_master')
                ->where('shopee_product_id', (string) $itemId)
                ->where('shopee_sku', $modelId)
                ->orderBy('id')
                ->first();

        if ($existingStock) {
            DB::table('stock_master')->where('id', $existingStock->id)->update($stockValues);
            $stockMasterId = (int) $existingStock->id;
        } else {
            DB::table('stock_master')->updateOrInsert(
                ['internal_sku' => $internalSku],
                $stockValues + ['created_at' => $now]
            );
            $stockMasterId = (int) DB::table('stock_master')->where('internal_sku', $internalSku)->value('id');
        }

        DB::table('stock_master')
            ->where('shopee_product_id', (string) $itemId)
            ->where('shopee_sku', $modelId)
            ->where('id', '<>', $stockMasterId)
            ->update([
                'is_hidden_from_mapping' => DB::raw('true'),
                'hidden_from_mapping_reason' => 'Auto-hide: duplikat stock master untuk varian Shopee yang sama.',
                'hidden_from_mapping_at' => $now,
                'hidden_from_mapping_by' => 'system',
                'updated_at' => $now,
            ]);
    }

    private function storeShopeeImages(int $itemId, ?string $modelId, array $urls): void
    {
        foreach ($urls as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $cachedUrl = $this->cacheMarketplaceImageUrl($url, 'shopee', (string) $itemId, (string) ($modelId ?? 'product'));

            if (! DB::table('shopee_product_image')->where('item_id', $itemId)->where('model_id', $modelId)->where('image_url', $cachedUrl)->exists()) {
                DB::table('shopee_product_image')->insert([
                    'item_id' => $itemId,
                    'model_id' => $modelId,
                    'image_url' => $cachedUrl,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function shopeeModelImageUrls(array $model, array $tierVariations): array
    {
        return $this->imageUrlsFromCandidates([
            data_get($model, 'image'),
            data_get($model, 'image_id'),
            data_get($model, 'image_url'),
            data_get($model, 'image_info.image_id'),
            data_get($model, 'image_info.image_url'),
            data_get($model, 'image_info.image_url_list'),
            data_get($model, 'image_info.image.url'),
            data_get($model, 'image_info.image.urls'),
            data_get($model, 'image.image_id'),
            data_get($model, 'image.image_url'),
            data_get($model, 'image.image_url_list'),
            ...$this->shopeeTierVariationImageCandidates($model, $tierVariations),
        ]);
    }

    private function shopeeTierVariationImageCandidates(array $model, array $tierVariations): array
    {
        $tierIndexes = $this->normalizeShopeeIndexList(data_get($model, 'tier_index', []));

        if (! is_array($tierIndexes) || ! is_array($tierVariations)) {
            return [];
        }

        $candidates = [];

        foreach ($tierIndexes as $tierPosition => $optionIndex) {
            if (! is_numeric($optionIndex)) {
                continue;
            }

            $option = data_get($tierVariations, $tierPosition.'.option_list.'.((int) $optionIndex), []);

            if (! is_array($option)) {
                continue;
            }

            $candidates[] = data_get($option, 'image');
            $candidates[] = data_get($option, 'image.image_id');
            $candidates[] = data_get($option, 'image.image_url');
            $candidates[] = data_get($option, 'image.image_url_list');
            $candidates[] = data_get($option, 'image.url');
            $candidates[] = data_get($option, 'image.urls');
            $candidates[] = data_get($option, 'image_id');
            $candidates[] = data_get($option, 'image_url');
            $candidates[] = data_get($option, 'image_url_list');
        }

        return $candidates;
    }

    private function normalizeShopeeIndexList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
        }

        if (is_string($value)) {
            $trimmed = trim($value, "[] \t\n\r\0\x0B");

            if ($trimmed === '') {
                return [];
            }

            return preg_split('/[\s,]+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (is_numeric($value)) {
            return [(string) $value];
        }

        return [];
    }

    private function imageUrlsFromCandidates(array $candidates): array
    {
        $urls = [];

        $collect = function (mixed $value) use (&$collect, &$urls): void {
            if (is_string($value)) {
                $trimmed = trim($value);

                if ($trimmed !== '' && $this->isImageUrl($trimmed)) {
                    $urls[] = $trimmed;
                }

                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $key => $child) {
                if (is_string($key) && in_array($key, ['url', 'uri', 'image_url', 'thumb_url'], true) && is_string($child)) {
                    $trimmed = trim($child);

                    if ($trimmed !== '' && $this->isImageUrl($trimmed)) {
                        $urls[] = $trimmed;
                    }
                }

                if (is_string($key) && in_array($key, ['image_id', 'image_id_list'], true)) {
                    foreach ($this->imageIdCandidates($child) as $imageId) {
                        $resolved = $this->resolveShopeeImageId($imageId);

                        if ($resolved) {
                            $urls[] = $resolved;
                        }
                    }
                }

                $collect($child);
            }
        };

        foreach ($candidates as $candidate) {
            $collect($candidate);
        }

        return array_values(array_unique($urls));
    }

    private function imageIdCandidates(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? [$trimmed] : [];
        }

        if (is_array($value)) {
            $values = [];

            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $values[] = trim($item);
                }
            }

            return $values;
        }

        return [];
    }

    private function resolveShopeeImageId(string $imageId): ?string
    {
        if ($imageId === '' || $this->isImageUrl($imageId)) {
            return $this->isImageUrl($imageId) ? $imageId : null;
        }

        $normalized = ltrim($imageId, '/');

        if ($normalized === '') {
            return null;
        }

        return 'https://down-id.img.susercontent.com/file/'.$normalized;
    }

    private function isImageUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            || str_starts_with($value, '//');
    }

    private function isLiveShopeeStatus(?string $status): bool
    {
        $normalized = strtoupper(trim((string) $status));

        return $normalized === '' || in_array($normalized, ['NORMAL', 'LIVE', 'PUBLISHED', 'ACTIVE'], true);
    }

    public function tokenAction(string $action): JsonResponse
    {
        $this->ensureShopeeAuthColumns();
        $this->normalizeActiveMarketplaceTokens();

        $account = $this->resolveAccountFromAction($action);

        if ($account && str_starts_with($action, 'connect-shopee')) {
            $result = $this->connectShopee($account);

            return response()->json($this->maskShopeeTokenPayload($result), ($result['status'] ?? '') === 'error' ? 422 : 200);
        }

        if ($account && str_starts_with($action, 'connect-tiktok')) {
            $result = $this->connectTiktok($account);

            return response()->json($this->maskTiktokTokenPayload($result), ($result['status'] ?? '') === 'error' ? 422 : 200);
        }

        if ($account && str_starts_with($action, 'auth-shopee')) {
            return response()->json([
                'status' => 'redirect',
                'action' => $action,
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Membuka halaman authorization '.$account['name'].'.',
                'redirect_url' => $this->buildShopeeAuthUrl($account),
            ]);
        }

        if ($account && str_starts_with($action, 'get-token-shopee')) {
            $callback = DB::table('shopee_callbacks')
                ->where('account_key', $account['key'])
                ->whereNull('used_at')
                ->latest('created_at')
                ->first();

            if (! $callback) {
                return response()->json([
                    'status' => 'error',
                    'action' => $action,
                    'account_key' => $account['key'],
                    'account_name' => $account['name'],
                    'message' => 'Belum ada callback '.$account['name'].' yang bisa ditukar menjadi token. Klik AUTH dulu.',
                ], 422);
            }

            return response()->json($this->maskShopeeTokenPayload($this->exchangeShopeeToken($callback)));
        }

        if ($account && str_starts_with($action, 'refresh-token-shopee')) {
            $result = $this->refreshShopeeToken($account);

            return response()->json($this->maskShopeeTokenPayload($result), ($result['status'] ?? '') === 'ok' ? 200 : 422);
        }

        if ($account && str_starts_with($action, 'auth-tiktok')) {
            return response()->json([
                'status' => 'redirect',
                'action' => $action,
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Membuka halaman authorization '.$account['name'].'.',
                'redirect_url' => $this->buildTiktokAuthUrl($account),
            ]);
        }

        if ($account && str_starts_with($action, 'get-token-tiktok')) {
            $result = $this->exchangeTiktokToken($account);

            return response()->json($this->maskTiktokTokenPayload($result), ($result['status'] ?? '') === 'ok' ? 200 : 422);
        }

        if ($account && str_starts_with($action, 'refresh-token-tiktok')) {
            $result = $this->refreshTiktokToken($account);

            return response()->json($this->maskTiktokTokenPayload($result), ($result['status'] ?? '') === 'ok' ? 200 : 422);
        }

        if ($account && str_starts_with($action, 'get-auth-shop-tiktok')) {
            $result = $this->getTiktokAuthorizedShops($account);

            return response()->json($result, ($result['status'] ?? '') === 'ok' ? 200 : 422);
        }

        return response()->json([
            'status' => 'error',
            'action' => $action,
            'account_key' => $account['key'] ?? null,
            'account_name' => $account['name'] ?? null,
            'message' => 'Aksi marketplace tidak dikenali.',
        ], 422);
    }

    public function autoRefreshMarketplaceTokens(bool $force = false): array
    {
        $this->ensureShopeeAuthColumns();
        $this->ensureTiktokAuthTables();
        $this->normalizeActiveMarketplaceTokens();

        $results = [];
        foreach (self::MARKETPLACE_ACCOUNTS as $key => $account) {
            $account = ['key' => $key, ...$account];
            $channel = $account['channel'];
            $token = $channel === 'shopee'
                ? $this->latestActiveShopeeToken($account)
                : $this->latestActiveTiktokToken($account);

            if (! $token) {
                $results[] = [
                    'status' => 'skipped',
                    'account_key' => $key,
                    'account_name' => $account['name'],
                    'channel' => $channel,
                    'message' => 'Belum ada token aktif untuk di-refresh.',
                ];
                continue;
            }

            $needsRefresh = $channel === 'shopee'
                ? $this->shopeeAccessTokenNeedsRefresh($token)
                : $this->tiktokAccessTokenNeedsRefresh($token);
            if (! $force && ! $needsRefresh) {
                $expireAt = $channel === 'shopee'
                    ? $this->shopeeAccessTokenExpireAt($token)
                    : $this->tiktokAccessTokenExpireAt($token);
                $results[] = [
                    'status' => 'ok',
                    'account_key' => $key,
                    'account_name' => $account['name'],
                    'channel' => $channel,
                    'message' => 'Access token masih aman.',
                    'access_token_expire_at' => $expireAt?->toDateTimeString(),
                ];
                continue;
            }

            $results[] = $channel === 'shopee'
                ? $this->refreshShopeeToken($account)
                : $this->refreshTiktokToken($account);
        }

        $failed = collect($results)->where('status', 'error')->count();

        return [
            'status' => $failed > 0 ? 'warning' : 'ok',
            'message' => $failed > 0
                ? 'Auto refresh token selesai dengan sebagian gagal.'
                : 'Auto refresh token marketplace selesai.',
            'failed' => $failed,
            'items' => $results,
        ];
    }

    public function shopeeCallback(Request $request): Response
    {
        $this->ensureShopeeAuthColumns();

        $code = $request->query('code');
        $account = $this->resolveAccount((string) $request->query('account', 'shopee-agnishopbjm'), 'shopee');

        if (! $code) {
            return response('Callback Shopee tidak membawa code.', 422);
        }

        $callbackId = DB::table('shopee_callbacks')->insertGetId([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'code' => $code,
            'shop_id' => $request->query('shop_id') ? (int) $request->query('shop_id') : null,
            'main_account_id' => $request->query('main_account_id') ? (int) $request->query('main_account_id') : null,
            'partner_id' => $this->shopeeConfig()['partner_id'],
            'query_payload' => json_encode($request->query()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callback = DB::table('shopee_callbacks')->where('id', $callbackId)->first();
        $result = $this->exchangeShopeeToken($callback);
        $ok = ($result['error'] ?? '') === '' && ! empty($result['access_token']);

        $title = $ok ? 'Token Shopee berhasil disimpan' : 'Token Shopee gagal diproses';
        $message = $ok
            ? 'Authorization berhasil. Kamu bisa kembali ke dashboard.'
            : ($result['message'] ?? 'Shopee mengembalikan error.');

        return response($this->renderShopeeCallbackPage($title, $message, $result), $ok ? 200 : 422)
            ->header('Content-Type', 'text/html');
    }

    public function tiktokCallback(Request $request): Response
    {
        $code = $request->query('code');
        $account = $this->resolveAccount((string) $request->query('state', 'tiktok-agnishopbjm'), 'tiktok');

        if (! $code) {
            return response('Callback TikTok tidak membawa code.', 422);
        }

        $this->ensureTiktokAuthTables();

        DB::table('tiktok_callbacks')->insert([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'code' => $code,
            'app_key' => $request->query('app_key'),
            'shop_region' => $request->query('shop_region'),
            'state' => $request->query('state'),
            'query_payload' => json_encode($request->query()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [
            'Status' => 'ok',
            'Akun' => $account['name'],
            'App Key' => $request->query('app_key', '-'),
            'Shop Region' => $request->query('shop_region', '-'),
            'Code' => $this->maskToken((string) $code),
        ];

        $tableRows = collect($rows)->map(fn ($value, string $label) => '<tr><th>'.e($label).'</th><td>'.e((string) ($value ?: '-')).'</td></tr>')->implode('');

        return response('<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Callback TikTok tersimpan</title><style>body{font-family:Arial,sans-serif;padding:32px;line-height:1.5;color:#0f172a}h1{margin-bottom:12px}table{border-collapse:collapse;width:100%;margin:18px 0;background:#fff}th,td{border:1px solid #d9e2ec;padding:10px 12px;text-align:left}th{width:180px;background:#f8fafc}a{color:#0f5fc7}</style></head><body><h1>Callback TikTok tersimpan</h1><p>Authorization berhasil. Kembali ke dashboard lalu klik GET TOKEN.</p><table>'.$tableRows.'</table><p><a href="/dashboard">Kembali ke Dashboard</a></p></body></html>')
            ->header('Content-Type', 'text/html');
    }

    public function tiktokItems(Request $request): JsonResponse
    {
        $this->ensureTiktokProductTables();

        $syncResult = null;

        if ($request->boolean('sync')) {
            $productId = trim((string) $request->query('product_id', ''));
            $syncResult = $productId !== ''
                ? $this->syncTiktokProductToDatabase($productId)
                : $this->syncTiktokProductsToDatabase();
        }

        $rows = DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('product_id', 'product_name', 'image_url', 'sku_id', 'sku_name', 'seller_sku', 'stock_qty', 'price', 'subtotal', 'updated_at')
            ->orderBy('product_name')
            ->orderBy('sku_name')
            ->get()
            ->groupBy('product_id');

        $lastSyncAt = Schema::hasTable('tiktok_products')
            ? DB::table('tiktok_products')->whereRaw('COALESCE(is_active, true) = true')->max('updated_at')
            : null;

        return response()->json([
            'count' => $rows->count(),
            'last_sync_at' => $lastSyncAt,
            'message' => $syncResult['message'] ?? ($lastSyncAt ? 'Data TikTok dari cache database.' : 'Belum ada cache produk TikTok.'),
            'sync' => [
                'status' => $syncResult['status'] ?? ($lastSyncAt ? 'cached' : 'empty'),
                'message' => $syncResult['message'] ?? ($lastSyncAt ? 'Terakhir sinkron: '.$lastSyncAt : 'Belum pernah sinkron.'),
                'last_sync_at' => $lastSyncAt,
                ...($syncResult ?? []),
                'mode' => $syncResult['mode'] ?? 'cache',
            ],
            'items' => $rows->map(function ($group, string $productId) {
                $first = $group->first();

                return [
                'product_id' => $productId,
                'product_name' => $first->product_name,
                'image_url' => $first->image_url ?? null,
                'updated_at' => $first->updated_at,
                'skus' => $group->map(fn ($sku) => [
                        'sku_id' => $sku->sku_id ?? null,
                        'sku_name' => $sku->sku_name,
                        'seller_sku' => $sku->seller_sku ?? null,
                        'kode_variasi' => $this->tiktokSkuVariationCode((string) $productId, $sku),
                        'stock_qty' => (int) ($sku->stock_qty ?? 0),
                        'price' => (int) ($sku->price ?? 0),
                        'subtotal' => (int) ($sku->subtotal ?? 0),
                        'image_url' => $sku->image_url ?? null,
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    private function syncTiktokProductsToDatabase(): array
    {
        $this->ensureTiktokProductTables();

        try {
            $config = $this->tiktokConfig();
            $shop = $this->latestTiktokShop();
            $accessToken = $this->activeTiktokAccessTokenForSync();

            if (! $shop) {
                return [
                    'status' => 'error',
                    'message' => 'Belum ada shop TikTok tersimpan. Jalankan AUTH / GET SHOP dulu.',
                    'products' => 0,
                    'variants' => 0,
                    'debug' => [
                        'shop' => null,
                        'access_token_present' => $accessToken !== '',
                        'app_key' => $config['app_key'] ?? null,
                    ],
                ];
            }

            if ($accessToken === '') {
                return [
                    'status' => 'error',
                    'message' => 'Belum ada access token TikTok aktif. Jalankan AUTH / GET TOKEN dulu.',
                    'products' => 0,
                    'variants' => 0,
                    'debug' => [
                        'shop_id' => (string) ($shop->shop_id ?? $shop->id ?? ''),
                        'shop_cipher' => (string) ($shop->cipher ?? $shop->shop_cipher ?? ''),
                        'app_key' => $config['app_key'] ?? null,
                    ],
                ];
            }

            $syncCount = 0;
            $variantCount = 0;
            $syncedProductIds = [];
            $pageSize = 100;
            $pageToken = null;
            $apiHost = rtrim((string) $config['api_host'], '/');
            $searchUrl = $apiHost.'/product/202502/products/search';
            $detailBaseUrl = $apiHost.'/product/202309/products/';

            do {
                $timestamp = time();
                $shopCipher = (string) ($shop->cipher ?? $shop->shop_cipher ?? '');
                if ($shopCipher === '') {
                    return [
                        'status' => 'error',
                        'message' => 'Shop cipher TikTok belum tersimpan. Jalankan GET AUTH SHOP dulu.',
                        'products' => 0,
                        'variants' => 0,
                        'debug' => [
                            'shop' => $shop,
                            'shop_cipher' => $shopCipher,
                            'access_token_present' => $accessToken !== '',
                            'app_key' => $config['app_key'] ?? null,
                        ],
                    ];
                }
                $searchParams = [
                    'app_key' => $config['app_key'],
                    'timestamp' => $timestamp,
                    'shop_cipher' => $shopCipher,
                    'page_size' => $pageSize,
                ];
                if ($pageToken) {
                    $searchParams['page_token'] = $pageToken;
                }
                $searchBody = [
                    'status' => 'ALL',
                ];
                $searchBodyString = json_encode($searchBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $searchParams['sign'] = $this->generateTiktokSign(
                    '/product/202502/products/search',
                    $searchParams,
                    $config['app_secret'],
                    $searchBodyString
                );

                $searchResponse = Http::timeout(45)
                    ->asJson()
                    ->withHeaders(['x-tts-access-token' => $accessToken])
                    ->withOptions(['query' => $searchParams])
                    ->post($searchUrl, $searchBody);

                $payload = $searchResponse->json();

                if (! is_array($payload)) {
                    return [
                        'status' => 'error',
                        'message' => 'TikTok search API tidak mengembalikan JSON valid.',
                        'products' => 0,
                        'variants' => 0,
                        'debug' => [
                            'url' => $searchUrl,
                            'query' => $searchParams,
                            'request_body' => $searchBody,
                            'http_status' => $searchResponse->status(),
                            'response_body' => $searchResponse->body(),
                            'shop_id' => (string) ($shop->shop_id ?? $shop->id ?? ''),
                            'shop_cipher' => (string) ($shop->cipher ?? $shop->shop_cipher ?? ''),
                            'app_key' => $config['app_key'] ?? null,
                            'curl' => $this->buildTiktokCurl('POST', $searchUrl, $searchParams, [
                                'x-tts-access-token' => $accessToken,
                                'content-type' => 'application/json',
                            ], $searchBody),
                        ],
                    ];
                }

                if ((int) ($payload['code'] ?? -1) !== 0) {
                    return [
                        'status' => 'error',
                        'message' => $payload['message'] ?? 'TikTok search API error.',
                        'products' => 0,
                        'variants' => 0,
                        'debug' => [
                            'url' => $searchUrl,
                            'query' => $searchParams,
                            'request_body' => $searchBody,
                            'response' => $payload,
                            'shop_id' => (string) ($shop->shop_id ?? $shop->id ?? ''),
                            'shop_cipher' => (string) ($shop->cipher ?? $shop->shop_cipher ?? ''),
                            'app_key' => $config['app_key'] ?? null,
                            'curl' => $this->buildTiktokCurl('POST', $searchUrl, $searchParams, [
                                'x-tts-access-token' => $accessToken,
                                'content-type' => 'application/json',
                            ], $searchBody),
                        ],
                    ];
                }

                $products = data_get($payload, 'data.products', []);
                $pageToken = data_get($payload, 'data.next_page_token');
                if (! is_array($products) || $products === []) {
                    break;
                }

                foreach ($products as $product) {
                    $productId = (string) ($product['id'] ?? '');
                    if ($productId === '') {
                        continue;
                    }

                    $detail = $this->fetchTiktokProductDetail($config, $accessToken, $shop, $productId, $detailBaseUrl);
                    $this->storeTiktokProductPayload($detail ?: $product);
                    $syncedProductIds[$productId] = true;
                    $syncCount++;
                    $variantCount += $this->activeTiktokVariantCount($productId);
                }

            } while ($pageToken);

            $deactivatedProducts = $this->deactivateTiktokProductsMissingFromSync(array_keys($syncedProductIds));
            $deactivatedDuplicateSkus = $this->deactivateDuplicateTiktokProductSkuRows();
            $message = $syncCount.' produk TikTok berhasil disinkronkan.';
            if ($deactivatedProducts > 0) {
                $message .= ' '.$deactivatedProducts.' produk lama dinonaktifkan.';
            }
            if ($deactivatedDuplicateSkus > 0) {
                $message .= ' '.$deactivatedDuplicateSkus.' SKU duplikat cache dinonaktifkan.';
            }

            DB::table('tiktok_sync_logs')->insert([
                'status' => 'ok',
                'message' => $message,
                'product_count' => $syncCount,
                'variant_count' => $variantCount,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'ok',
                'message' => $message,
                'products' => $syncCount,
                'variants' => $variantCount,
                'deactivated_products' => $deactivatedProducts,
                'deactivated_duplicate_skus' => $deactivatedDuplicateSkus,
                'last_sync_at' => now()->toDateTimeString(),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
                'products' => 0,
                'variants' => 0,
                'debug' => [
                    'shop_id' => isset($shop) ? (string) ($shop->shop_id ?? $shop->id ?? '') : null,
                    'shop_cipher' => isset($shop) ? (string) ($shop->cipher ?? $shop->shop_cipher ?? '') : null,
                    'app_key' => $config['app_key'] ?? null,
                    'curl' => isset($searchUrl, $searchParams, $accessToken)
                            ? $this->buildTiktokCurl('POST', $searchUrl, $searchParams, [
                            'x-tts-access-token' => $accessToken,
                            'content-type' => 'application/json',
                        ], $searchBody ?? ['status' => 'ALL'])
                        : null,
                ],
            ];
        }
    }

    private function deactivateTiktokProductsMissingFromSync(array $activeProductIds): int
    {
        if (! Schema::hasTable('tiktok_products')) {
            return 0;
        }

        $activeProductIds = array_values(array_unique(array_filter(array_map(
            fn ($productId) => trim((string) $productId),
            $activeProductIds
        ), fn (string $productId) => $productId !== '')));

        $query = DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true');

        if ($activeProductIds !== []) {
            $query->whereNotIn('product_id', $activeProductIds);
        }

        return $query->update([
            'is_active' => DB::raw('false'),
            'updated_at' => now(),
        ]);
    }

    private function deactivateDuplicateTiktokProductSkuRows(?string $productId = null): int
    {
        if (! Schema::hasTable('tiktok_products')) {
            return 0;
        }

        $rowsQuery = DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('id', 'product_id', 'sku_id', 'sku_name', 'updated_at', 'created_at')
            ->orderBy('product_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($productId !== null && trim($productId) !== '') {
            $rowsQuery->where('product_id', trim($productId));
        }

        $seen = [];
        $duplicateIds = [];

        foreach ($rowsQuery->get() as $row) {
            $skuIdentity = $this->tiktokCacheSkuIdentityKey($row);
            if ($skuIdentity === '') {
                continue;
            }

            $key = trim((string) ($row->product_id ?? '')).'|'.$skuIdentity;
            if (isset($seen[$key])) {
                $duplicateIds[] = (int) $row->id;
                continue;
            }

            $seen[$key] = true;
        }

        foreach (array_chunk($duplicateIds, 500) as $chunk) {
            DB::table('tiktok_products')
                ->whereIn('id', $chunk)
                ->update([
                    'is_active' => DB::raw('false'),
                    'updated_at' => now(),
                ]);
        }

        return count($duplicateIds);
    }

    private function tiktokCacheSkuIdentityKey(object $row): string
    {
        $skuId = trim((string) ($row->sku_id ?? ''));
        if ($skuId !== '') {
            return 'sku_id:'.$skuId;
        }

        $skuName = $this->normalizeSkuMatchValue($row->sku_name ?? '');
        if ($skuName !== '') {
            return 'sku_name:'.$skuName;
        }

        return '';
    }

    private function fetchTiktokProductDetail(array $config, string $accessToken, ?object $shop, string $productId, string $detailBaseUrl): ?array
    {
        $shopCipher = (string) ($shop->cipher ?? $shop->shop_cipher ?? '');
        $shopId = (string) ($shop->shop_id ?? $shop->id ?? '');
        $timestamp = time();
        $params = [
            'app_key' => $config['app_key'],
            'shop_cipher' => $shopCipher,
            'shop_id' => $shopId,
            'timestamp' => $timestamp,
            'version' => '202309',
        ];
        $params['sign'] = $this->generateTiktokSign('/product/202309/products/'.$productId, $params, $config['app_secret']);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'content-type' => 'application/json',
            ])
            ->get($detailBaseUrl.$productId, $params);

        $payload = $response->json();

        if (! is_array($payload) || (int) ($payload['code'] ?? -1) !== 0) {
            logger()->warning('TikTok detail request failed', [
                'product_id' => $productId,
                'url' => $detailBaseUrl.$productId,
                'params' => $params,
                'http_status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);
        }

        return is_array($payload) ? data_get($payload, 'data') : null;
    }

    private function submitTiktokVariantMutation(object $stock, array $draftPayload, object $shop, string $accessToken, ?array $existingProduct = null, array $options = []): array
    {
        $config = $this->tiktokConfig();
        $shopCipher = (string) ($shop->cipher ?? $shop->shop_cipher ?? '');

        if ($shopCipher === '') {
            return [
                'ok' => false,
                'message' => 'Shop TikTok belum lengkap untuk mengirim request.',
            ];
        }

        $existingDetail = null;
        if ($existingProduct && ! empty($existingProduct['product_id'])) {
            $existingDetail = $this->fetchTiktokProductDetail(
                $config,
                $accessToken,
                $shop,
                (string) $existingProduct['product_id'],
                $config['api_host'].'/product/202309/products/'
            );
        }

        $mainImages = [];
        foreach ((array) data_get($existingDetail, 'main_images', []) as $imageNode) {
            $imageUrl = $this->extractTiktokImageNodeUrl($imageNode);
            if (is_string($imageUrl) && trim($imageUrl) !== '') {
                $mainImages[] = $imageUrl;
            }
        }

        $uploadedImageUri = trim((string) ($options['uploaded_image_uri'] ?? ''));
        if ($uploadedImageUri !== '') {
            $mainImages[] = $uploadedImageUri;
        }

        foreach ([
            $draftPayload['source']['image_url'] ?? null,
            $draftPayload['target']['image_url'] ?? null,
        ] as $imageUrl) {
            if (is_string($imageUrl) && trim($imageUrl) !== '') {
                $mainImages[] = trim($imageUrl);
            }
        }

        $mainImages = array_values(array_unique($mainImages));

        $skuRows = [];
        $existingSkus = $this->normalizeTiktokSkuList(is_array($existingDetail) ? $existingDetail : []);
        foreach ($existingSkus as $sku) {
            $skuRows[] = array_filter([
                'id' => data_get($sku, 'id') ?? data_get($sku, 'sku_id'),
                'sku_name' => $this->deriveTiktokSkuName($sku),
                'seller_sku' => $this->extractTiktokSellerSku($sku),
                'price' => data_get($sku, 'price.sale_price', data_get($sku, 'price', 0)),
                'stock' => data_get($sku, 'inventory.0.quantity', data_get($sku, 'stock', 0)),
                'sku_img' => $this->extractTiktokSkuImageUrl($sku),
            ], fn ($value) => $value !== null && $value !== '');
        }

        $skuRows[] = array_filter([
            'sku_name' => (string) ($draftPayload['target']['variant_name'] ?? $stock->variant_name ?? 'Default'),
            'seller_sku' => (string) ($draftPayload['target']['seller_sku'] ?? $stock->internal_sku ?? ''),
            'price' => (int) data_get($draftPayload, 'source.price', 0),
            'stock' => (int) data_get($draftPayload, 'target.stock_qty', 0),
            'sku_img' => $uploadedImageUri !== ''
                ? $uploadedImageUri
                : ($draftPayload['target']['image_url'] ?? $draftPayload['source']['image_url'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');

        $body = array_filter([
            'title' => (string) ($existingDetail['title'] ?? $draftPayload['product_name'] ?? $stock->product_name ?? 'TikTok Product'),
            'main_images' => $mainImages,
            'skus' => $skuRows,
        ], fn ($value) => $value !== null && $value !== []);

        $method = $existingProduct && ! empty($existingProduct['product_id']) ? 'PUT' : 'POST';
        $path = $method === 'PUT'
            ? '/product/202309/products/'.(string) $existingProduct['product_id']
            : '/product/202309/products';

        $query = [
            'app_key' => $config['app_key'],
            'access_token' => $accessToken,
            'shop_cipher' => $shopCipher,
            'timestamp' => time(),
        ];

        $bodyString = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query['sign'] = $this->generateTiktokSign($path, $query, $config['app_secret'], $bodyString);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->withBody($bodyString, 'application/json')
            ->withOptions(['query' => $query])
            ->send($method, $config['api_host'].$path);

        $payload = $response->json();
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'message' => 'TikTok tidak mengembalikan JSON valid.',
                'http_status' => $response->status(),
                'response_body' => $response->body(),
                'request' => [
                    'method' => $method,
                    'path' => $path,
                    'query' => $query,
                    'body' => $body,
                ],
            ];
        }

        if ((int) ($payload['code'] ?? -1) !== 0) {
            return [
                'ok' => false,
                'message' => $payload['message'] ?? 'TikTok mengembalikan error.',
                'response' => $payload,
                'request' => [
                    'method' => $method,
                    'path' => $path,
                    'query' => $query,
                    'body' => $body,
                ],
            ];
        }

        $responseData = data_get($payload, 'data', []);
        $returnedProductId = (string) (data_get($responseData, 'product_id') ?? data_get($responseData, 'id') ?? data_get($responseData, 'product.id') ?? '');
        $returnedSkuId = (string) (data_get($responseData, 'sku_id') ?? data_get($responseData, 'skus.0.id') ?? data_get($responseData, 'skus.0.sku_id') ?? '');

        return [
            'ok' => true,
            'message' => $method === 'PUT'
                ? 'Varian TikTok berhasil diperbarui.'
                : 'Varian TikTok berhasil dibuat.',
            'product_id' => $returnedProductId !== '' ? $returnedProductId : ($existingProduct['product_id'] ?? null),
            'sku_id' => $returnedSkuId !== '' ? $returnedSkuId : null,
            'response' => $payload,
            'request' => [
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'body' => $body,
            ],
        ];
    }

    private function uploadTiktokProductImage(object $shop, string $accessToken, string $sourceImageUrl, string $useCase = 'MAIN_IMAGE'): array
    {
        $config = $this->tiktokConfig();
        $path = '/product/202309/images/upload';
        $query = [
            'app_key' => $config['app_key'],
            'timestamp' => time(),
        ];
        $bodyString = null;
        $query['sign'] = $this->generateTiktokSign($path, $query, $config['app_secret'], $bodyString);

        $absolutePath = $this->resolveUploadImagePath($sourceImageUrl);
        if ($absolutePath === null) {
            return [
                'ok' => false,
                'message' => 'Gambar sumber belum bisa dibaca untuk diupload ke TikTok.',
            ];
        }

        $imageStream = @fopen($absolutePath, 'r');
        if (! is_resource($imageStream)) {
            return [
                'ok' => false,
                'message' => 'Gambar sumber belum bisa dibuka untuk diupload ke TikTok.',
            ];
        }

        try {
            $response = Http::timeout(45)
                ->withHeaders([
                    'x-tts-access-token' => $accessToken,
                ])
                ->attach('data', $imageStream, basename($absolutePath))
                ->post($config['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986), [
                    'use_case' => $useCase,
                ]);
        } finally {
            if (is_resource($imageStream)) {
                fclose($imageStream);
            }
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'message' => 'TikTok tidak mengembalikan JSON valid saat upload gambar.',
                'http_status' => $response->status(),
                'response_body' => $response->body(),
                'request' => [
                    'method' => 'POST',
                    'path' => $path,
                    'query' => $query,
                    'use_case' => $useCase,
                    'source_image' => $sourceImageUrl,
                ],
            ];
        }

        if ((int) ($payload['code'] ?? -1) !== 0) {
            return [
                'ok' => false,
                'message' => $payload['message'] ?? 'Gagal upload gambar ke TikTok.',
                'response' => $payload,
                'request' => [
                    'method' => 'POST',
                    'path' => $path,
                    'query' => $query,
                    'use_case' => $useCase,
                    'source_image' => $sourceImageUrl,
                ],
            ];
        }

        $data = data_get($payload, 'data', []);
        $uri = (string) (
            data_get($data, 'uri')
            ?? data_get($data, 'image_uri')
            ?? data_get($data, 'image.0.uri')
            ?? data_get($data, 'images.0.uri')
            ?? data_get($data, 'file.uri')
            ?? ''
        );

        return [
            'ok' => true,
            'message' => 'Gambar berhasil diupload ke TikTok.',
            'uri' => $uri !== '' ? $uri : null,
            'response' => $payload,
            'request' => [
                'method' => 'POST',
                'path' => $path,
                'query' => $query,
                'use_case' => $useCase,
                'source_image' => $sourceImageUrl,
            ],
        ];
    }

    private function updateTiktokInventory(object $shop, string $accessToken, string $productId, string $skuId, string $warehouseId, int $quantity): array
    {
        $config = $this->tiktokConfig();
        $shopCipher = (string) ($shop->cipher ?? $shop->shop_cipher ?? '');

        if ($shopCipher === '') {
            return [
                'ok' => false,
                'message' => 'Shop TikTok belum lengkap untuk update inventory.',
            ];
        }

        $path = '/product/202309/products/'.$productId.'/inventory/update';
        $query = [
            'app_key' => $config['app_key'],
            'access_token' => $accessToken,
            'shop_cipher' => $shopCipher,
            'timestamp' => time(),
        ];
        $query['sign'] = $this->generateTiktokWriteSign($path, $query, $config['app_secret']);

        $body = [
            'skus' => [
                [
                    'id' => $skuId,
                    'inventory' => [
                        [
                            'warehouse_id' => $warehouseId,
                            'quantity' => $quantity,
                        ],
                    ],
                ],
            ],
        ];

        $response = Http::timeout(45)
            ->asJson()
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'content-type' => 'application/json',
            ])
            ->withOptions(['query' => $query])
            ->post($config['api_host'].$path, $body);

        $payload = $response->json();
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'message' => 'TikTok tidak mengembalikan JSON valid saat update inventory.',
                'http_status' => $response->status(),
                'response_body' => $response->body(),
                'request' => [
                    'method' => 'POST',
                    'path' => $path,
                    'query' => $query,
                    'body' => $body,
                ],
            ];
        }

        if ((int) ($payload['code'] ?? -1) !== 0) {
            return [
                'ok' => false,
                'message' => $payload['message'] ?? 'Gagal update inventory TikTok.',
                'response' => $payload,
                'request' => [
                    'method' => 'POST',
                    'path' => $path,
                    'query' => $query,
                    'body' => $body,
                ],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Inventory TikTok berhasil diperbarui.',
            'response' => $payload,
            'request' => [
                'method' => 'POST',
                'path' => $path,
                'query' => $query,
                'body' => $body,
            ],
        ];
    }

    private function buildTiktokCurl(string $method, string $url, array $query, array $headers = [], ?array $body = null): string
    {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $fullUrl = $queryString ? $url.'?'.$queryString : $url;
        $parts = [
            "curl -k -X '".strtoupper($method)."'",
        ];

        foreach ($headers as $name => $value) {
            $parts[] = "-H '".str_replace("'", "'\"'\"'", $name.': '.$value)."'";
        }

        $parts[] = "'".str_replace("'", "'\"'\"'", $fullUrl)."'";

        if ($body !== null && strtoupper($method) !== 'GET') {
            $parts[] = "-d '".str_replace("'", "'\"'\"'", json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."'";
        }

        return implode(' ', $parts);
    }

    private function storeTiktokProductPayload(array $data): void
    {
        $productId = (string) ($data['id'] ?? $data['product_id'] ?? '');
        if ($productId === '') {
            return;
        }

        $productName = (string) ($data['title'] ?? $data['product_name'] ?? 'TikTok Product');
        $imageUrl = $this->cacheMarketplaceImageUrl($this->extractTiktokImageUrl($data), 'tiktok', $productId, 'product');
        $skus = $this->normalizeTiktokSkuList($data);
        $statusInfo = $this->tiktokPayloadStatusInfo($data);
        $deletedSkuIds = $this->tiktokDeletedVariantIdsForProduct($productId);
        $activeSkuKeys = [];

        if (! is_array($skus) || $skus === []) {
            $skus = [[
                'id' => $productId.'-default',
                'sku_name' => 'Default',
                'stock' => data_get($data, 'stock', 0),
                'price' => ['sale_price' => data_get($data, 'price', 0)],
            ]];
        }

        foreach ($skus as $sku) {
            $skuId = (string) ($sku['id'] ?? $sku['sku_id'] ?? $sku['sku_no'] ?? $sku['sku_code'] ?? '');
            if ($skuId !== '' && isset($deletedSkuIds[$this->normalizeSkuMatchValue($skuId)])) {
                continue;
            }

            $skuName = $this->deriveTiktokSkuName($sku);
            $sellerSku = $this->extractTiktokSellerSku($sku);
            $price = (int) data_get($sku, 'price.sale_price', data_get($sku, 'price', 0));
            $stock = (int) data_get($sku, 'inventory.0.quantity', data_get($sku, 'stock', 0));
            $skuImageUrl = $this->cacheMarketplaceImageUrl($this->extractTiktokSkuImageUrl($sku), 'tiktok', $productId, $skuId !== '' ? $skuId : $skuName);
            $skuKey = $skuId !== '' ? $skuId : $skuName;
            $matchAttributes = $skuId !== ''
                ? ['product_id' => $productId, 'sku_id' => $skuId]
                : ['product_id' => $productId, 'sku_name' => $skuName];

            if ($skuKey !== '') {
                $activeSkuKeys[$this->normalizeSkuMatchValue($skuKey)] = true;
            }

            DB::table('tiktok_products')->updateOrInsert(
                $matchAttributes,
                [
                    'product_name' => $productName,
                    'sku_id' => $skuId !== '' ? $skuId : null,
                    'image_url' => $skuImageUrl,
                    'sku_name' => $skuName,
                    'seller_sku' => $sellerSku,
                    'stock_qty' => $stock,
                    'price' => $price,
                    'subtotal' => $price * $stock,
                    'product_status' => $statusInfo['product_status'],
                    'audit_status' => $statusInfo['audit_status'],
                    'is_active' => DB::raw($statusInfo['is_active'] ? 'true' : 'false'),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if ($activeSkuKeys !== []) {
            $existingRows = DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->whereRaw('COALESCE(is_active, true) = true')
                ->select('id', 'sku_id', 'sku_name', 'seller_sku')
                ->get();

            foreach ($existingRows as $existingRow) {
                $existingKey = $this->normalizeSkuMatchValue($existingRow->sku_id ?? '');

                if ($existingKey === '') {
                    $existingKey = $this->normalizeSkuMatchValue($existingRow->sku_name ?? '');
                }

                if ($existingKey === '') {
                    $existingKey = $this->normalizeSkuMatchValue($existingRow->seller_sku ?? '');
                }

                if ($existingKey !== '' && ! isset($activeSkuKeys[$existingKey])) {
                    DB::table('tiktok_products')
                        ->where('id', $existingRow->id)
                        ->update([
                            'is_active' => DB::raw('false'),
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        $this->deactivateDeletedTiktokVariantRows($productId, $deletedSkuIds);
        $this->deactivateDuplicateTiktokProductSkuRows($productId);
    }

    private function activeTiktokVariantCount(string $productId): int
    {
        if ($productId === '' || ! Schema::hasTable('tiktok_products')) {
            return 0;
        }

        return DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->count();
    }

    private function extractTiktokImageUrl(array $data): ?string
    {
        $mainImages = $this->normalizeTiktokImageCandidates(data_get($data, 'main_images', []));

        if (is_array($mainImages)) {
            foreach ($mainImages as $image) {
                $url = $this->extractTiktokImageNodeUrl($image);
                if ($url) {
                    return $url;
                }
            }
        }

        $skus = $this->normalizeTiktokSkuList($data);

        if (is_array($skus)) {
            foreach ($skus as $sku) {
                $skuImage = $this->extractTiktokSkuImageUrl($sku);
                if ($skuImage) {
                    return $skuImage;
                }
            }
        }

        return null;
    }

    private function tiktokPayloadStatusInfo(array $data): array
    {
        $candidates = [
            data_get($data, 'status'),
            data_get($data, 'product_status'),
            data_get($data, 'audit_status'),
            data_get($data, 'display_status'),
            data_get($data, 'status_text'),
            data_get($data, 'listing_status'),
        ];

        $normalized = collect($candidates)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->first();

        if ($normalized === null) {
            $normalized = 'LIVE';
        }

        $inactiveNeedles = ['ARCHIVED', 'BANNED', 'BLOCKED', 'DELETE', 'DELETED', 'DEACTIVATED', 'DRAFT', 'FREEZE', 'FROZEN', 'INACTIVE', 'OUT_OF_STOCK', 'PENDING', 'PLATFORM_DEACTIVATED', 'REJECT', 'REJECTED', 'REMOVED', 'SELLER_DEACTIVATED', 'SOLD_OUT', 'SUSPENDED', 'UNLIST'];
        $activeNeedles = ['LIVE', 'ACTIVE', 'NORMAL', 'PUBLISHED', 'APPROVED'];

        foreach ($inactiveNeedles as $needle) {
            if (str_contains($normalized, $needle)) {
                return [
                    'product_status' => data_get($data, 'product_status', $normalized),
                    'audit_status' => data_get($data, 'audit_status', null),
                    'is_active' => false,
                ];
            }
        }

        foreach ($activeNeedles as $needle) {
            if (str_contains($normalized, $needle)) {
                return [
                    'product_status' => data_get($data, 'product_status', $normalized),
                    'audit_status' => data_get($data, 'audit_status', null),
                    'is_active' => true,
                ];
            }
        }

        return [
            'product_status' => data_get($data, 'product_status', $normalized),
            'audit_status' => data_get($data, 'audit_status', null),
            'is_active' => true,
        ];
    }

    private function extractTiktokSellerSku(array $sku): ?string
    {
        foreach ([
            data_get($sku, 'seller_sku'),
            data_get($sku, 'sku_code'),
            data_get($sku, 'sku_no'),
            data_get($sku, 'sellerSku'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function extractTiktokSkuImageUrl(array $sku): ?string
    {
        $candidates = [
            data_get($sku, 'sku_img'),
            data_get($sku, 'sku_image'),
            data_get($sku, 'sku_image_url'),
            data_get($sku, 'image'),
            data_get($sku, 'image_url'),
            data_get($sku, 'image_urls'),
            data_get($sku, 'images.0'),
            data_get($sku, 'image_list.0'),
            data_get($sku, 'image_list'),
            data_get($sku, 'sales_attributes.0.sku_img'),
            data_get($sku, 'sales_attributes.0.image'),
            data_get($sku, 'sales_attributes.1.sku_img'),
            data_get($sku, 'sales_attributes.1.image'),
            data_get($sku, 'sales_attributes.0.image_url'),
            data_get($sku, 'sales_attributes.1.image_url'),
            data_get($sku, 'sku_img_list'),
            data_get($sku, 'sku_image_list'),
        ];

        $salesAttributes = data_get($sku, 'sales_attributes', []);
        if (is_array($salesAttributes)) {
            foreach ($salesAttributes as $attribute) {
                $candidates[] = data_get($attribute, 'sku_img');
                $candidates[] = data_get($attribute, 'image');
            }
        }

        foreach ($candidates as $candidate) {
            $url = $this->extractTiktokImageNodeUrl($candidate);
            if ($url) {
                return $url;
            }
        }

        return null;
    }

    private function cacheMarketplaceImageUrl(?string $sourceUrl, string $channel, string $scope, string $variant = 'image'): ?string
    {
        if (! is_string($sourceUrl)) {
            return null;
        }

        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $sourceUrl)) {
            return $sourceUrl;
        }

        if (! (bool) config('stb.cache_marketplace_images', true)) {
            return $sourceUrl;
        }

        $cacheDir = storage_path('app/public/marketplace-images/'.$channel);
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $hash = sha1($channel.'|'.$scope.'|'.$variant.'|'.$sourceUrl);
        $extension = $this->guessImageExtensionFromUrl($sourceUrl);
        $fileName = $hash.$extension;
        $absolutePath = $cacheDir.DIRECTORY_SEPARATOR.$fileName;

        if (! is_file($absolutePath)) {
            try {
                $response = Http::timeout(30)
                    ->retry(2, 250)
                    ->accept('image/*')
                    ->get($sourceUrl);

                if ($response->successful()) {
                    $body = $response->body();
                    if (is_string($body) && $body !== '') {
                        $contentType = strtolower((string) $response->header('Content-Type', ''));
                        if ($extension === '' || $extension === '.bin') {
                            $extension = $this->guessImageExtensionFromContentType($contentType);
                            $fileName = $hash.$extension;
                            $absolutePath = $cacheDir.DIRECTORY_SEPARATOR.$fileName;
                        }

                        file_put_contents($absolutePath, $body);
                    }
                }
            } catch (\Throwable) {
                return $sourceUrl;
            }
        }

        if (is_file($absolutePath)) {
            return '/cached-images/marketplace-images/'.$channel.'/'.$fileName;
        }

        return $sourceUrl;
    }

    private function guessImageExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return '.jpg';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($extension) {
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif' => '.'.$extension,
            default => '.jpg',
        };
    }

    private function guessImageExtensionFromContentType(string $contentType): string
    {
        return match (true) {
            str_contains($contentType, 'png') => '.png',
            str_contains($contentType, 'webp') => '.webp',
            str_contains($contentType, 'gif') => '.gif',
            str_contains($contentType, 'bmp') => '.bmp',
            str_contains($contentType, 'avif') => '.avif',
            default => '.jpg',
        };
    }

    private function deriveTiktokSkuName(array $sku): string
    {
        foreach ([
            data_get($sku, 'sku_name'),
            data_get($sku, 'name'),
            data_get($sku, 'sku_title'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $trimmed = trim($candidate);
                if (strtolower($trimmed) !== 'default') {
                    return $trimmed;
                }
            }
        }

        $salesAttributes = data_get($sku, 'sales_attributes', []);
        if (is_array($salesAttributes) && $salesAttributes !== []) {
            $parts = [];

            foreach ($salesAttributes as $attribute) {
                foreach ([
                    data_get($attribute, 'value_name'),
                    data_get($attribute, 'original_value_name'),
                    data_get($attribute, 'name'),
                ] as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $parts[] = trim($candidate);
                        break;
                    }
                }
            }

            $parts = array_values(array_filter(array_unique($parts), fn ($value) => $value !== ''));
            if ($parts !== []) {
                return implode(' / ', $parts);
            }
        }

        $sellerSku = data_get($sku, 'seller_sku');
        if (is_string($sellerSku) && trim($sellerSku) !== '') {
            return trim($sellerSku);
        }

        return 'Default';
    }

    private function normalizeTiktokSkuList(array $data): array
    {
        $candidates = [
            data_get($data, 'skus', []),
            data_get($data, 'sku_list', []),
            data_get($data, 'sku_info_list', []),
            data_get($data, 'sku_infos', []),
            data_get($data, 'skus_info', []),
            data_get($data, 'variants', []),
            data_get($data, 'model_list', []),
            data_get($data, 'product_skus', []),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    private function normalizeTiktokImageCandidates(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        return [];
    }

    private function extractTiktokImageNodeUrl(mixed $image): ?string
    {
        if (is_string($image) && trim($image) !== '') {
            return trim($image);
        }

        if (! is_array($image)) {
            return null;
        }

        foreach (['urls', 'thumb_urls', 'url_list', 'image_url_list', 'image_urls'] as $key) {
            $urls = data_get($image, $key, []);
            if (is_array($urls) && ! empty($urls[0])) {
                return (string) $urls[0];
            }
        }

        foreach (['url', 'uri', 'image_url', 'thumb_url', 'image_id'] as $key) {
            $url = data_get($image, $key);
            if (is_string($url) && trim($url) !== '') {
                $trimmed = trim($url);

                if ($key === 'image_id' && ! $this->isImageUrl($trimmed)) {
                    return $this->resolveTiktokImageId($trimmed);
                }

                return $trimmed;
            }
        }

        return null;
    }

    private function resolveTiktokImageId(string $imageId): ?string
    {
        if ($imageId === '' || $this->isImageUrl($imageId)) {
            return $this->isImageUrl($imageId) ? $imageId : null;
        }

        return 'https://p16-tiktokcdn-com.akamaized.net/obj/'.$imageId;
    }

    private function latestTiktokAccessToken(): string
    {
        return (string) (DB::table('tiktok_tokens')->whereRaw('is_active = true')->orderByDesc('created_at')->value('access_token') ?? DB::table('tiktok_tokens')->orderByDesc('created_at')->value('access_token') ?? '');
    }

    private function activeTiktokAccessTokenForSync(): string
    {
        $account = $this->resolveAccount('tiktok-agnishopbjm', 'tiktok');
        $token = $this->latestActiveTiktokToken($account);

        if (! $token) {
            return $this->latestTiktokAccessToken();
        }

        if ($this->tiktokAccessTokenNeedsRefresh($token) && $this->tiktokRefreshTokenIsUsable($token)) {
            $this->refreshTiktokToken($account);
            $token = $this->latestActiveTiktokToken($account);
        }

        if (! $token || $this->tiktokAccessTokenIsExpired($token)) {
            return '';
        }

        return (string) ($token->access_token ?? '');
    }

    private function ensureTiktokProductTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_products (
                id BIGSERIAL PRIMARY KEY,
                product_id TEXT NOT NULL,
                product_name TEXT NULL,
                image_url TEXT NULL,
                sku_name TEXT NULL,
                seller_sku TEXT NULL,
                stock_qty INTEGER DEFAULT 0,
                price BIGINT DEFAULT 0,
                subtotal BIGINT DEFAULT 0,
                product_status TEXT NULL,
                audit_status TEXT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                updated_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS sku_id TEXT NULL");
        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS seller_sku TEXT NULL");
        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS product_status TEXT NULL");
        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS audit_status TEXT NULL");
        DB::statement("ALTER TABLE tiktok_products ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_sync_logs (
                id BIGSERIAL PRIMARY KEY,
                status TEXT NULL,
                message TEXT NULL,
                product_count INTEGER DEFAULT 0,
                variant_count INTEGER DEFAULT 0,
                synced_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    private function ensureSkuMappingTables(): void
    {
        $this->ensureShopeeProductTables();
        $this->ensureTiktokProductTables();
        $this->ensureSkuVariantActionTables();

        DB::statement("
            CREATE TABLE IF NOT EXISTS sku_mappings (
                id BIGSERIAL PRIMARY KEY,
                stock_master_id BIGINT NOT NULL UNIQUE,
                shopee_item_id TEXT NULL,
                shopee_model_id TEXT NULL,
                tiktok_product_id TEXT NULL,
                tiktok_sku_id TEXT NULL,
                tiktok_sku_name TEXT NULL,
                seller_sku TEXT NULL,
                internal_image_url TEXT NULL,
                shopee_image_url TEXT NULL,
                tiktok_image_url TEXT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS sku_mappings_stock_master_id_idx ON sku_mappings (stock_master_id)");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS shopee_product_id TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS shopee_sku TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS shopee_seller_sku TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS tiktok_product_id TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS tiktok_sku TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS tiktok_seller_sku TEXT NULL");
        DB::statement("ALTER TABLE sku_mappings ADD COLUMN IF NOT EXISTS seller_sku TEXT NULL");
        $this->ensureSkuMappingVisibilityColumns();
    }

    private function syncTiktokProductToDatabase(string $productId): array
    {
        $this->ensureTiktokProductTables();

        try {
            $config = $this->tiktokConfig();
            $shop = $this->latestTiktokShop();
            $accessToken = $this->activeTiktokAccessTokenForSync();

            if (! $shop) {
                throw new \RuntimeException('Belum ada shop TikTok tersimpan. Jalankan AUTH / GET SHOP dulu.');
            }

            if ($accessToken === '') {
                throw new \RuntimeException('Belum ada access token TikTok aktif. Jalankan AUTH / GET TOKEN dulu.');
            }

            $detail = $this->fetchTiktokProductDetail(
                $config,
                $accessToken,
                $shop,
                $productId,
                rtrim((string) $config['api_host'], '/').'/product/202309/products/'
            );

            if (! is_array($detail) || $detail === []) {
                throw new \RuntimeException('Detail produk TikTok tidak berhasil diambil.');
            }

            $this->storeTiktokProductPayload($detail);
            $variantCount = $this->activeTiktokVariantCount($productId);
            $message = 'Produk TikTok dipilih berhasil disinkronkan: '.$variantCount.' varian aktif.';

            DB::table('tiktok_sync_logs')->insert([
                'status' => 'ok',
                'message' => $message,
                'product_count' => 1,
                'variant_count' => $variantCount,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'ok',
                'message' => $message,
                'products' => 1,
                'variants' => $variantCount,
                'mode' => 'product',
                'product_id' => $productId,
                'last_sync_at' => now()->toDateTimeString(),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => $exception->getMessage(),
                'products' => 0,
                'variants' => 0,
                'mode' => 'product',
                'product_id' => $productId,
            ];
        }
    }

    private function ensureSkuMappingVisibilityColumns(): void
    {
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS is_hidden_from_mapping BOOLEAN DEFAULT FALSE");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS hidden_from_mapping_reason TEXT NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS hidden_from_mapping_at TIMESTAMP NULL");
        DB::statement("ALTER TABLE stock_master ADD COLUMN IF NOT EXISTS hidden_from_mapping_by VARCHAR(255) NULL");
    }

    private function ensureSkuVariantActionTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS sku_variant_actions (
                id BIGSERIAL PRIMARY KEY,
                stock_master_id BIGINT NOT NULL,
                target_channel TEXT NOT NULL,
                source_channel TEXT NULL,
                action_type TEXT NOT NULL,
                payload JSONB NULL,
                status TEXT NOT NULL DEFAULT 'ready_to_create',
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS sku_variant_actions_stock_master_id_idx ON sku_variant_actions (stock_master_id)");
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS sku_variant_actions_unique_idx ON sku_variant_actions (stock_master_id, target_channel, action_type)");
    }

    public function skuMapping(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();
        $autoHiddenCount = $this->autoHideInactiveStockMasterMappings();

        $flow = (string) $request->query('flow', '');
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'updated_desc');
        $perPage = max(1, min(5000, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $compact = $request->boolean('compact');

        $query = DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join) {
                $join->on('spm.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin('shopee_product as sp', function ($join) {
                $join->on('sp.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->whereRaw('COALESCE(sp.is_active, true) = true');
            })
            ->leftJoin(DB::raw('(SELECT item_id, model_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NOT NULL GROUP BY item_id, model_id) as spmi'), function ($join) {
                $join->on('spmi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->on('spmi.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NULL GROUP BY item_id) as spi'), function ($join) {
                $join->on('spi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->leftJoin(DB::raw("(
                SELECT DISTINCT ON (stock_master_id)
                    stock_master_id,
                    target_channel,
                    source_channel,
                    action_type,
                    status AS variant_action_status,
                    payload AS variant_action_payload,
                    created_at,
                    updated_at
                FROM sku_variant_actions
                ORDER BY stock_master_id, created_at DESC, id DESC
            ) as sva"), function ($join) {
                $join->on('sva.stock_master_id', '=', 'sm.id');
            })
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->select(
            'sm.id',
            'sm.internal_sku',
            'sm.shopee_product_id as stock_shopee_item_id',
            'sm.shopee_sku as stock_shopee_model_id',
            'sm.shopee_seller_sku as stock_shopee_seller_sku',
            'sm.product_name',
            'sm.variant_name',
            'sm.stock_qty',
            'sm.tiktok_product_id as stock_tiktok_product_id',
            'sm.tiktok_sku as stock_tiktok_sku_id',
            'sm.tiktok_seller_sku as stock_tiktok_seller_sku',
            'sm.updated_at',
            'map.id as mapping_id',
            'map.shopee_item_id',
            'map.shopee_model_id',
            'map.tiktok_product_id as mapped_tiktok_product_id',
            'map.tiktok_sku_id',
            'map.tiktok_sku_name',
            'map.seller_sku as mapped_seller_sku',
            'map.internal_image_url',
            'map.shopee_image_url',
            'map.tiktok_image_url',
            'map.notes',
            'sva.target_channel as variant_action_target_channel',
            'sva.source_channel as variant_action_source_channel',
            'sva.action_type as variant_action_type',
            'sva.variant_action_status',
            'sva.variant_action_payload',
            'sp.name as shopee_name',
            'sp.is_active as shopee_is_active',
            'spm.name as shopee_variant_name',
            'spm.model_sku as shopee_model_sku',
            'spm.price as shopee_variant_price',
            'spm.original_price as shopee_variant_original_price',
            'spm.stock as shopee_variant_stock',
            'sp.status as shopee_status',
            'spmi.image_url as shopee_model_image_url',
            'spi.image_url as shopee_product_image_url'
        );

        $rows = $query->get();
        $shopeeProductGroups = $this->shopeeProductGroupsForSkuMapping();
        $stockGroupShopeeMatches = $this->suggestShopeeProductsForStockGroups($rows, $shopeeProductGroups);
        $tiktokLookup = $this->tiktokSkuMappingLookup();
        $tiktokProductGroups = $tiktokLookup['product_groups'];
        $stockGroupTiktokMatches = $this->suggestTiktokProductsForStockGroups($rows, $tiktokProductGroups);
        $matchedTiktokToStockGroup = [];
        foreach ($stockGroupTiktokMatches as $stockGroupKey => $productId) {
            if ($productId !== null && $productId !== '' && ! isset($matchedTiktokToStockGroup[$productId])) {
                $matchedTiktokToStockGroup[$productId] = $stockGroupKey;
            }
        }
        $matchedTiktokVariantKeys = [];
        $items = [];
        $resolveItemStatus = static function (bool $hasShopeeActual, bool $hasTiktokActual): string {
            if ($hasShopeeActual && $hasTiktokActual) {
                return 'ready_to_sync';
            }

            if ($hasShopeeActual) {
                return 'tiktok_missing';
            }

            if ($hasTiktokActual) {
                return 'shopee_missing';
            }

            return 'belum_ada_variant';
        };

        foreach ($rows as $row) {
            $stockGroupKey = $this->stockMappingGroupKey($row);
            $matchedShopeeItemId = $stockGroupShopeeMatches[$stockGroupKey] ?? null;
            $matchedShopeeProduct = $matchedShopeeItemId ? ($shopeeProductGroups[$matchedShopeeItemId] ?? null) : null;
            $savedShopeeItemId = $row->shopee_item_id ?: $row->stock_shopee_item_id;
            $shopeeItemId = $savedShopeeItemId ?: $matchedShopeeItemId;
            $shopeeModelId = $row->shopee_model_id ?: $row->stock_shopee_model_id;
            $shopeeStatus = trim((string) ($row->shopee_status ?? ''));
            $shopeeStatus = $shopeeStatus !== '' ? $shopeeStatus : (string) ($matchedShopeeProduct['status'] ?? '');
            $shopeeActive = $row->shopee_is_active === null ? true : (bool) $row->shopee_is_active;
            $shopeeIsLive = $shopeeActive && $this->isLiveShopeeStatus($shopeeStatus);
            $stockVariantKey = $this->normalizeSkuMatchValue($row->variant_name ?? '');
            $matchedShopeeVariant = $matchedShopeeProduct && $stockVariantKey !== ''
                ? ($matchedShopeeProduct['variants_by_name'][$stockVariantKey] ?? null)
                : null;
            $savedShopeeVariantKey = $this->normalizeSkuMatchValue($row->shopee_variant_name ?? '');
            $hasSavedShopeeVariantMismatch = $stockVariantKey !== ''
                && $savedShopeeVariantKey !== ''
                && $stockVariantKey !== $savedShopeeVariantKey;
            if ($hasSavedShopeeVariantMismatch && $matchedShopeeVariant) {
                $shopeeModelId = $matchedShopeeVariant['model_id'] ?? null;
            } elseif ($hasSavedShopeeVariantMismatch) {
                $shopeeModelId = null;
            } elseif (! $this->filledString($shopeeModelId) && $matchedShopeeVariant) {
                $shopeeModelId = $matchedShopeeVariant['model_id'] ?? null;
            }
            $shopeeModelSku = $hasSavedShopeeVariantMismatch
                ? ($matchedShopeeVariant['model_sku'] ?? null)
                : ($row->shopee_model_sku ?: ($matchedShopeeVariant['model_sku'] ?? null));
            $shopeeMatchSource = $this->filledString($savedShopeeItemId) || $this->filledString($shopeeModelId)
                ? ($matchedShopeeVariant && ! $this->filledString($savedShopeeItemId) ? 'suggested_variant' : 'saved')
                : ($matchedShopeeProduct ? 'suggested_product' : null);
            $hasShopeeVariantIdentity = $shopeeActive && $shopeeMatchSource !== 'suggested_product' && (
                $this->filledString($shopeeModelId)
                || (! $hasSavedShopeeVariantMismatch && $this->filledString($row->shopee_variant_name ?? null))
                || $this->filledString($shopeeModelSku)
                || (! $hasSavedShopeeVariantMismatch && $this->filledString($row->stock_shopee_seller_sku ?? null))
                || $matchedShopeeVariant !== null
            );
            $shopeeVariantName = $hasShopeeVariantIdentity
                ? ($hasSavedShopeeVariantMismatch ? ($matchedShopeeVariant['name'] ?? $row->variant_name) : ($row->shopee_variant_name ?: ($matchedShopeeVariant['name'] ?? $row->variant_name)))
                : null;
            $shopeeSellerSku = $hasShopeeVariantIdentity
                ? trim((string) ($shopeeModelSku ?? ''))
                : '';
            $templateSellerSku = $this->bestSkuMappingSkuValue([
                $row->mapped_seller_sku ?? null,
                $row->internal_sku ?? null,
                $row->stock_shopee_seller_sku ?? null,
                $row->stock_tiktok_seller_sku ?? null,
            ]);
            $matchedProductId = $stockGroupTiktokMatches[$stockGroupKey] ?? null;
            $canonicalGroupKey = $matchedProductId && isset($matchedTiktokToStockGroup[$matchedProductId])
                ? $matchedTiktokToStockGroup[$matchedProductId]
                : $stockGroupKey;

            [$tiktokMatch, $tiktokMatchSource] = $this->resolveSkuMappingTiktokMatch(
                $row,
                $tiktokLookup,
                $matchedProductId,
                $shopeeVariantName,
                $shopeeModelSku
            );

            $tiktokProductId = $row->mapped_tiktok_product_id ?: $row->stock_tiktok_product_id;
            $tiktokSkuId = $row->tiktok_sku_id ?: $row->stock_tiktok_sku_id;
            $tiktokSkuName = $row->tiktok_sku_name ?: null;
            $tiktokSellerSku = null;
            $variantActionStatus = trim((string) ($row->variant_action_status ?? ''));
            $hasShopeeActual = $hasShopeeVariantIdentity;
            $hasTiktokVariantIdentity = $tiktokMatch !== null && (
                $this->filledString($tiktokMatch->sku_id ?? null)
                || $this->normalizeSkuMatchValue($tiktokMatch->sku_name ?? '') !== ''
                || $this->normalizeSkuMatchValue($tiktokMatch->seller_sku ?? '') !== ''
            );
            $hasTiktokActual = $tiktokMatchSource === 'suggested_product'
                ? false
                : $hasTiktokVariantIdentity;

            if ($hasTiktokActual) {
                $tiktokProductId = $tiktokMatch->product_id ?? $tiktokProductId;
                $tiktokSkuId = $tiktokMatch->sku_id ?? $tiktokSkuId;
                $tiktokSkuName = $tiktokMatch->sku_name ?? $tiktokSkuName;
                $tiktokSellerSku = trim((string) ($tiktokMatch->seller_sku ?? '')) ?: null;
            }

            $shopeeImageUrl = $row->shopee_model_image_url
                ?: $row->internal_image_url
                ?: $row->shopee_image_url
                ?: $row->shopee_product_image_url
                ?: ($matchedShopeeProduct['image_url'] ?? null);
            $tiktokImageUrl = $row->tiktok_image_url
                ?: ($tiktokMatch->image_url ?? null)
                ?: ($tiktokMatch->product_image_url ?? null);

            if ($tiktokMatch) {
                foreach ($this->tiktokVariantMatchKeys($tiktokMatch) as $matchKey) {
                    $matchedTiktokVariantKeys[$matchKey] = true;
                }
            }

            $items[] = [
                'id' => $row->id,
                'group_key' => $canonicalGroupKey,
                'stock_master_id' => $row->id,
                'internal_sku' => $row->internal_sku,
                'template_sku' => $templateSellerSku,
                'product_name' => $row->product_name,
                'variant_name' => $row->variant_name,
                'stock_qty' => (int) ($row->stock_qty ?? 0),
                'image_url' => $shopeeImageUrl ?: $tiktokImageUrl ?: $row->shopee_product_image_url,
                'mapping_id' => $row->mapping_id,
                'seller_sku' => $templateSellerSku ?: ($shopeeSellerSku ?: $tiktokSellerSku),
                'variant_action_status' => $variantActionStatus !== '' ? $variantActionStatus : null,
                'variant_action_target_channel' => $row->variant_action_target_channel ?? null,
                'shopee' => [
                    'item_id' => $shopeeItemId,
                    'model_id' => $shopeeModelId,
                    'status' => $shopeeStatus !== '' ? $shopeeStatus : null,
                    'is_active' => $shopeeActive,
                    'is_live' => $shopeeIsLive,
                    'product_name' => $row->shopee_name ?: ($matchedShopeeProduct['product_name'] ?? $row->product_name),
                    'variant_name' => $shopeeVariantName,
                    'seller_sku' => $shopeeSellerSku,
                    'template_sku' => $templateSellerSku,
                    'price' => $hasShopeeActual ? (isset($row->shopee_variant_price) ? (int) $row->shopee_variant_price : ($matchedShopeeVariant['price'] ?? null)) : null,
                    'original_price' => $hasShopeeActual ? (isset($row->shopee_variant_original_price) ? (int) $row->shopee_variant_original_price : ($matchedShopeeVariant['original_price'] ?? null)) : null,
                    'product_prices' => $matchedShopeeProduct['prices'] ?? [],
                    'product_original_prices' => $matchedShopeeProduct['original_prices'] ?? [],
                    'stock_qty' => $hasShopeeActual ? (int) ($row->shopee_variant_stock ?? $row->stock_qty ?? 0) : null,
                    'image_url' => $hasShopeeActual ? $shopeeImageUrl : ($matchedShopeeProduct['image_url'] ?? null),
                    'status' => $hasShopeeActual ? 'mapped' : 'unmapped',
                    'source' => $shopeeMatchSource,
                ],
                'shopee_variant_price' => isset($row->shopee_variant_price) ? (int) $row->shopee_variant_price : null,
                'tiktok' => [
                    'product_id' => $tiktokProductId ?: ($tiktokMatch->product_id ?? null),
                    'sku_id' => $tiktokSkuId ?: ($tiktokMatch->sku_id ?? null),
                    'sku_name' => $tiktokSkuName ?: ($tiktokMatch->sku_name ?? null),
                    'seller_sku' => $tiktokSellerSku ?: ($tiktokMatch->seller_sku ?? null),
                    'price' => $hasTiktokVariantIdentity && isset($tiktokMatch->price) ? (int) $tiktokMatch->price : null,
                    'product_name' => $tiktokMatch->product_name ?? null,
                    'variant_name' => $tiktokMatch->sku_name ?? $tiktokSkuName,
                    'stock_qty' => $hasTiktokVariantIdentity ? (int) ($tiktokMatch->stock_qty ?? 0) : null,
                    'image_url' => $tiktokImageUrl,
                    'status' => $hasTiktokActual ? 'mapped' : ($tiktokMatch ? 'suggested' : 'unmapped'),
                    'source' => $tiktokMatchSource,
                    'template_sku' => $templateSellerSku,
                ],
                'status' => $resolveItemStatus($hasShopeeActual, $hasTiktokActual),
                'updated_at' => $row->updated_at,
            ];
        }

        $tiktokRows = $tiktokLookup['rows_by_product_id'] ?? DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('product_id', 'product_name', 'image_url', 'sku_id', 'sku_name', 'seller_sku', 'stock_qty', 'price', 'subtotal', 'updated_at')
            ->orderBy('product_name')
            ->orderBy('sku_name')
            ->get()
            ->groupBy('product_id');

        foreach ($tiktokRows as $productId => $group) {
            $canonicalGroupKey = isset($matchedTiktokToStockGroup[$productId])
                ? $matchedTiktokToStockGroup[$productId]
                : 'tiktok:'.$productId;
            $groupFirst = $group->first();
            $productImageUrl = $group->firstWhere('image_url')?->image_url ?? null;
            $suggestedShopeeItemId = str_starts_with((string) $canonicalGroupKey, 'shopee:')
                ? substr((string) $canonicalGroupKey, strlen('shopee:'))
                : ($stockGroupShopeeMatches[$canonicalGroupKey] ?? null);
            $suggestedShopeeProduct = $suggestedShopeeItemId
                ? ($shopeeProductGroups[$suggestedShopeeItemId] ?? null)
                : null;

            foreach ($group as $skuRow) {
                if ($this->hasMatchedTiktokVariant($matchedTiktokVariantKeys, $skuRow)) {
                    continue;
                }

                $skuVariantKey = $this->normalizeSkuMatchValue($skuRow->sku_name ?? '');
                if ($suggestedShopeeProduct && $skuVariantKey !== '' && isset($suggestedShopeeProduct['variants_by_name'][$skuVariantKey])) {
                    continue;
                }

                $items[] = [
                    'id' => 'tiktok-'.$skuRow->product_id.'-'.($skuRow->sku_id ?: $this->normalizeSkuMatchValue($skuRow->sku_name ?? '')),
                    'group_key' => $canonicalGroupKey,
                    'stock_master_id' => null,
                    'internal_sku' => null,
                    'product_name' => $groupFirst->product_name ?? null,
                    'variant_name' => $skuRow->sku_name,
                    'stock_qty' => (int) ($skuRow->stock_qty ?? 0),
                    'image_url' => $skuRow->image_url ?: $productImageUrl,
                    'mapping_id' => null,
                    'shopee' => [
                        'item_id' => $suggestedShopeeItemId,
                        'model_id' => null,
                        'product_name' => $suggestedShopeeProduct['product_name'] ?? null,
                        'variant_name' => null,
                        'price' => null,
                        'original_price' => null,
                        'product_prices' => $suggestedShopeeProduct['prices'] ?? [],
                        'product_original_prices' => $suggestedShopeeProduct['original_prices'] ?? [],
                        'stock_qty' => null,
                        'image_url' => $suggestedShopeeProduct['image_url'] ?? null,
                        'status' => 'unmapped',
                        'source' => $suggestedShopeeProduct ? 'suggested_product' : null,
                    ],
                    'tiktok' => [
                        'product_id' => $skuRow->product_id,
                        'sku_id' => $skuRow->sku_id ?? null,
                        'sku_name' => $skuRow->sku_name,
                        'seller_sku' => $skuRow->seller_sku ?? null,
                        'product_name' => $skuRow->product_name,
                        'variant_name' => $skuRow->sku_name,
                        'price' => (int) ($skuRow->price ?? 0),
                        'stock_qty' => (int) ($skuRow->stock_qty ?? 0),
                        'image_url' => $skuRow->image_url ?: $productImageUrl,
                        'status' => 'mapped',
                        'source' => 'actual',
                    ],
                    'status' => $resolveItemStatus(false, true),
                    'updated_at' => $skuRow->updated_at,
                ];
            }
        }

        $items = $this->deduplicateSkuMappingItems($items);

        $items = collect($items)
            ->filter(function (array $item) use ($search, $status, $flow) {
                if ($this->isSkuMappingSoldOutItem($item)) {
                    return false;
                }

                $matchesStatus = match ($status) {
                    'ready_to_sync', 'shopee_missing', 'tiktok_missing', 'belum_ada_variant' => $item['status'] === $status,
                    'tiktok_actual' => data_get($item, 'tiktok.status') === 'mapped'
                        && data_get($item, 'tiktok.source') !== 'suggested_product',
                    'all' => match ($flow) {
                        'shopee-to-tiktok' => in_array($item['status'], ['ready_to_sync', 'tiktok_missing', 'shopee_missing'], true),
                        'tiktok-to-shopee' => in_array($item['status'], ['ready_to_sync', 'shopee_missing'], true),
                        default => true,
                    },
                    default => true,
                };

                if (! $matchesStatus) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                $haystack = implode(' ', array_filter([
                    $item['internal_sku'] ?? '',
                    $item['product_name'] ?? '',
                    $item['variant_name'] ?? '',
                    $item['shopee']['item_id'] ?? '',
                    $item['shopee']['model_id'] ?? '',
                    $item['tiktok']['product_id'] ?? '',
                    $item['tiktok']['sku_id'] ?? '',
                    $item['tiktok']['sku_name'] ?? '',
                    $item['tiktok']['product_name'] ?? '',
                ], fn ($value) => trim((string) $value) !== ''));

                return str_contains(strtolower($haystack), strtolower($search));
            })
            ->sort(function (array $a, array $b) use ($sort) {
                return match ($sort) {
                    'name_asc' => strcmp(
                        strtolower((string) ($a['product_name'] ?? '')),
                        strtolower((string) ($b['product_name'] ?? ''))
                    ),
                    'created_desc' => strcmp(
                        (string) ($b['updated_at'] ?? ''),
                        (string) ($a['updated_at'] ?? '')
                    ),
                    default => strcmp(
                        (string) ($b['updated_at'] ?? ''),
                        (string) ($a['updated_at'] ?? '')
                    ),
                };
            })
            ->values();

        $total = $items->count();
        $pagedItems = $items->slice(($page - 1) * $perPage, $perPage)->values();
        if ($compact) {
            $pagedItems = $pagedItems->map(function (array $item): array {
                unset($item['shopee']['product_prices'], $item['shopee']['product_original_prices']);

                return $item;
            });
        }

        return response()->json([
            'summary' => [
                'total' => DB::table('stock_master')->count(),
                'mapped' => DB::table('sku_mappings')->count(),
                'auto_hidden_inactive_stock_master' => $autoHiddenCount,
                'last_shopee_sync_at' => DB::table('shopee_sync_logs')->max('synced_at'),
                'last_tiktok_sync_at' => DB::table('tiktok_sync_logs')->max('synced_at'),
            ],
            'items' => $pagedItems,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function syncMarketplaceCaches(Request $request): JsonResponse
    {
        if ((bool) config('stb.sync_worker', false)) {
            abort(423, 'Full marketplace sync dari dashboard dibatasi di mode STB sync worker. Gunakan php artisan agnishop:sync-marketplace-lite atau matikan STB_SYNC_WORKER.');
        }

        return response()->json($this->syncMarketplaceCachesForSkuMapping());
    }

    public function syncMarketplaceCachesForSkuMapping(): array
    {
        $this->ensureSkuMappingTables();
        $this->autoRefreshMarketplaceTokens();

        $shopee = $this->syncShopeeProductsToDatabase();
        $tiktok = $this->syncTiktokProductsToDatabase();
        $autoHiddenCount = $this->autoHideInactiveStockMasterMappings();

        $hasError = collect([$shopee, $tiktok])->contains(fn ($result) => ($result['status'] ?? '') === 'error');
        $hasPartial = collect([$shopee, $tiktok])->contains(fn ($result) => ($result['status'] ?? '') === 'partial');

        return [
            'status' => $hasError ? 'partial_error' : ($hasPartial ? 'partial' : 'ok'),
            'message' => 'Sync marketplace selesai.',
            'shopee' => $shopee,
            'tiktok' => $tiktok,
            'auto_hidden_inactive_stock_master' => $autoHiddenCount,
            'last_shopee_sync_at' => DB::table('shopee_sync_logs')->max('synced_at'),
            'last_tiktok_sync_at' => DB::table('tiktok_sync_logs')->max('synced_at'),
        ];
    }

    public function syncMarketplaceProductCachesForOrder(array $refs, int $tiktokDelaySeconds = 0): array
    {
        $this->autoRefreshMarketplaceTokens();

        $shopeeItemIds = collect($refs['shopee_item_ids'] ?? [])
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values();
        $tiktokProductIds = collect($refs['tiktok_product_ids'] ?? [])
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        $results = [
            'shopee' => [],
            'tiktok' => [],
        ];

        foreach ($shopeeItemIds as $itemId) {
            $results['shopee'][] = $this->syncShopeeProductToDatabase((int) $itemId);
        }

        if ($tiktokProductIds->isNotEmpty() && $tiktokDelaySeconds > 0) {
            sleep(min(300, max(0, $tiktokDelaySeconds)));
        }

        foreach ($tiktokProductIds as $productId) {
            $results['tiktok'][] = $this->syncTiktokProductToDatabase((string) $productId);
        }

        $flatResults = collect($results['shopee'])->merge($results['tiktok']);
        $hasError = $flatResults->contains(fn ($result): bool => ($result['status'] ?? '') === 'error');

        return [
            'status' => $hasError ? 'warning' : 'success',
            'message' => sprintf(
                'Refresh cache produk marketplace selesai. Shopee=%s TikTok=%s.',
                $shopeeItemIds->count(),
                $tiktokProductIds->count()
            ),
            'shopee_item_ids' => $shopeeItemIds->all(),
            'tiktok_product_ids' => $tiktokProductIds->all(),
            'results' => $results,
        ];
    }

    private function autoHideInactiveStockMasterMappings(): int
    {
        if (! Schema::hasTable('stock_master')) {
            return 0;
        }

        $this->ensureSkuMappingVisibilityColumns();

        return DB::table('stock_master as sm')
            ->whereRaw('COALESCE(sm.is_hidden_from_mapping, false) = false')
            ->where(function ($query) {
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
                'hidden_from_mapping_reason' => 'Auto-hide: varian marketplace aktif tidak ditemukan saat SKU Mapping dibuka/sync.',
                'hidden_from_mapping_at' => now(),
                'hidden_from_mapping_by' => 'system',
                'updated_at' => now(),
            ]);
    }

    private function deduplicateSkuMappingItems(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $key = $this->skuMappingItemIdentityKey($item);
            if ($key === '') {
                $merged[] = $item;
                continue;
            }

            if (! isset($merged[$key])) {
                $merged[$key] = $item;
                continue;
            }

            $merged[$key] = $this->mergeSkuMappingItems($merged[$key], $item);
        }

        return array_values($merged);
    }

    private function skuMappingItemIdentityKey(array $item): string
    {
        $shopeeItemId = trim((string) data_get($item, 'shopee.item_id', ''));
        $shopeeModelId = trim((string) data_get($item, 'shopee.model_id', ''));
        if ($shopeeItemId !== '' && $shopeeModelId !== '') {
            return 'shopee:'.$shopeeItemId.'|'.$shopeeModelId;
        }

        $tiktokProductId = trim((string) data_get($item, 'tiktok.product_id', ''));
        $tiktokSkuId = trim((string) data_get($item, 'tiktok.sku_id', ''));
        if ($tiktokProductId !== '' && $tiktokSkuId !== '') {
            return 'tiktok:'.$tiktokProductId.'|'.$tiktokSkuId;
        }

        $groupKey = trim((string) ($item['group_key'] ?? ''));
        $variantKey = $this->normalizeSkuMatchValue($item['variant_name'] ?? '');

        return $groupKey !== '' && $variantKey !== '' ? 'variant:'.$groupKey.'|'.$variantKey : '';
    }

    private function mergeSkuMappingItems(array $left, array $right): array
    {
        $base = $this->skuMappingItemScore($right) > $this->skuMappingItemScore($left) ? $right : $left;
        $other = $base === $right ? $left : $right;

        $merged = $this->mergeSkuMappingArrayValues($base, $other);
        $merged['shopee'] = $this->mergeSkuMappingArrayValues($base['shopee'] ?? [], $other['shopee'] ?? []);
        $merged['tiktok'] = $this->mergeSkuMappingArrayValues($base['tiktok'] ?? [], $other['tiktok'] ?? []);

        $merged['internal_sku'] = $this->bestSkuMappingSkuValue([
            $this->sameSkuMappingSku(data_get($merged, 'shopee.seller_sku'), data_get($merged, 'tiktok.seller_sku'))
                ? data_get($merged, 'shopee.seller_sku')
                : null,
            $left['internal_sku'] ?? null,
            $right['internal_sku'] ?? null,
            data_get($left, 'shopee.seller_sku'),
            data_get($right, 'shopee.seller_sku'),
            data_get($left, 'tiktok.seller_sku'),
            data_get($right, 'tiktok.seller_sku'),
        ]) ?: ($merged['internal_sku'] ?? null);

        $merged['seller_sku'] = $this->bestSkuMappingSkuValue([
            $this->sameSkuMappingSku(data_get($merged, 'shopee.seller_sku'), data_get($merged, 'tiktok.seller_sku'))
                ? data_get($merged, 'shopee.seller_sku')
                : null,
            $merged['seller_sku'] ?? null,
            data_get($merged, 'shopee.seller_sku'),
            data_get($merged, 'tiktok.seller_sku'),
            $merged['internal_sku'] ?? null,
        ]);

        $hasShopeeActual = ($merged['shopee']['status'] ?? null) === 'mapped'
            && ($merged['shopee']['source'] ?? null) !== 'suggested_product';
        $hasTiktokActual = ($merged['tiktok']['status'] ?? null) === 'mapped'
            && ($merged['tiktok']['source'] ?? null) !== 'suggested_product';
        $merged['status'] = $hasShopeeActual && $hasTiktokActual
            ? 'ready_to_sync'
            : ($hasShopeeActual ? 'tiktok_missing' : ($hasTiktokActual ? 'shopee_missing' : 'belum_ada_variant'));

        return $merged;
    }

    private function mergeSkuMappingArrayValues(array $base, array $other): array
    {
        $merged = $base;

        foreach ($other as $key => $value) {
            if (! array_key_exists($key, $merged) || ! $this->filledSkuMappingValue($merged[$key])) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function skuMappingItemScore(array $item): int
    {
        $score = 0;
        foreach ([
            $item['mapping_id'] ?? null,
            data_get($item, 'shopee.seller_sku'),
            data_get($item, 'tiktok.seller_sku'),
            data_get($item, 'shopee.item_id'),
            data_get($item, 'shopee.model_id'),
            data_get($item, 'tiktok.product_id'),
            data_get($item, 'tiktok.sku_id'),
        ] as $value) {
            if ($this->filledSkuMappingValue($value)) {
                $score += 10;
            }
        }

        $score += max(0, 20 - $this->skuMappingSkuPenalty($item['internal_sku'] ?? null));

        return $score;
    }

    private function bestSkuMappingSkuValue(array $values): ?string
    {
        $best = null;
        $bestPenalty = PHP_INT_MAX;

        foreach ($values as $value) {
            $sku = trim((string) ($value ?? ''));
            if ($sku === '') {
                continue;
            }

            $penalty = $this->skuMappingSkuPenalty($sku);
            if ($best === null || $penalty < $bestPenalty || ($penalty === $bestPenalty && strlen($sku) < strlen($best))) {
                $best = $sku;
                $bestPenalty = $penalty;
            }
        }

        return $best;
    }

    private function skuMappingSkuPenalty(mixed $value): int
    {
        $sku = trim((string) ($value ?? ''));
        if ($sku === '') {
            return 1000;
        }

        $penalty = substr_count(strtoupper($sku), 'INT-') > 1 ? 100 : 0;

        if (preg_match('/^INT-(\d+)-INT-\1-/i', $sku) === 1) {
            $penalty += 100;
        }

        return $penalty + min(strlen($sku), 200);
    }

    private function sameSkuMappingSku(mixed $left, mixed $right): bool
    {
        $left = trim((string) ($left ?? ''));
        $right = trim((string) ($right ?? ''));

        return $left !== '' && $right !== '' && strcasecmp($left, $right) === 0;
    }

    private function filledSkuMappingValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        return trim((string) ($value ?? '')) !== '';
    }

    private function isSkuMappingSoldOutItem(array $item): bool
    {
        $shopeeItemId = trim((string) data_get($item, 'shopee.item_id', ''));
        $shopeeStatus = strtoupper(trim((string) data_get($item, 'shopee.status', '')));
        $hasShopeeActual = $shopeeItemId !== '' && (data_get($item, 'shopee.source') !== 'suggested_product');

        if ($hasShopeeActual && ! (bool) data_get($item, 'shopee.is_live', false)) {
            return true;
        }

        foreach ([$shopeeStatus, strtoupper(trim((string) data_get($item, 'tiktok.product_status', '')))] as $status) {
            if ($status !== '' && preg_match('/SOLD[ _-]?OUT|OUT[ _-]?OF[ _-]?STOCK|UNLIST|DELETED|REMOVED|DEACTIVATED|ARCHIVED|BANNED|BLOCKED|SUSPENDED|FROZEN|FREEZE|DRAFT|REJECT|REJECTED/', $status) === 1) {
                return true;
            }
        }

        return false;
    }

    private function tiktokSkuMappingLookup(): array
    {
        $empty = [
            'by_sku_id' => [],
            'by_seller_sku' => [],
            'by_product_seller_sku' => [],
            'by_product_sku_id' => [],
            'by_product_sku_name' => [],
            'by_product_variant_name' => [],
            'by_variant_name' => [],
            'product_groups' => [],
            'rows_by_product_id' => [],
        ];

        if (! Schema::hasTable('tiktok_products')) {
            return $empty;
        }

        $rows = DB::table('tiktok_products')
            ->whereRaw('COALESCE(is_active, true) = true')
            ->select('product_id', 'product_name', 'image_url', 'sku_id', 'sku_name', 'seller_sku', 'stock_qty', 'price', 'updated_at')
            ->get();

        if ($rows->isEmpty()) {
            return $empty;
        }

        $bySkuId = [];
        $bySellerSku = [];
        $byProductSellerSku = [];
        $byProductSkuId = [];
        $byProductSkuName = [];
        $byProductVariantName = [];
        $byVariantName = [];
        $productGroups = [];
        $rowsByProductId = $rows->groupBy('product_id');

        foreach ($rowsByProductId as $productId => $group) {
            $first = $group->first();
            $productImageUrl = null;
            $skuNames = [];
            $sellerSkus = [];
            $rowsBySkuName = [];
            $rowsBySellerSku = [];

            foreach ($group as $skuRow) {
                if (! $this->filledString($productImageUrl) && $this->filledString($skuRow->image_url ?? null)) {
                    $productImageUrl = $skuRow->image_url;
                }
            }

            foreach ($group as $skuRow) {
                $skuRow->product_image_url = $productImageUrl;
                $skuNameKey = $this->normalizeSkuMatchValue($skuRow->sku_name ?? '');
                $sellerSkuKey = $this->normalizeSkuMatchValue($skuRow->seller_sku ?? '');

                if ($skuNameKey !== '') {
                    $skuNames[$skuNameKey] = true;
                    $rowsBySkuName[$skuNameKey] ??= $skuRow;
                }

                if ($sellerSkuKey !== '') {
                    $sellerSkus[$sellerSkuKey] = true;
                    $rowsBySellerSku[$sellerSkuKey] ??= $skuRow;
                }
            }

            $productGroups[(string) $productId] = [
                'product_id' => (string) $productId,
                'product_name' => $first->product_name ?? '',
                'product_name_key' => $this->normalizeSkuMatchValue($first->product_name ?? ''),
                'tokens' => $this->skuMappingNameTokens($first->product_name ?? ''),
                'sku_names' => array_keys($skuNames),
                'seller_skus' => array_keys($sellerSkus),
                'rows_by_sku_name' => $rowsBySkuName,
                'rows_by_seller_sku' => $rowsBySellerSku,
            ];
        }

        $setFirst = function (array &$index, string $key, object $row): void {
            if ($key !== '' && ! isset($index[$key])) {
                $index[$key] = $row;
            }
        };

        foreach ($rows as $row) {
            $productId = trim((string) ($row->product_id ?? ''));
            $skuId = trim((string) ($row->sku_id ?? ''));
            $skuNameKey = $this->normalizeSkuMatchValue($row->sku_name ?? '');
            $sellerSkuKey = $this->normalizeSkuMatchValue($row->seller_sku ?? '');
            $productNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');

            if ($skuId !== '') {
                $setFirst($bySkuId, $skuId, $row);
            }

            if ($sellerSkuKey !== '') {
                $setFirst($bySellerSku, $sellerSkuKey, $row);
            }

            if ($productId !== '' && $skuId !== '') {
                $setFirst($byProductSkuId, $productId.'|'.$skuId, $row);
            }

            if ($productId !== '' && $sellerSkuKey !== '') {
                $setFirst($byProductSellerSku, $productId.'|'.$sellerSkuKey, $row);
            }

            if ($productId !== '' && $skuNameKey !== '') {
                $setFirst($byProductSkuName, $productId.'|'.$skuNameKey, $row);
            }

            if ($productNameKey !== '' && $skuNameKey !== '') {
                $setFirst($byProductVariantName, $productNameKey.'|'.$skuNameKey, $row);
            }

            if ($skuNameKey !== '') {
                $byVariantName[$skuNameKey] ??= [];
                $byVariantName[$skuNameKey][] = $row;
            }
        }

        return [
            'by_sku_id' => $bySkuId,
            'by_seller_sku' => $bySellerSku,
            'by_product_seller_sku' => $byProductSellerSku,
            'by_product_sku_id' => $byProductSkuId,
            'by_product_sku_name' => $byProductSkuName,
            'by_product_variant_name' => $byProductVariantName,
            'by_variant_name' => $byVariantName,
            'product_groups' => $productGroups,
            'rows_by_product_id' => $rowsByProductId,
        ];
    }

    private function shopeeProductGroupsForSkuMapping(): array
    {
        if (! Schema::hasTable('shopee_product')) {
            return [];
        }

        $products = DB::table('shopee_product as sp')
            ->leftJoin(DB::raw('(SELECT item_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NULL GROUP BY item_id) as spi'), 'spi.item_id', '=', 'sp.item_id')
            ->whereRaw('COALESCE(sp.is_active, true) = true')
            ->select('sp.item_id', 'sp.name as product_name', 'sp.status', 'spi.image_url')
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $modelRows = Schema::hasTable('shopee_product_model')
            ? DB::table('shopee_product_model')
                ->select('item_id', 'name', 'model_id', 'model_sku', 'price', 'original_price')
                ->get()
                ->groupBy('item_id')
            : collect();

        $groups = [];

        foreach ($products as $product) {
            $itemId = trim((string) ($product->item_id ?? ''));
            if ($itemId === '') {
                continue;
            }

            $variantNames = [];
            $variantsByName = [];
            $prices = [];
            $originalPrices = [];
            foreach (($modelRows[$product->item_id] ?? collect()) as $model) {
                $variantKey = $this->normalizeSkuMatchValue($model->name ?? '');
                if ($variantKey !== '') {
                    $variantNames[$variantKey] = true;
                    $price = (int) ($model->price ?? 0);
                    $variantsByName[$variantKey] = [
                        'model_id' => trim((string) ($model->model_id ?? '')),
                        'name' => $model->name ?? '',
                        'model_sku' => $model->model_sku ?? null,
                        'price' => $price,
                        'original_price' => max($price, (int) ($model->original_price ?? 0)),
                    ];
                }

                $price = (int) ($model->price ?? 0);
                if ($price > 0) {
                    $prices[] = $price;
                }

                $originalPrice = max($price, (int) ($model->original_price ?? 0));
                if ($originalPrice > 0) {
                    $originalPrices[] = $originalPrice;
                }
            }

            $groups[$itemId] = [
                'item_id' => $itemId,
                'product_name' => $product->product_name ?? '',
                'product_name_key' => $this->normalizeSkuMatchValue($product->product_name ?? ''),
                'tokens' => $this->skuMappingNameTokens($product->product_name ?? ''),
                'variant_names' => array_keys($variantNames),
                'variants_by_name' => $variantsByName,
                'prices' => $prices,
                'original_prices' => $originalPrices,
                'status' => $product->status ?? null,
                'image_url' => $product->image_url ?? null,
            ];
        }

        return $groups;
    }

    private function suggestShopeeProductsForStockGroups($rows, array $shopeeProductGroups): array
    {
        if ($rows->isEmpty() || $shopeeProductGroups === []) {
            return [];
        }

        $stockGroups = [];

        foreach ($rows as $row) {
            $groupKey = $this->stockMappingGroupKey($row);

            if (! isset($stockGroups[$groupKey])) {
                $stockGroups[$groupKey] = [
                    'product_name_key' => $this->normalizeSkuMatchValue($row->product_name ?? ''),
                    'tokens' => $this->skuMappingNameTokens($row->product_name ?? ''),
                    'variant_names' => [],
                ];
            }

            $variantKey = $this->normalizeSkuMatchValue($row->variant_name ?? '');
            if ($variantKey !== '') {
                $stockGroups[$groupKey]['variant_names'][$variantKey] = true;
            }
        }

        $matches = [];

        foreach ($stockGroups as $groupKey => $stockGroup) {
            $bestItemId = null;
            $bestScore = 0;
            $variantNames = array_keys($stockGroup['variant_names']);

            foreach ($shopeeProductGroups as $itemId => $shopeeGroup) {
                $exactNameMatch = $stockGroup['product_name_key'] !== ''
                    && $stockGroup['product_name_key'] === $shopeeGroup['product_name_key'];
                $nameContains = $stockGroup['product_name_key'] !== ''
                    && $shopeeGroup['product_name_key'] !== ''
                    && (
                        str_contains($stockGroup['product_name_key'], $shopeeGroup['product_name_key'])
                        || str_contains($shopeeGroup['product_name_key'], $stockGroup['product_name_key'])
                    );
                $tokenOverlap = count(array_intersect($stockGroup['tokens'], $shopeeGroup['tokens']));
                $variantOverlap = $variantNames !== []
                    ? count(array_intersect($variantNames, $shopeeGroup['variant_names']))
                    : 0;

                if (! $exactNameMatch && ! $nameContains && $tokenOverlap < 2 && $variantOverlap < 1) {
                    continue;
                }

                $score = ($exactNameMatch ? 100000 : 0)
                    + ($variantOverlap * 1000)
                    + ($tokenOverlap * 50)
                    + ($nameContains ? 25 : 0);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestItemId = (string) $itemId;
                }
            }

            if ($bestItemId !== null) {
                $matches[$groupKey] = $bestItemId;
            }
        }

        return $matches;
    }

    private function suggestTiktokProductsForStockGroups($rows, array $tiktokProductGroups): array
    {
        if ($rows->isEmpty() || $tiktokProductGroups === []) {
            return [];
        }

        $stockGroups = [];

        foreach ($rows as $row) {
            $groupKey = $this->stockMappingGroupKey($row);

            if (! isset($stockGroups[$groupKey])) {
                $stockGroups[$groupKey] = [
                    'product_name_key' => $this->normalizeSkuMatchValue($row->product_name ?? ''),
                    'tokens' => $this->skuMappingNameTokens($row->product_name ?? ''),
                    'variant_names' => [],
                ];
            }

            $variantKey = $this->normalizeSkuMatchValue($row->variant_name ?? '');
            if ($variantKey !== '') {
                $stockGroups[$groupKey]['variant_names'][$variantKey] = true;
            }
        }

        $matches = [];

        foreach ($stockGroups as $groupKey => $stockGroup) {
            $variantNames = array_keys($stockGroup['variant_names']);
            if ($variantNames === []) {
                continue;
            }

            $bestProductId = null;
            $bestScore = 0;

            foreach ($tiktokProductGroups as $productId => $tiktokGroup) {
                if ($stockGroup['product_name_key'] === '' || $stockGroup['product_name_key'] !== $tiktokGroup['product_name_key']) {
                    continue;
                }

                $variantOverlap = count(array_intersect($variantNames, $tiktokGroup['sku_names']));
                $exactNameMatch = $stockGroup['product_name_key'] !== ''
                    && $stockGroup['product_name_key'] === $tiktokGroup['product_name_key'];

                if ($variantOverlap === 0 && ! $exactNameMatch) {
                    continue;
                }

                $tokenOverlap = count(array_intersect($stockGroup['tokens'], $tiktokGroup['tokens']));
                $nameContains = $stockGroup['product_name_key'] !== ''
                    && $tiktokGroup['product_name_key'] !== ''
                    && (
                        str_contains($stockGroup['product_name_key'], $tiktokGroup['product_name_key'])
                        || str_contains($tiktokGroup['product_name_key'], $stockGroup['product_name_key'])
                    );

                if (! $exactNameMatch && $variantOverlap < 2 && $tokenOverlap < 2 && ! $nameContains) {
                    continue;
                }

                $score = ($exactNameMatch ? 100000 : 0) + ($variantOverlap * 1000) + ($tokenOverlap * 50) + ($nameContains ? 25 : 0);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestProductId = (string) $productId;
                }
            }

            if ($bestProductId !== null) {
                $matches[$groupKey] = $bestProductId;
            }
        }

        return $matches;
    }

    private function resolveSkuMappingTiktokMatch(object $row, array $lookup, ?string $suggestedProductId, ?string $preferredVariantName = null, ?string $preferredSellerSku = null): array
    {
        $productId = trim((string) (($row->mapped_tiktok_product_id ?: $row->stock_tiktok_product_id) ?? ''));
        $skuId = trim((string) (($row->tiktok_sku_id ?: $row->stock_tiktok_sku_id) ?? ''));
        $stockVariantKey = $this->normalizeSkuMatchValue($row->variant_name ?? '');
        $preferredVariantKey = $this->normalizeSkuMatchValue($preferredVariantName ?? $row->variant_name ?? '');
        $hasMarketplaceVariantMismatch = $preferredVariantKey !== '' && $stockVariantKey !== '' && $preferredVariantKey !== $stockVariantKey;
        $sellerSku = '';
        $sellerSkuCandidates = [
            $row->mapped_seller_sku ?? null,
            $row->stock_tiktok_seller_sku ?? null,
            $preferredSellerSku,
            $row->stock_shopee_seller_sku ?? null,
        ];
        if (! $hasMarketplaceVariantMismatch) {
            $sellerSkuCandidates[] = $row->internal_sku ?? null;
        }

        foreach ($sellerSkuCandidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $sellerSku = trim($candidate);
                break;
            }
        }
        $sellerSkuKey = $this->normalizeSkuMatchValue($sellerSku);
        $savedSkuNameKey = $this->normalizeSkuMatchValue($row->tiktok_sku_name ?? '');
        $variantKey = $preferredVariantKey;
        $productNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');
        $lookupSkuNameKey = $savedSkuNameKey !== '' ? $savedSkuNameKey : $variantKey;
        $hasSavedMapping = $this->filledString($productId) || $this->filledString($skuId) || $savedSkuNameKey !== '' || $sellerSku !== '';

        foreach ([
            $productId !== '' && $sellerSkuKey !== '' ? ['by_product_seller_sku', $productId.'|'.$sellerSkuKey] : null,
            $sellerSkuKey !== '' ? ['by_seller_sku', $sellerSkuKey] : null,
            $productId !== '' && $skuId !== '' ? ['by_product_sku_id', $productId.'|'.$skuId] : null,
            $skuId !== '' ? ['by_sku_id', $skuId] : null,
            $productId !== '' && $lookupSkuNameKey !== '' ? ['by_product_sku_name', $productId.'|'.$lookupSkuNameKey] : null,
        ] as $candidate) {
            if ($candidate && isset($lookup[$candidate[0]][$candidate[1]])) {
                $match = $lookup[$candidate[0]][$candidate[1]];
                if (
                    in_array($candidate[0], ['by_product_seller_sku', 'by_seller_sku'], true)
                    && $variantKey !== ''
                    && $this->normalizeSkuMatchValue($match->sku_name ?? '') !== $variantKey
                ) {
                    continue;
                }

                if (
                    $candidate[0] === 'by_seller_sku'
                    && $productNameKey !== ''
                    && $this->normalizeSkuMatchValue($match->product_name ?? '') !== $productNameKey
                ) {
                    continue;
                }

                return [$match, 'saved'];
            }
        }

        if ($productId !== '' && $variantKey !== '') {
            $match = $lookup['product_groups'][$productId]['rows_by_sku_name'][$variantKey] ?? null;
            if ($match) {
                return [$match, 'saved'];
            }
        }

        if ($productId !== '' && $sellerSkuKey !== '') {
            $match = $lookup['product_groups'][$productId]['rows_by_seller_sku'][$sellerSkuKey] ?? null;
            if ($match) {
                return [$match, 'saved'];
            }
        }

        if ($productNameKey !== '' && $variantKey !== '') {
            $match = $lookup['by_product_variant_name'][$productNameKey.'|'.$variantKey] ?? null;
            if ($match) {
                return [$match, $hasSavedMapping ? 'saved' : 'suggested'];
            }
        }

        if ($suggestedProductId && $variantKey !== '') {
            $match = $lookup['product_groups'][$suggestedProductId]['rows_by_sku_name'][$variantKey] ?? null;
            if ($match) {
                return [$match, $hasSavedMapping ? 'saved' : 'suggested'];
            }
        }

        if ($suggestedProductId && isset($lookup['rows_by_product_id'][$suggestedProductId])) {
            $groupFirst = $lookup['rows_by_product_id'][$suggestedProductId]->first();
            if ($groupFirst) {
                return [(object) [
                    'product_id' => (string) ($groupFirst->product_id ?? $suggestedProductId),
                    'product_name' => $groupFirst->product_name ?? null,
                    'product_image_url' => $groupFirst->product_image_url ?? $groupFirst->image_url ?? null,
                    'image_url' => $groupFirst->image_url ?? $groupFirst->product_image_url ?? null,
                    'sku_id' => null,
                    'sku_name' => null,
                    'seller_sku' => null,
                    'stock_qty' => null,
                ], 'suggested_product'];
            }
        }

        return [null, null];
    }

    private function bestTiktokVariantCandidateForStockRow(object $row, array $candidates, array $tiktokProductGroups): ?object
    {
        if ($candidates === []) {
            return null;
        }

        $stockTokens = $this->skuMappingNameTokens($row->product_name ?? '');
        $stockProductNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');
        $bestMatch = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $productId = (string) ($candidate->product_id ?? '');
            $tiktokGroup = $tiktokProductGroups[$productId] ?? null;

            if (! $tiktokGroup) {
                continue;
            }

            $tokenOverlap = count(array_intersect($stockTokens, $tiktokGroup['tokens']));
            $nameContains = $stockProductNameKey !== ''
                && $tiktokGroup['product_name_key'] !== ''
                && (
                    str_contains($stockProductNameKey, $tiktokGroup['product_name_key'])
                    || str_contains($tiktokGroup['product_name_key'], $stockProductNameKey)
                );

            if ($tokenOverlap < 2 && ! $nameContains) {
                continue;
            }

            $score = ($tokenOverlap * 100) + ($nameContains ? 25 : 0);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        return $bestMatch;
    }

    private function stockMappingGroupKey(object $row): string
    {
        $shopeeItemId = $row->shopee_item_id ?: ($row->stock_shopee_item_id ?? '');

        if ($this->filledString($shopeeItemId)) {
            return 'shopee:'.trim((string) $shopeeItemId);
        }

        $productNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');

        return $productNameKey !== '' ? 'product:'.$productNameKey : 'stock:'.$row->id;
    }

    private function normalizeSkuMatchValue(mixed $value): string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function tiktokVariantKey(object $row): string
    {
        $productId = trim((string) ($row->product_id ?? ''));
        $skuId = trim((string) ($row->sku_id ?? ''));
        $skuNameKey = $this->normalizeSkuMatchValue($row->sku_name ?? '');

        if ($productId === '') {
            return $skuId !== '' ? 'sku:'.$skuId : 'name:'.$skuNameKey;
        }

        if ($skuId !== '') {
            return $productId.'|sku:'.$skuId;
        }

        return $productId.'|name:'.$skuNameKey;
    }

    private function tiktokVariantMatchKeys(object $row): array
    {
        $keys = [];
        $variantKey = $this->tiktokVariantKey($row);
        $skuId = trim((string) ($row->sku_id ?? ''));
        $sellerSkuKey = $this->normalizeSkuMatchValue($row->seller_sku ?? '');
        $productNameKey = $this->normalizeSkuMatchValue($row->product_name ?? '');
        $skuNameKey = $this->normalizeSkuMatchValue($row->sku_name ?? '');

        if ($variantKey !== '') {
            $keys[] = 'variant:'.$variantKey;
        }

        if ($skuId !== '') {
            $keys[] = 'sku_id:'.$skuId;
        }

        if ($sellerSkuKey !== '') {
            $keys[] = 'seller_sku:'.$sellerSkuKey;
        }

        if ($productNameKey !== '' && $skuNameKey !== '') {
            $keys[] = 'product_variant:'.$productNameKey.'|'.$skuNameKey;
        }

        if ($productNameKey !== '' && $sellerSkuKey !== '') {
            $keys[] = 'product_seller_sku:'.$productNameKey.'|'.$sellerSkuKey;
        }

        return array_values(array_unique($keys));
    }

    private function hasMatchedTiktokVariant(array $matchedKeys, object $row): bool
    {
        foreach ($this->tiktokVariantMatchKeys($row) as $matchKey) {
            if (isset($matchedKeys[$matchKey])) {
                return true;
            }
        }

        return false;
    }

    private function skuMappingNameTokens(mixed $value): array
    {
        $tokens = preg_split('/\s+/', $this->normalizeSkuMatchValue($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ignored = ['agni', 'and', 'bjm', 'by', 'dan', 'for', 'kw', 'ori', 'shop', 'the', 'yang'];

        return array_values(array_unique(array_filter(
            $tokens,
            fn ($token) => strlen($token) >= 3 && ! in_array($token, $ignored, true)
        )));
    }

    private function filledString(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }

    public function saveSkuMapping(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'stock_master_id' => ['required', 'integer'],
            'shopee_item_id' => ['nullable', 'string'],
            'shopee_model_id' => ['nullable', 'string'],
            'tiktok_product_id' => ['nullable', 'string'],
            'tiktok_sku_id' => ['nullable', 'string'],
            'tiktok_sku_name' => ['nullable', 'string'],
            'seller_sku' => ['nullable', 'string'],
            'internal_image_url' => ['nullable', 'string'],
            'shopee_image_url' => ['nullable', 'string'],
            'tiktok_image_url' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        DB::table('sku_mappings')->updateOrInsert(
            ['stock_master_id' => $data['stock_master_id']],
            [
                'shopee_item_id' => $data['shopee_item_id'] ?? null,
                'shopee_model_id' => $data['shopee_model_id'] ?? null,
                'tiktok_product_id' => $data['tiktok_product_id'] ?? null,
                'tiktok_sku_id' => $data['tiktok_sku_id'] ?? null,
                'tiktok_sku_name' => $data['tiktok_sku_name'] ?? null,
                'seller_sku' => $data['seller_sku'] ?? null,
                'internal_image_url' => $data['internal_image_url'] ?? null,
                'shopee_image_url' => $data['shopee_image_url'] ?? null,
                'tiktok_image_url' => $data['tiktok_image_url'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('stock_master')->where('id', $data['stock_master_id'])->update([
            'shopee_product_id' => $data['shopee_item_id'] ?? null,
            'shopee_sku' => $data['shopee_model_id'] ?? null,
            'shopee_seller_sku' => $data['seller_sku'] ?? null,
            'tiktok_product_id' => $data['tiktok_product_id'] ?? null,
            'tiktok_sku' => $data['tiktok_sku_id'] ?? null,
            'tiktok_seller_sku' => $data['seller_sku'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'ok', 'message' => 'Mapping SKU berhasil disimpan.']);
    }

    public function updateSkuMappingMarketplaceSku(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'stock_master_id' => ['required', 'integer'],
            'seller_sku' => ['required', 'string', 'max:100'],
            'apply_shopee' => ['nullable'],
            'apply_tiktok' => ['nullable'],
            'dry_run' => ['nullable'],
        ]);

        $stock = $this->loadSkuMappingVariantStockRow((int) $data['stock_master_id']);
        abort_if(! $stock, 404, 'Varian tidak ditemukan.');

        $sellerSku = trim((string) $data['seller_sku']);
        abort_if($sellerSku === '', 422, 'SKU target wajib diisi.');

        $applyShopee = $this->boolString($data['apply_shopee'] ?? true) === 'true';
        $applyTiktok = $this->boolString($data['apply_tiktok'] ?? true) === 'true';
        $dryRun = $this->boolString($data['dry_run'] ?? false) === 'true';
        abort_if(! $applyShopee && ! $applyTiktok, 422, 'Pilih minimal satu marketplace untuk diedit.');
        if (! $dryRun) {
            $this->autoRefreshMarketplaceTokens();
        }

        $shopeeItemId = trim((string) (($stock->shopee_item_id ?: $stock->shopee_product_id) ?? ''));
        $shopeeModelId = trim((string) (($stock->shopee_model_id ?: $stock->shopee_sku) ?? ''));
        $tiktokProductId = trim((string) (($stock->mapped_tiktok_product_id ?: $stock->tiktok_product_id) ?? ''));
        $tiktokSkuId = trim((string) (($stock->tiktok_sku_id ?: $stock->tiktok_sku) ?? ''));
        $oldShopeeSellerSku = trim((string) ($stock->shopee_seller_sku ?? ''));
        $oldTiktokSellerSku = trim((string) ($stock->tiktok_seller_sku ?? ''));
        $now = now();

        $result = [
            'status' => 'ok',
            'message' => $dryRun
                ? 'Preview update SKU berhasil dibuat. Belum ada perubahan dikirim.'
                : 'Update SKU marketplace selesai diproses.',
            'dry_run' => $dryRun,
            'seller_sku' => $sellerSku,
            'stock_master_id' => (int) $stock->id,
            'shopee' => [
                'enabled' => $applyShopee,
                'status' => $applyShopee ? 'pending' : 'skipped',
                'request' => null,
                'response' => null,
            ],
            'tiktok' => [
                'enabled' => $applyTiktok,
                'status' => $applyTiktok ? 'pending' : 'skipped',
                'request' => null,
                'response' => null,
            ],
        ];

        $successfulChannels = [];

        if ($applyShopee) {
            $shopeePayload = [
                'item_id' => is_numeric($shopeeItemId) ? (int) $shopeeItemId : $shopeeItemId,
                'model' => [[
                    'model_id' => is_numeric($shopeeModelId) ? (int) $shopeeModelId : $shopeeModelId,
                    'model_sku' => mb_substr($sellerSku, 0, 100),
                ]],
            ];
            $result['shopee']['request'] = [
                'method' => 'POST',
                'path' => '/api/v2/product/update_model',
                'body' => $shopeePayload,
            ];

            if ($shopeeItemId === '' || $shopeeModelId === '') {
                $result['shopee']['status'] = 'skipped';
                $result['shopee']['response'] = [
                    'message' => 'Item ID atau Model ID Shopee belum lengkap.',
                    'item_id' => $shopeeItemId,
                    'model_id' => $shopeeModelId,
                ];
            } elseif ($dryRun) {
                $result['shopee']['status'] = 'dry_run';
                $result['shopee']['response'] = ['message' => 'Dry run Shopee. Request belum dikirim.'];
            } else {
                try {
                    $config = $this->shopeeConfig();
                    $context = $this->resolveShopeeApiTestContext([]);
                    $shopId = (int) ($context['shop_id'] ?? 0);
                    $accessToken = trim((string) ($context['access_token'] ?? ''));
                    abort_if($shopId <= 0 || $accessToken === '', 422, 'Token Shopee aktif belum lengkap.');

                    $response = $this->shopeeSignedPost($config, '/api/v2/product/update_model', $shopId, $accessToken, $shopeePayload);
                    $result['shopee']['response'] = $response;
                    $result['shopee']['status'] = ($response['error'] ?? '') === '' ? 'ok' : 'error';

                    if ($result['shopee']['status'] === 'ok') {
                        $successfulChannels[] = 'shopee';
                    }
                } catch (\Throwable $exception) {
                    $result['shopee']['status'] = 'error';
                    $result['shopee']['response'] = [
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        if ($applyTiktok) {
            $tiktokPayload = null;
            $tiktokPath = null;

            if ($tiktokProductId === '' || $tiktokSkuId === '') {
                $result['tiktok']['status'] = 'skipped';
                $result['tiktok']['response'] = [
                    'message' => 'Product ID atau SKU ID TikTok belum lengkap.',
                    'product_id' => $tiktokProductId,
                    'sku_id' => $tiktokSkuId,
                ];
            } else {
                try {
                    $context = $this->resolveTiktokGetProductContext(['version' => '202509']);
                    $accessToken = trim((string) ($context['access_token'] ?? ''));
                    $shopId = trim((string) ($context['shop_id'] ?? ''));
                    $shopCipher = trim((string) ($context['shop_cipher'] ?? ''));
                    abort_if($accessToken === '', 422, 'Token TikTok belum aktif.');
                    abort_if($shopId === '' || $shopCipher === '', 422, 'Shop TikTok belum lengkap.');

                    $config = $this->tiktokConfig();
                    $detail = $this->fetchTiktokProductDetail(
                        $config,
                        $accessToken,
                        (object) ['shop_id' => $shopId, 'shop_cipher' => $shopCipher, 'cipher' => $shopCipher],
                        $tiktokProductId,
                        $config['api_host'].'/product/202309/products/'
                    );
                    abort_if(! is_array($detail), 422, 'Detail produk TikTok belum bisa dibaca untuk menjaga SKU lain tidak terhapus.');
                    $detailPayload = is_array($detail['product'] ?? null) ? $detail['product'] : $detail;

                    $skuRows = $this->buildTiktokPartialEditSkuRows(
                        $detailPayload,
                        $tiktokSkuId,
                        $sellerSku,
                        $this->tiktokDeletedVariantIdsForProduct($tiktokProductId)
                    );
                    abort_if($skuRows === [], 422, 'SKU TikTok target tidak ditemukan di detail produk terbaru.');

                    $tiktokPayload = [
                        'save_mode' => 'LISTING',
                        'skus' => $skuRows,
                    ];
                    $tiktokPath = '/product/202509/products/'.$tiktokProductId.'/partial_edit';
                    $result['tiktok']['request'] = [
                        'method' => 'POST',
                        'path' => $tiktokPath,
                        'body' => $tiktokPayload,
                    ];

                    if ($dryRun) {
                        $result['tiktok']['status'] = 'dry_run';
                        $result['tiktok']['response'] = ['message' => 'Dry run TikTok. Request belum dikirim.'];
                    } else {
                        $response = $this->submitTiktokPartialEditPayload($tiktokPath, $tiktokPayload, $context);
                        $result['tiktok']['response'] = $response;
                        $result['tiktok']['status'] = (int) ($response['code'] ?? -1) === 0 ? 'ok' : 'error';

                        if ($result['tiktok']['status'] === 'ok') {
                            $successfulChannels[] = 'tiktok';
                        }
                    }
                } catch (\Throwable $exception) {
                    $result['tiktok']['status'] = 'error';
                    $result['tiktok']['request'] = $result['tiktok']['request'] ?: [
                        'method' => 'POST',
                        'path' => $tiktokPath ?: '/product/202509/products/'.$tiktokProductId.'/partial_edit',
                        'body' => $tiktokPayload,
                    ];
                    $result['tiktok']['response'] = [
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        if (! $dryRun && $successfulChannels !== []) {
            DB::table('sku_mappings')->updateOrInsert(
                ['stock_master_id' => (int) $stock->id],
                [
                    'shopee_item_id' => $shopeeItemId ?: null,
                    'shopee_model_id' => $shopeeModelId ?: null,
                    'tiktok_product_id' => $tiktokProductId ?: null,
                    'tiktok_sku_id' => $tiktokSkuId ?: null,
                    'tiktok_sku_name' => $stock->tiktok_sku_name ?? $stock->variant_name,
                    'seller_sku' => $sellerSku,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $stockUpdate = ['updated_at' => $now];
            if (in_array('shopee', $successfulChannels, true)) {
                $stockUpdate['shopee_seller_sku'] = $sellerSku;
                $stockUpdate['shopee_product_id'] = $shopeeItemId ?: null;
                $stockUpdate['shopee_sku'] = $shopeeModelId ?: null;
            }
            if (in_array('tiktok', $successfulChannels, true)) {
                $stockUpdate['tiktok_seller_sku'] = $sellerSku;
                $stockUpdate['tiktok_product_id'] = $tiktokProductId ?: null;
                $stockUpdate['tiktok_sku'] = $tiktokSkuId ?: null;
                DB::table('tiktok_products')
                    ->where('product_id', $tiktokProductId)
                    ->where('sku_id', $tiktokSkuId)
                    ->update([
                        'seller_sku' => $sellerSku,
                        'updated_at' => $now,
                    ]);
            }

            DB::table('stock_master')->where('id', (int) $stock->id)->update($stockUpdate);
        }

        if (! $dryRun) {
            if ($applyShopee && ($result['shopee']['status'] ?? 'skipped') !== 'pending') {
                $this->recordMarketplaceSkuChange(
                    $stock->id,
                    'shopee',
                    $shopeeItemId,
                    $shopeeModelId,
                    $oldShopeeSellerSku,
                    $sellerSku,
                    'sku_mapping_update',
                    (string) ($result['shopee']['status'] ?? 'unknown'),
                    $result['shopee']['request'] ?? null,
                    $result['shopee']['response'] ?? null,
                    $result['shopee']['response']['message'] ?? $result['message'] ?? null
                );
            }
            if ($applyTiktok && ($result['tiktok']['status'] ?? 'skipped') !== 'pending') {
                $this->recordMarketplaceSkuChange(
                    $stock->id,
                    'tiktok',
                    $tiktokProductId,
                    $tiktokSkuId,
                    $oldTiktokSellerSku,
                    $sellerSku,
                    'sku_mapping_update',
                    (string) ($result['tiktok']['status'] ?? 'unknown'),
                    $result['tiktok']['request'] ?? null,
                    $result['tiktok']['response'] ?? null,
                    $result['tiktok']['response']['message'] ?? $result['message'] ?? null
                );
            }
        }

        if (($result['shopee']['status'] ?? 'skipped') === 'error' || ($result['tiktok']['status'] ?? 'skipped') === 'error') {
            $result['status'] = 'partial_error';
            $result['message'] = 'Sebagian request SKU gagal. Cek response Shopee dan TikTok.';
        }

        return response()->json($result);
    }

    public function updateMarketplaceVariantSku(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'channel' => ['required', 'string', 'in:shopee,tiktok'],
            'seller_sku' => ['required', 'string', 'max:100'],
            'item_id' => ['nullable', 'string'],
            'model_id' => ['nullable', 'string'],
            'product_id' => ['nullable', 'string'],
            'sku_id' => ['nullable', 'string'],
        ]);

        $channel = strtolower(trim((string) $data['channel']));
        $sellerSku = trim((string) $data['seller_sku']);
        abort_if($sellerSku === '', 422, 'SKU wajib diisi.');
        $this->autoRefreshMarketplaceTokens();

        $now = now();
        $result = [
            'status' => 'ok',
            'message' => 'Update SKU varian marketplace selesai diproses.',
            'channel' => $channel,
            'seller_sku' => $sellerSku,
            'request' => null,
            'response' => null,
        ];

        if ($channel === 'shopee') {
            $itemId = trim((string) ($data['item_id'] ?? ''));
            $modelId = trim((string) ($data['model_id'] ?? ''));
            abort_if($itemId === '' || $modelId === '', 422, 'Item ID atau Model ID Shopee belum lengkap.');
            $oldSellerSku = (string) (DB::table('shopee_product_model')
                ->where('item_id', $itemId)
                ->where('model_id', $modelId)
                ->value('model_sku') ?? '');

            $payload = [
                'item_id' => is_numeric($itemId) ? (int) $itemId : $itemId,
                'model' => [[
                    'model_id' => is_numeric($modelId) ? (int) $modelId : $modelId,
                    'model_sku' => mb_substr($sellerSku, 0, 100),
                ]],
            ];
            $result['request'] = [
                'method' => 'POST',
                'path' => '/api/v2/product/update_model',
                'body' => $payload,
            ];

            try {
                $config = $this->shopeeConfig();
                $context = $this->resolveShopeeApiTestContext([]);
                $shopId = (int) ($context['shop_id'] ?? 0);
                $accessToken = trim((string) ($context['access_token'] ?? ''));
                abort_if($shopId <= 0 || $accessToken === '', 422, 'Token Shopee aktif belum lengkap.');

                $response = $this->shopeeSignedPost($config, '/api/v2/product/update_model', $shopId, $accessToken, $payload);
                $result['response'] = $response;
                $result['status'] = ($response['error'] ?? '') === '' ? 'ok' : 'error';

                if ($result['status'] === 'ok') {
                    DB::table('shopee_product_model')
                        ->where('item_id', $itemId)
                        ->where('model_id', $modelId)
                        ->update(['model_sku' => $sellerSku, 'updated_at' => $now]);
                    DB::table('stock_master')
                        ->where('shopee_product_id', $itemId)
                        ->where('shopee_sku', $modelId)
                        ->update(['shopee_seller_sku' => $sellerSku, 'updated_at' => $now]);
                }
            } catch (\Throwable $exception) {
                $result['status'] = 'error';
                $result['response'] = ['message' => $exception->getMessage()];
            }

            $this->recordMarketplaceSkuChange(null, 'shopee', $itemId, $modelId, $oldSellerSku, $sellerSku, 'variant_update', $result['status'], $result['request'], $result['response'], $result['response']['message'] ?? null);

            return response()->json($result, $result['status'] === 'error' ? 422 : 200);
        }

        $productId = trim((string) ($data['product_id'] ?? ''));
        $skuId = trim((string) ($data['sku_id'] ?? ''));
        abort_if($productId === '' || $skuId === '', 422, 'Product ID atau SKU ID TikTok belum lengkap.');
        $oldSellerSku = (string) (DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->where('sku_id', $skuId)
            ->value('seller_sku') ?? '');

        $tiktokPayload = null;
        $tiktokPath = '/product/202509/products/'.$productId.'/partial_edit';

        try {
            $context = $this->resolveTiktokGetProductContext(['version' => '202509']);
            $accessToken = trim((string) ($context['access_token'] ?? ''));
            $shopId = trim((string) ($context['shop_id'] ?? ''));
            $shopCipher = trim((string) ($context['shop_cipher'] ?? ''));
            abort_if($accessToken === '', 422, 'Token TikTok belum aktif.');
            abort_if($shopId === '' || $shopCipher === '', 422, 'Shop TikTok belum lengkap.');

            $config = $this->tiktokConfig();
            $detail = $this->fetchTiktokProductDetail(
                $config,
                $accessToken,
                (object) ['shop_id' => $shopId, 'shop_cipher' => $shopCipher, 'cipher' => $shopCipher],
                $productId,
                $config['api_host'].'/product/202309/products/'
            );
            abort_if(! is_array($detail), 422, 'Detail produk TikTok belum bisa dibaca untuk menjaga SKU lain tidak terhapus.');
            $detailPayload = is_array($detail['product'] ?? null) ? $detail['product'] : $detail;

            $skuRows = $this->buildTiktokPartialEditSkuRows(
                $detailPayload,
                $skuId,
                $sellerSku,
                $this->tiktokDeletedVariantIdsForProduct($productId)
            );
            abort_if($skuRows === [], 422, 'SKU TikTok target tidak ditemukan di detail produk terbaru.');

            $tiktokPayload = [
                'save_mode' => 'LISTING',
                'skus' => $skuRows,
            ];
            $result['request'] = [
                'method' => 'POST',
                'path' => $tiktokPath,
                'body' => $tiktokPayload,
            ];

            $response = $this->submitTiktokPartialEditPayload($tiktokPath, $tiktokPayload, $context);
            $result['response'] = $response;
            $result['status'] = (int) ($response['code'] ?? -1) === 0 ? 'ok' : 'error';

            if ($result['status'] === 'ok') {
                DB::table('tiktok_products')
                    ->where('product_id', $productId)
                    ->where('sku_id', $skuId)
                    ->update(['seller_sku' => $sellerSku, 'updated_at' => $now]);
                DB::table('stock_master')
                    ->where('tiktok_product_id', $productId)
                    ->where('tiktok_sku', $skuId)
                    ->update(['tiktok_seller_sku' => $sellerSku, 'updated_at' => $now]);
            }
        } catch (\Throwable $exception) {
            $result['status'] = 'error';
            $result['request'] = $result['request'] ?: [
                'method' => 'POST',
                'path' => $tiktokPath,
                'body' => $tiktokPayload,
            ];
            $result['response'] = ['message' => $exception->getMessage()];
        }

        $this->recordMarketplaceSkuChange(null, 'tiktok', $productId, $skuId, $oldSellerSku, $sellerSku, 'variant_update', $result['status'], $result['request'], $result['response'], $result['response']['message'] ?? null);

        return response()->json($result, $result['status'] === 'error' ? 422 : 200);
    }

    public function shopeeDeleteVariant(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'item_id' => ['required', 'string'],
            'model_id' => ['required', 'string'],
            'confirm_model_id' => ['required', 'string'],
        ]);

        $itemId = trim((string) $data['item_id']);
        $modelId = trim((string) $data['model_id']);
        $confirmModelId = trim((string) $data['confirm_model_id']);

        abort_if(! preg_match('/^\d+$/', $itemId) || ! preg_match('/^\d+$/', $modelId), 422, 'Item ID dan Model ID Shopee wajib berupa angka.');
        abort_if($modelId === '0', 422, 'Model default/tanpa varian tidak bisa dihapus dari tool ini.');
        abort_if($confirmModelId !== $modelId, 422, 'Konfirmasi belum cocok. Ketik Model ID varian yang akan dihapus.');

        $product = DB::table('shopee_product')->where('item_id', $itemId)->first();
        abort_if(! $product, 422, 'Produk Shopee tidak ditemukan di cache lokal. Klik Sync produk dulu.');

        $model = DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->where('model_id', $modelId)
            ->first();
        abort_if(! $model, 422, 'Varian Shopee tidak ditemukan di cache lokal. Klik Sync produk dulu.');

        $modelCount = DB::table('shopee_product_model')->where('item_id', $itemId)->count();
        abort_if($modelCount <= 1, 422, 'Varian terakhir tidak bisa dihapus dari tool ini. Hapus/nonaktifkan produk dari Seller Center jika memang mau menghapus produk.');

        $stockMasterId = DB::table('stock_master')
            ->where('shopee_product_id', $itemId)
            ->where('shopee_sku', $modelId)
            ->value('id');
        $oldSellerSku = (string) ($model->model_sku ?? '');
        $payload = [
            'item_id' => (int) $itemId,
            'model_id' => (int) $modelId,
        ];
        $requestPayload = [
            'method' => 'POST',
            'path' => '/api/v2/product/delete_model',
            'body' => $payload,
        ];
        $result = [
            'status' => 'ok',
            'message' => 'Varian Shopee berhasil dihapus dari marketplace dan cache lokal.',
            'item_id' => $itemId,
            'model_id' => $modelId,
            'model_name' => (string) ($model->name ?? ''),
            'request' => $requestPayload,
            'response' => null,
            'cleanup' => null,
        ];

        try {
            $this->autoRefreshMarketplaceTokens();

            $config = $this->shopeeConfig();
            $context = $this->resolveShopeeApiTestContext(['shop_id' => (string) ($product->shop_id ?? '')]);
            $shopId = (int) ($context['shop_id'] ?? 0);
            $accessToken = trim((string) ($context['access_token'] ?? ''));
            $productShopId = (int) ($product->shop_id ?? 0);

            abort_if($shopId <= 0 || $accessToken === '', 422, 'Token Shopee aktif belum lengkap. Jalankan AUTH / REFRESH Shopee dulu.');
            abort_if($productShopId > 0 && $shopId !== $productShopId, 422, 'Token Shopee aktif tidak cocok dengan toko produk ini.');

            $response = $this->shopeeSignedPost($config, '/api/v2/product/delete_model', $shopId, $accessToken, $payload);
            $result['response'] = $response;
            $result['status'] = ($response['error'] ?? '') === '' ? 'ok' : 'error';

            if ($result['status'] === 'ok') {
                $result['cleanup'] = $this->deleteShopeeVariantFromLocalCache($itemId, $modelId);
            } else {
                $result['message'] = $response['message'] ?? $response['error'] ?? 'Shopee menolak hapus varian.';
            }
        } catch (\Throwable $exception) {
            $result['status'] = 'error';
            $result['message'] = $exception->getMessage();
            $result['response'] = ['message' => $exception->getMessage()];
        }

        $this->recordMarketplaceSkuChange(
            $stockMasterId ? (int) $stockMasterId : null,
            'shopee',
            $itemId,
            $modelId,
            $oldSellerSku,
            null,
            'variant_delete',
            $result['status'],
            $requestPayload,
            $result['response'],
            $result['message']
        );

        return response()->json($result, $result['status'] === 'error' ? 422 : 200);
    }

    private function deleteShopeeVariantFromLocalCache(string $itemId, string $modelId): array
    {
        $now = now();
        $stockMasterIds = DB::table('stock_master')
            ->where('shopee_product_id', $itemId)
            ->where('shopee_sku', $modelId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $deletedImages = DB::table('shopee_product_image')
            ->where('item_id', $itemId)
            ->where('model_id', $modelId)
            ->delete();
        $deletedModels = DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->where('model_id', $modelId)
            ->delete();

        $remainingStock = (int) DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->sum('stock');
        DB::table('shopee_product')
            ->where('item_id', $itemId)
            ->update(['stock' => $remainingStock, 'updated_at' => $now]);

        $updatedStockMasters = 0;
        if ($stockMasterIds !== []) {
            $updatedStockMasters = DB::table('stock_master')
                ->whereIn('id', $stockMasterIds)
                ->update([
                    'shopee_product_id' => null,
                    'shopee_sku' => null,
                    'shopee_seller_sku' => null,
                    'stock_qty' => 0,
                    'is_hidden_from_mapping' => DB::raw('true'),
                    'hidden_from_mapping_reason' => 'Varian Shopee dihapus dari marketplace.',
                    'hidden_from_mapping_at' => $now,
                    'hidden_from_mapping_by' => 'system',
                    'updated_at' => $now,
                ]);
        }

        $updatedSkuMappings = DB::table('sku_mappings')
            ->where('shopee_item_id', $itemId)
            ->where('shopee_model_id', $modelId)
            ->update([
                'shopee_item_id' => null,
                'shopee_model_id' => null,
                'shopee_image_url' => null,
                'updated_at' => $now,
            ]);

        return [
            'deleted_models' => $deletedModels,
            'deleted_images' => $deletedImages,
            'updated_stock_masters' => $updatedStockMasters,
            'updated_sku_mappings' => $updatedSkuMappings,
            'remaining_stock' => $remainingStock,
        ];
    }

    private function recordMarketplaceSkuChange(?int $stockMasterId, string $channel, ?string $productId, ?string $variantId, ?string $oldSellerSku, ?string $newSellerSku, string $action, string $status, ?array $requestPayload = null, ?array $responsePayload = null, ?string $message = null): void
    {
        if (! Schema::hasTable('marketplace_sku_change_logs')) {
            return;
        }

        DB::table('marketplace_sku_change_logs')->insert([
            'stock_master_id' => $stockMasterId,
            'channel' => $channel,
            'product_id' => $productId ?: null,
            'variant_id' => $variantId ?: null,
            'old_seller_sku' => $oldSellerSku !== null && trim($oldSellerSku) !== '' ? $oldSellerSku : null,
            'new_seller_sku' => $newSellerSku !== null && trim($newSellerSku) !== '' ? $newSellerSku : null,
            'action' => $action,
            'status' => $status,
            'request_payload' => $requestPayload !== null ? json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'response_payload' => $responsePayload !== null ? json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tiktokDeletedVariantIdsForProduct(string $productId, array $includeSkuIds = []): array
    {
        $ids = [];
        $add = function (mixed $value) use (&$ids): void {
            $skuId = trim((string) ($value ?? ''));
            $key = $this->normalizeSkuMatchValue($skuId);

            if ($key !== '') {
                $ids[$key] = $skuId;
            }
        };

        foreach ($includeSkuIds as $skuId) {
            $add($skuId);
        }

        static $hasLogTable = null;
        if ($hasLogTable === null) {
            $hasLogTable = Schema::hasTable('marketplace_sku_change_logs');
        }

        if (! $hasLogTable || trim($productId) === '') {
            return $ids;
        }

        DB::table('marketplace_sku_change_logs')
            ->where('channel', 'tiktok')
            ->where('action', 'variant_delete')
            ->where('status', 'ok')
            ->where('product_id', $productId)
            ->whereNotNull('variant_id')
            ->pluck('variant_id')
            ->each($add);

        return $ids;
    }

    private function deactivateDeletedTiktokVariantRows(string $productId, array $deletedSkuIds): int
    {
        $skuIds = collect($deletedSkuIds)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($skuIds === [] || ! Schema::hasTable('tiktok_products')) {
            return 0;
        }

        return DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->whereIn('sku_id', $skuIds)
            ->update([
                'stock_qty' => 0,
                'subtotal' => 0,
                'is_active' => DB::raw('false'),
                'updated_at' => now(),
            ]);
    }

    private function buildTiktokPartialEditSkuRows(array $productDetail, string $targetSkuId, string $sellerSku, array $excludedSkuIds = []): array
    {
        $rows = [];
        $targetFound = false;
        $productId = trim((string) ($productDetail['id'] ?? $productDetail['product_id'] ?? ''));
        $excludedKeys = collect($excludedSkuIds)
            ->map(fn (mixed $value): string => $this->normalizeSkuMatchValue($value))
            ->filter()
            ->flip()
            ->all();
        unset($excludedKeys[$this->normalizeSkuMatchValue($targetSkuId)]);

        foreach ($this->normalizeTiktokSkuList($productDetail) as $sku) {
            if (! is_array($sku)) {
                continue;
            }

            $skuId = trim((string) ($sku['id'] ?? $sku['sku_id'] ?? ''));
            if ($skuId === '') {
                continue;
            }

            if (isset($excludedKeys[$this->normalizeSkuMatchValue($skuId)])) {
                continue;
            }

            $row = $this->buildTiktokPartialEditSkuKeepRow(
                $sku,
                $skuId === $targetSkuId ? $sellerSku : null,
                $productId
            );

            $salesAttributes = data_get($sku, 'sales_attributes', data_get($sku, 'sale_attributes', []));
            if (is_array($salesAttributes) && $salesAttributes !== []) {
                $row['sales_attributes'] = $salesAttributes;
            }

            $rows[] = $row;
            if ($skuId === $targetSkuId) {
                $targetFound = true;
            }
        }

        return $targetFound ? $rows : [];
    }

    private function buildTiktokPartialEditSkuDeleteRows(array $productDetail, string $targetSkuId, array $excludedSkuIds = []): array
    {
        $rows = [];
        $targetFound = false;
        $productId = trim((string) ($productDetail['id'] ?? $productDetail['product_id'] ?? ''));
        $excludedKeys = collect([...array_values($excludedSkuIds), $targetSkuId])
            ->map(fn (mixed $value): string => $this->normalizeSkuMatchValue($value))
            ->filter()
            ->flip()
            ->all();

        foreach ($this->normalizeTiktokSkuList($productDetail) as $sku) {
            if (! is_array($sku)) {
                continue;
            }

            $skuId = trim((string) ($sku['id'] ?? $sku['sku_id'] ?? ''));
            if ($skuId === '') {
                continue;
            }

            if ($skuId === $targetSkuId) {
                $targetFound = true;
                continue;
            }

            if (isset($excludedKeys[$this->normalizeSkuMatchValue($skuId)])) {
                continue;
            }

            $row = $this->buildTiktokPartialEditSkuKeepRow($sku, null, $productId);

            $salesAttributes = data_get($sku, 'sales_attributes', data_get($sku, 'sale_attributes', []));
            if (is_array($salesAttributes) && $salesAttributes !== []) {
                $row['sales_attributes'] = $salesAttributes;
            }

            $rows[] = $row;
        }

        return $targetFound ? $rows : [];
    }

    private function buildTiktokPartialEditSkuKeepRow(array $sku, ?string $sellerSkuOverride = null, string $productId = ''): array
    {
        $skuId = trim((string) ($sku['id'] ?? $sku['sku_id'] ?? ''));
        $row = ['id' => $skuId];
        $sellerSku = $sellerSkuOverride !== null
            ? trim($sellerSkuOverride)
            : trim((string) ($this->extractTiktokSellerSku($sku) ?? ''));

        if ($sellerSku !== '') {
            $row['seller_sku'] = $sellerSku;
        }

        $price = $this->buildTiktokPartialEditSkuPrice($sku, $productId, $skuId);
        if ($price !== null) {
            $row['price'] = $price;
        }

        $inventory = $this->buildTiktokPartialEditSkuInventory($sku);
        if ($inventory !== []) {
            $row['inventory'] = $inventory;
        }

        return $row;
    }

    private function buildTiktokPartialEditSkuPrice(array $sku, string $productId = '', string $skuId = ''): ?array
    {
        $priceNode = data_get($sku, 'price');
        $salePrice = is_array($priceNode)
            ? data_get($priceNode, 'sale_price', data_get($priceNode, 'amount', data_get($priceNode, 'tax_exclusive_price')))
            : $priceNode;
        $salePrice = $this->normalizeTiktokPriceValue($salePrice);

        if ($salePrice === '' && $productId !== '' && $skuId !== '') {
            $salePrice = $this->normalizeTiktokPriceValue(DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->where('sku_id', $skuId)
                ->value('price'));
        }

        if ($salePrice === '') {
            return null;
        }

        $currency = is_array($priceNode)
            ? trim((string) data_get($priceNode, 'currency', 'IDR'))
            : 'IDR';
        $taxExclusivePrice = is_array($priceNode)
            ? $this->normalizeTiktokPriceValue(data_get($priceNode, 'tax_exclusive_price', $salePrice))
            : $salePrice;
        $amount = is_array($priceNode)
            ? $this->normalizeTiktokPriceValue(data_get($priceNode, 'amount', $salePrice))
            : $salePrice;

        return array_filter([
            'currency' => $currency !== '' ? $currency : 'IDR',
            'sale_price' => $salePrice,
            'tax_exclusive_price' => $taxExclusivePrice !== '' ? $taxExclusivePrice : $salePrice,
            'amount' => $amount !== '' ? $amount : $salePrice,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function normalizeTiktokPriceValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = data_get($value, 'sale_price', data_get($value, 'amount', data_get($value, 'tax_exclusive_price')));
        }

        if ($value === null || $value === '') {
            return '';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $normalized = preg_replace('/[^\d.]/', '', (string) $value) ?: '';

        return trim($normalized);
    }

    private function buildTiktokPartialEditSkuInventory(array $sku): array
    {
        $inventoryRows = data_get($sku, 'inventory', data_get($sku, 'inventories', []));
        if (! is_array($inventoryRows) || $inventoryRows === []) {
            $stock = data_get($sku, 'stock', data_get($sku, 'stock_qty'));
            if ($stock === null || $stock === '') {
                return [];
            }

            $row = ['quantity' => max(0, (int) $stock)];
            $warehouseId = trim((string) config('tiktok.default_warehouse_id', ''));
            if ($warehouseId !== '') {
                $row['warehouse_id'] = $warehouseId;
            }

            return [$row];
        }

        $rows = [];
        foreach ($inventoryRows as $inventory) {
            if (! is_array($inventory)) {
                continue;
            }

            $quantity = data_get($inventory, 'quantity', data_get($inventory, 'stock'));
            if ($quantity === null || $quantity === '') {
                continue;
            }

            $row = ['quantity' => max(0, (int) $quantity)];
            $warehouseId = trim((string) data_get($inventory, 'warehouse_id', data_get($inventory, 'warehouse.id', '')));
            if ($warehouseId !== '') {
                $row['warehouse_id'] = $warehouseId;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function tiktokDeleteVariantConfirmationSkus(string $productId, string $skuId, object $sku): array
    {
        $candidates = [];
        $add = function (mixed $value) use (&$candidates): void {
            $skuValue = trim((string) ($value ?? ''));
            if ($skuValue !== '') {
                $candidates[] = $skuValue;
            }
        };

        DB::table('sku_mappings as map')
            ->leftJoin('stock_master as sm', 'sm.id', '=', 'map.stock_master_id')
            ->where('map.tiktok_product_id', $productId)
            ->where('map.tiktok_sku_id', $skuId)
            ->select('map.seller_sku', 'sm.internal_sku', 'sm.tiktok_seller_sku')
            ->get()
            ->each(function ($row) use ($add): void {
                $add($row->internal_sku ?? null);
                $add($row->seller_sku ?? null);
                $add($row->tiktok_seller_sku ?? null);
            });

        DB::table('stock_master')
            ->where('tiktok_product_id', $productId)
            ->where('tiktok_sku', $skuId)
            ->select('internal_sku', 'tiktok_seller_sku')
            ->get()
            ->each(function ($row) use ($add): void {
                $add($row->internal_sku ?? null);
                $add($row->tiktok_seller_sku ?? null);
            });

        $add($sku->seller_sku ?? null);
        $add($this->tiktokSkuVariationCode($productId, $sku));

        return collect($candidates)
            ->filter()
            ->unique(fn (string $value): string => $this->normalizeSkuMatchValue($value))
            ->values()
            ->all();
    }

    public function tiktokDeleteVariant(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'product_id' => ['required', 'string'],
            'sku_id' => ['required', 'string'],
            'confirm_mapping_sku' => ['required', 'string'],
        ]);

        $productId = trim((string) $data['product_id']);
        $skuId = trim((string) $data['sku_id']);
        $confirmMappingSku = trim((string) $data['confirm_mapping_sku']);

        abort_if($productId === '' || $skuId === '', 422, 'Product ID dan SKU ID TikTok wajib diisi.');
        abort_if($confirmMappingSku === '', 422, 'SKU Mapping wajib diisi untuk konfirmasi hapus varian.');

        $sku = DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->where('sku_id', $skuId)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->first();
        abort_if(! $sku, 422, 'Varian TikTok tidak ditemukan di cache lokal. Klik Sync produk dulu.');

        $confirmationSkus = $this->tiktokDeleteVariantConfirmationSkus($productId, $skuId, $sku);
        $confirmKey = $this->normalizeSkuMatchValue($confirmMappingSku);
        $confirmationValid = collect($confirmationSkus)
            ->contains(fn (string $candidate): bool => $this->normalizeSkuMatchValue($candidate) === $confirmKey);
        abort_if(! $confirmationValid, 422, 'Konfirmasi belum cocok. Ketik SKU Mapping varian yang akan dihapus.');

        $activeSkuCount = DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->count();
        abort_if($activeSkuCount <= 1, 422, 'Varian terakhir tidak bisa dihapus dari tool ini. Nonaktifkan/hapus produk dari Seller Center jika memang mau menghapus produk.');

        $stockMasterId = DB::table('stock_master')
            ->where('tiktok_product_id', $productId)
            ->where('tiktok_sku', $skuId)
            ->value('id');
        $oldSellerSku = (string) ($sku->seller_sku ?? '');
        $tiktokPath = '/product/202509/products/'.$productId.'/partial_edit';
        $requestPayload = [
            'method' => 'POST',
            'path' => $tiktokPath,
            'body' => null,
        ];
        $result = [
            'status' => 'ok',
            'message' => 'Varian TikTok berhasil dihapus dari marketplace dan cache lokal.',
            'product_id' => $productId,
            'sku_id' => $skuId,
            'sku_name' => (string) ($sku->sku_name ?? ''),
            'mapping_sku' => $confirmationSkus[0] ?? null,
            'request' => $requestPayload,
            'response' => null,
            'cleanup' => null,
        ];

        try {
            $this->autoRefreshMarketplaceTokens();

            $context = $this->resolveTiktokGetProductContext(['version' => '202509']);
            $accessToken = trim((string) ($context['access_token'] ?? ''));
            $shopId = trim((string) ($context['shop_id'] ?? ''));
            $shopCipher = trim((string) ($context['shop_cipher'] ?? ''));
            abort_if($accessToken === '', 422, 'Token TikTok belum aktif.');
            abort_if($shopId === '' || $shopCipher === '', 422, 'Shop TikTok belum lengkap.');

            $config = $this->tiktokConfig();
            $detail = $this->fetchTiktokProductDetail(
                $config,
                $accessToken,
                (object) ['shop_id' => $shopId, 'shop_cipher' => $shopCipher, 'cipher' => $shopCipher],
                $productId,
                $config['api_host'].'/product/202309/products/'
            );
            abort_if(! is_array($detail), 422, 'Detail produk TikTok belum bisa dibaca untuk menjaga SKU lain tidak terhapus.');
            $detailPayload = is_array($detail['product'] ?? null) ? $detail['product'] : $detail;

            $skuRows = $this->buildTiktokPartialEditSkuDeleteRows(
                $detailPayload,
                $skuId,
                $this->tiktokDeletedVariantIdsForProduct($productId, [$skuId])
            );
            abort_if($skuRows === [], 422, 'SKU TikTok target tidak ditemukan di detail produk terbaru atau varian tersisa kosong.');

            $payload = [
                'save_mode' => 'LISTING',
                'skus' => $skuRows,
            ];
            $requestPayload['body'] = $payload;
            $result['request'] = $requestPayload;

            $response = $this->submitTiktokPartialEditPayload($tiktokPath, $payload, $context);
            $result['response'] = $response;
            $result['status'] = (int) ($response['code'] ?? -1) === 0 ? 'ok' : 'error';

            if ($result['status'] === 'ok') {
                $result['cleanup'] = $this->deleteTiktokVariantFromLocalCache($productId, $skuId);
            } else {
                $result['message'] = $response['message'] ?? 'TikTok menolak hapus varian.';
            }
        } catch (\Throwable $exception) {
            $result['status'] = 'error';
            $result['message'] = $exception->getMessage();
            $result['response'] = ['message' => $exception->getMessage()];
        }

        $this->recordMarketplaceSkuChange(
            $stockMasterId ? (int) $stockMasterId : null,
            'tiktok',
            $productId,
            $skuId,
            $oldSellerSku,
            null,
            'variant_delete',
            $result['status'],
            $result['request'],
            $result['response'],
            $result['message']
        );

        return response()->json($result, $result['status'] === 'error' ? 422 : 200);
    }

    private function deleteTiktokVariantFromLocalCache(string $productId, string $skuId): array
    {
        $now = now();
        $stockMasterIds = DB::table('stock_master')
            ->where('tiktok_product_id', $productId)
            ->where('tiktok_sku', $skuId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $updatedProducts = DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->where('sku_id', $skuId)
            ->update([
                'stock_qty' => 0,
                'subtotal' => 0,
                'is_active' => DB::raw('false'),
                'updated_at' => $now,
            ]);

        $updatedStockMasters = 0;
        if ($stockMasterIds !== []) {
            $updatedStockMasters = DB::table('stock_master')
                ->whereIn('id', $stockMasterIds)
                ->update([
                    'tiktok_product_id' => null,
                    'tiktok_sku' => null,
                    'tiktok_seller_sku' => null,
                    'is_hidden_from_mapping' => DB::raw('true'),
                    'hidden_from_mapping_reason' => 'Varian TikTok dihapus dari marketplace.',
                    'hidden_from_mapping_at' => $now,
                    'hidden_from_mapping_by' => 'system',
                    'updated_at' => $now,
                ]);
        }

        $updatedSkuMappings = DB::table('sku_mappings')
            ->where('tiktok_product_id', $productId)
            ->where('tiktok_sku_id', $skuId)
            ->update([
                'tiktok_product_id' => null,
                'tiktok_sku_id' => null,
                'tiktok_sku_name' => null,
                'tiktok_image_url' => null,
                'updated_at' => $now,
            ]);

        return [
            'updated_products' => $updatedProducts,
            'updated_stock_masters' => $updatedStockMasters,
            'updated_sku_mappings' => $updatedSkuMappings,
        ];
    }

    private function submitTiktokPartialEditPayload(string $path, array $payload, array $context): array
    {
        $config = $this->tiktokConfig();
        $accessToken = trim((string) ($context['access_token'] ?? ''));
        $shopCipher = trim((string) ($context['shop_cipher'] ?? ''));
        $shopId = trim((string) ($context['shop_id'] ?? ''));
        $payloadBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payloadBody === false) {
            throw new \RuntimeException('Payload TikTok tidak bisa diencode.');
        }

        $query = [
            'access_token' => $accessToken,
            'app_key' => $config['app_key'],
            'shop_cipher' => $shopCipher,
            'shop_id' => $shopId,
            'timestamp' => time(),
            'version' => '202509',
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, $config['app_secret'], $payloadBody);

        $response = Http::timeout(45)
            ->withHeaders([
                'x-tts-access-token' => $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->withBody($payloadBody, 'application/json')
            ->post($config['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return [
                'code' => $response->status(),
                'message' => 'TikTok tidak mengembalikan JSON valid.',
                'raw' => $response->body(),
            ];
        }

        return [
            ...$decoded,
            '_http_status' => $response->status(),
        ];
    }

    private function loadSkuMappingVariantStockRow(int $stockMasterId): ?object
    {
        return DB::table('stock_master as sm')
            ->leftJoin('sku_mappings as map', 'map.stock_master_id', '=', 'sm.id')
            ->leftJoin('shopee_product_model as spm', function ($join) {
                $join->on('spm.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin('shopee_product as sp', function ($join) {
                $join->on('sp.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, model_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NOT NULL GROUP BY item_id, model_id) as spmi'), function ($join) {
                $join->on('spmi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"))
                    ->on('spmi.model_id', '=', DB::raw("COALESCE(NULLIF(map.shopee_model_id, ''), NULLIF(sm.shopee_sku, ''))"));
            })
            ->leftJoin(DB::raw('(SELECT item_id, MIN(image_url) as image_url FROM shopee_product_image WHERE model_id IS NULL GROUP BY item_id) as spi'), function ($join) {
                $join->on('spi.item_id', '=', DB::raw("NULLIF(COALESCE(NULLIF(map.shopee_item_id, ''), NULLIF(sm.shopee_product_id, '')), '')::BIGINT"));
            })
            ->where('sm.id', $stockMasterId)
            ->select(
                'sm.id',
                'sm.internal_sku',
                'sm.product_name',
                'sm.variant_name',
                'sm.stock_qty',
                'sm.shopee_product_id',
                'sm.shopee_sku',
                'sm.shopee_seller_sku',
                'sm.tiktok_product_id',
                'sm.tiktok_sku',
                'sm.tiktok_seller_sku',
                'map.seller_sku as mapped_seller_sku',
                'map.shopee_item_id',
                'map.shopee_model_id',
                'map.tiktok_product_id as mapped_tiktok_product_id',
                'map.tiktok_sku_id',
                'map.tiktok_sku_name',
                'map.tiktok_image_url',
                'map.shopee_image_url',
                'map.internal_image_url',
                'sp.name as shopee_product_name',
                'spm.name as shopee_variant_name',
                'spm.price as shopee_variant_price',
                'spm.stock as shopee_variant_stock',
                'spmi.image_url as shopee_model_image_url',
                'spi.image_url as shopee_product_image_url'
            )
            ->first();
    }

    public function prepareMissingVariant(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'stock_master_id' => ['required', 'integer'],
            'target_channel' => ['required', 'in:shopee,tiktok'],
        ]);

        $stock = $this->loadSkuMappingVariantStockRow((int) $data['stock_master_id']);

        abort_if(! $stock, 404, 'Varian tidak ditemukan.');

        $targetChannel = $data['target_channel'];
        $sourceChannel = $targetChannel === 'tiktok' ? 'shopee' : 'tiktok';
        $draftPayload = null;
        $actionStatus = 'ready_to_create';
        $actionMessage = 'Draft varian berhasil disiapkan.';
        $actionPayload = null;

        if ($targetChannel === 'tiktok') {
            $sourceProductId = trim((string) ($stock->shopee_product_id ?? ''));
            $sourceModelId = trim((string) ($stock->shopee_sku ?? ''));
            $sourceVariantName = trim((string) ($stock->shopee_variant_name ?? $stock->variant_name ?? ''));
            $sourceSellerSku = trim((string) ($stock->mapped_seller_sku ?? $stock->shopee_seller_sku ?? ''));
            $sourceImageUrl = $stock->shopee_model_image_url ?: $stock->internal_image_url ?: $stock->shopee_image_url ?: $stock->shopee_product_image_url;

            abort_if($sourceProductId === '' && $sourceModelId === '' && $sourceVariantName === '', 422, 'Data Shopee belum cukup untuk membuat draft TikTok.');

            $draftPayload = [
                'target_channel' => 'tiktok',
                'source_channel' => 'shopee',
                'stock_master_id' => (int) $stock->id,
                'product_name' => $stock->product_name,
                'variant_name' => $stock->variant_name,
                'source' => [
                    'item_id' => $sourceProductId ?: null,
                    'model_id' => $sourceModelId ?: null,
                    'variant_name' => $sourceVariantName ?: null,
                    'seller_sku' => $sourceSellerSku ?: null,
                    'image_url' => $sourceImageUrl ?: null,
                    'stock_qty' => (int) ($stock->stock_qty ?? 0),
                    'price' => (int) ($stock->shopee_variant_price ?? 0),
                ],
                'target' => [
                    'variant_name' => $stock->variant_name,
                    'seller_sku' => $sourceSellerSku ?: $stock->internal_sku,
                    'image_url' => $sourceImageUrl ?: null,
                    'stock_qty' => (int) ($stock->stock_qty ?? 0),
                ],
            ];

            $actionPayload = $draftPayload;

            $accessToken = $this->activeTiktokAccessTokenForSync();
            $shop = $this->latestTiktokShop();

            if ($accessToken !== '' && $shop) {
                try {
                    $existingProduct = DB::table('tiktok_products')
                        ->whereRaw('COALESCE(is_active, true) = true')
                        ->whereRaw('LOWER(TRIM(product_name)) = LOWER(TRIM(?))', [$stock->product_name ?? ''])
                        ->orderByDesc('updated_at')
                        ->first();

                    $mutationResult = $this->submitTiktokVariantMutation(
                        $stock,
                        $draftPayload,
                        $shop,
                        $accessToken,
                        $existingProduct ? (array) $existingProduct : null
                    );

                    $actionPayload = array_merge($draftPayload, ['mutation' => $mutationResult]);

                    if (($mutationResult['ok'] ?? false) === true) {
                        $actionStatus = 'submitted';
                        $actionMessage = $mutationResult['message'] ?? 'Varian berhasil dikirim ke TikTok.';

                        $returnedProductId = trim((string) ($mutationResult['product_id'] ?? ''));
                        $returnedSkuId = trim((string) ($mutationResult['sku_id'] ?? ''));

                        if ($returnedProductId !== '' || $returnedSkuId !== '') {
                            DB::table('sku_mappings')->updateOrInsert(
                                ['stock_master_id' => $stock->id],
                                [
                                    'tiktok_product_id' => $returnedProductId !== '' ? $returnedProductId : ($stock->tiktok_product_id ?? null),
                                    'tiktok_sku_id' => $returnedSkuId !== '' ? $returnedSkuId : ($stock->tiktok_sku ?? null),
                                    'tiktok_sku_name' => $draftPayload['target']['variant_name'] ?? $stock->variant_name,
                                    'seller_sku' => $draftPayload['target']['seller_sku'] ?? $stock->internal_sku,
                                    'tiktok_image_url' => $draftPayload['target']['image_url'] ?? null,
                                    'updated_at' => now(),
                                    'created_at' => now(),
                                ]
                            );

                            DB::table('stock_master')
                                ->where('id', $stock->id)
                                ->update([
                                    'tiktok_product_id' => $returnedProductId !== '' ? $returnedProductId : ($stock->tiktok_product_id ?? null),
                                    'tiktok_sku' => $returnedSkuId !== '' ? $returnedSkuId : ($stock->tiktok_sku ?? null),
                                    'tiktok_seller_sku' => $draftPayload['target']['seller_sku'] ?? $stock->internal_sku,
                                    'updated_at' => now(),
                                ]);
                        }
                    } else {
                        $actionStatus = 'failed';
                        $actionMessage = $mutationResult['message'] ?? 'Gagal mengirim varian ke TikTok.';
                    }
                } catch (\Throwable $exception) {
                    $actionStatus = 'failed';
                    $actionMessage = 'Gagal mencoba kirim ke TikTok: '.$exception->getMessage();
                    $actionPayload = array_merge($draftPayload, ['mutation_error' => $exception->getMessage()]);
                }
            } else {
                $actionMessage = 'Draft varian disiapkan, tetapi token atau shop TikTok belum lengkap untuk mengirim request.';
            }
        } else {
            $tiktokSource = DB::table('tiktok_products')
                ->whereRaw('COALESCE(is_active, true) = true')
                ->where(function ($query) use ($stock) {
                    $query->where(function ($sub) use ($stock) {
                        $sub->where('product_id', (string) ($stock->tiktok_product_id ?? ''))
                            ->where(function ($inner) use ($stock) {
                                $inner->where('sku_id', (string) ($stock->tiktok_sku ?? ''))
                                    ->orWhereRaw('LOWER(TRIM(sku_name)) = LOWER(TRIM(?))', [$stock->variant_name ?? '']);
                            });
                    })
                    ->orWhereRaw('LOWER(TRIM(product_name)) = LOWER(TRIM(?))', [$stock->product_name ?? '']);
                })
                ->orderByDesc('updated_at')
                ->first();

            abort_if(! $tiktokSource, 422, 'Data TikTok belum cukup untuk membuat draft Shopee.');

            $draftPayload = [
                'target_channel' => 'shopee',
                'source_channel' => 'tiktok',
                'stock_master_id' => (int) $stock->id,
                'product_name' => $stock->product_name,
                'variant_name' => $stock->variant_name,
                'source' => [
                    'product_id' => $tiktokSource->product_id,
                    'sku_id' => $tiktokSource->sku_id,
                    'sku_name' => $tiktokSource->sku_name,
                    'seller_sku' => $tiktokSource->seller_sku ?? null,
                    'image_url' => $tiktokSource->image_url ?? null,
                    'stock_qty' => (int) ($tiktokSource->stock_qty ?? 0),
                    'price' => (int) ($tiktokSource->price ?? 0),
                ],
                'target' => [
                    'variant_name' => $stock->variant_name,
                    'seller_sku' => $tiktokSource->seller_sku ?? $stock->internal_sku,
                    'image_url' => $tiktokSource->image_url ?? null,
                    'stock_qty' => (int) ($tiktokSource->stock_qty ?? 0),
                ],
            ];
        }

        DB::table('sku_variant_actions')->updateOrInsert(
            [
                'stock_master_id' => $stock->id,
                'target_channel' => $targetChannel,
                'action_type' => 'create_variant',
            ],
            [
                'source_channel' => $sourceChannel,
                'payload' => json_encode($actionPayload ?? $draftPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $actionStatus,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'message' => $actionMessage,
            'draft' => $draftPayload,
            'action_status' => $actionStatus,
        ]);
    }

    public function tiktokVariantAction(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'stock_master_id' => ['required', 'integer'],
            'action' => ['required', 'in:upload_image,save_product,update_inventory,full_sync'],
            'warehouse_id' => ['nullable', 'string'],
            'quantity' => ['nullable', 'integer'],
        ]);

        $stock = $this->loadSkuMappingVariantStockRow((int) $data['stock_master_id']);
        abort_if(! $stock, 404, 'Varian tidak ditemukan.');

        $sourceProductId = trim((string) ($stock->shopee_product_id ?? ''));
        $sourceModelId = trim((string) ($stock->shopee_sku ?? ''));
        $sourceVariantName = trim((string) ($stock->shopee_variant_name ?? $stock->variant_name ?? ''));
        $sourceSellerSku = trim((string) ($stock->mapped_seller_sku ?? $stock->shopee_seller_sku ?? ''));
        $sourceImageUrl = $stock->shopee_model_image_url ?: $stock->internal_image_url ?: $stock->shopee_image_url ?: $stock->shopee_product_image_url;

        abort_if($sourceProductId === '' && $sourceModelId === '' && $sourceVariantName === '', 422, 'Data Shopee belum cukup untuk menyiapkan payload TikTok.');

        $draftPayload = [
            'target_channel' => 'tiktok',
            'source_channel' => 'shopee',
            'stock_master_id' => (int) $stock->id,
            'product_name' => $stock->product_name,
            'variant_name' => $stock->variant_name,
            'source' => [
                'item_id' => $sourceProductId ?: null,
                'model_id' => $sourceModelId ?: null,
                'variant_name' => $sourceVariantName ?: null,
                'seller_sku' => $sourceSellerSku ?: null,
                'image_url' => $sourceImageUrl ?: null,
                'stock_qty' => (int) ($stock->stock_qty ?? 0),
                'price' => (int) ($stock->shopee_variant_price ?? 0),
            ],
            'target' => [
                'variant_name' => $stock->variant_name,
                'seller_sku' => $sourceSellerSku ?: $stock->internal_sku,
                'image_url' => $sourceImageUrl ?: null,
                'stock_qty' => (int) ($stock->stock_qty ?? 0),
            ],
        ];

        $accessToken = $this->activeTiktokAccessTokenForSync();
        abort_if($accessToken === '', 422, 'Token TikTok belum aktif. Jalankan login/refresh token dulu.');

        $action = (string) $data['action'];
        $shop = $this->latestTiktokShop();
        $actionStatus = 'ready_to_create';
        $actionMessage = 'Action TikTok berhasil disiapkan.';
        $actionPayload = $draftPayload;
        $uploadResult = null;
        $mutationResult = null;
        $inventoryResult = null;

        if (in_array($action, ['upload_image', 'save_product', 'full_sync'], true)) {
            $uploadResult = $this->uploadTiktokProductImage(
                $shop ?? (object) [],
                $accessToken,
                (string) ($sourceImageUrl ?: ''),
                'MAIN_IMAGE'
            );

            $actionPayload['upload'] = $uploadResult;

            if (($uploadResult['ok'] ?? false) !== true) {
                $actionStatus = 'failed';
                $actionMessage = $uploadResult['message'] ?? 'Gagal upload gambar ke TikTok.';
            } elseif ($action === 'upload_image') {
                $actionStatus = 'uploaded_image';
                $actionMessage = $uploadResult['message'] ?? 'Gambar berhasil diupload ke TikTok.';
            }
        }

        if (in_array($action, ['save_product', 'full_sync'], true) && $actionStatus !== 'failed') {
            $shop = $shop ?? $this->latestTiktokShop();
            abort_if(! $shop, 422, 'Shop TikTok belum tersedia. Klik Get Auth Shop dulu.');

            $existingProduct = DB::table('tiktok_products')
                ->whereRaw('COALESCE(is_active, true) = true')
                ->whereRaw('LOWER(TRIM(product_name)) = LOWER(TRIM(?))', [$stock->product_name ?? ''])
                ->orderByDesc('updated_at')
                ->first();

            $mutationResult = $this->submitTiktokVariantMutation(
                $stock,
                $draftPayload,
                $shop,
                $accessToken,
                $existingProduct ? (array) $existingProduct : null,
                ['uploaded_image_uri' => data_get($uploadResult, 'uri')]
            );

            $actionPayload['mutation'] = $mutationResult;

            if (($mutationResult['ok'] ?? false) !== true) {
                $actionStatus = 'failed';
                $actionMessage = $mutationResult['message'] ?? 'Gagal mengirim produk ke TikTok.';
            } else {
                $actionStatus = 'submitted';
                $actionMessage = $mutationResult['message'] ?? 'Produk TikTok berhasil dikirim.';

                $returnedProductId = trim((string) ($mutationResult['product_id'] ?? ''));
                $returnedSkuId = trim((string) ($mutationResult['sku_id'] ?? ''));

                if ($returnedProductId !== '' || $returnedSkuId !== '') {
                    DB::table('sku_mappings')->updateOrInsert(
                        ['stock_master_id' => $stock->id],
                        [
                            'tiktok_product_id' => $returnedProductId !== '' ? $returnedProductId : ($stock->tiktok_product_id ?? null),
                            'tiktok_sku_id' => $returnedSkuId !== '' ? $returnedSkuId : ($stock->tiktok_sku ?? null),
                            'tiktok_sku_name' => $draftPayload['target']['variant_name'] ?? $stock->variant_name,
                            'seller_sku' => $draftPayload['target']['seller_sku'] ?? $stock->internal_sku,
                            'tiktok_image_url' => data_get($uploadResult, 'uri') ?: ($draftPayload['target']['image_url'] ?? null),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );

                    DB::table('stock_master')
                        ->where('id', $stock->id)
                        ->update([
                            'tiktok_product_id' => $returnedProductId !== '' ? $returnedProductId : ($stock->tiktok_product_id ?? null),
                            'tiktok_sku' => $returnedSkuId !== '' ? $returnedSkuId : ($stock->tiktok_sku ?? null),
                            'tiktok_seller_sku' => $draftPayload['target']['seller_sku'] ?? $stock->internal_sku,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        if (in_array($action, ['update_inventory', 'full_sync'], true) && $actionStatus !== 'failed') {
            $shop = $shop ?? $this->latestTiktokShop();
            abort_if(! $shop, 422, 'Shop TikTok belum tersedia. Klik Get Auth Shop dulu.');

            $warehouseId = trim((string) ($data['warehouse_id'] ?? ''));
            abort_if($warehouseId === '', 422, 'Warehouse ID TikTok wajib diisi untuk update inventory.');

            $productId = trim((string) (
                data_get($mutationResult, 'product_id')
                ?: ($stock->mapped_tiktok_product_id ?? $stock->tiktok_product_id ?? '')
            ));
            $skuId = trim((string) (
                data_get($mutationResult, 'sku_id')
                ?: ($stock->tiktok_sku ?? $stock->tiktok_sku_id ?? '')
            ));

            abort_if($productId === '' || $skuId === '', 422, 'Product ID atau SKU ID TikTok belum tersedia untuk update inventory.');

            $quantity = (int) ($data['quantity'] ?? ($stock->stock_qty ?? 0));
            $inventoryResult = $this->updateTiktokInventory($shop, $accessToken, $productId, $skuId, $warehouseId, $quantity);
            $actionPayload['inventory'] = $inventoryResult;

            if (($inventoryResult['ok'] ?? false) !== true) {
                $actionStatus = 'failed';
                $actionMessage = $inventoryResult['message'] ?? 'Gagal update inventory TikTok.';
            } else {
                $actionStatus = 'inventory_updated';
                $actionMessage = $inventoryResult['message'] ?? 'Inventory TikTok berhasil diperbarui.';
            }
        }

        DB::table('sku_variant_actions')->updateOrInsert(
            [
                'stock_master_id' => $stock->id,
                'target_channel' => 'tiktok',
                'action_type' => 'tiktok_'.$action,
            ],
            [
                'source_channel' => 'shopee',
                'payload' => json_encode($actionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $actionStatus,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'message' => $actionMessage,
            'draft' => $draftPayload,
            'action_status' => $actionStatus,
            'upload' => $uploadResult,
            'mutation' => $mutationResult,
            'inventory' => $inventoryResult,
        ]);
    }

    public function shopeeApiTest(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'api_name' => ['required', 'in:get_item_base_info,get_model_list'],
            'item_id' => ['required', 'string'],
            'shop_id' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'account_key' => ['nullable', 'string'],
            'need_tax_info' => ['nullable'],
            'need_complaint_policy' => ['nullable'],
        ]);

        $config = $this->shopeeConfig();
        $context = $this->resolveShopeeApiTestContext($data);
        $shopId = (int) ($context['shop_id'] ?? 0);
        $accessToken = trim((string) ($context['access_token'] ?? ''));

        abort_if($shopId <= 0 || $accessToken === '', 422, 'Token Shopee aktif belum lengkap. Jalankan AUTH / REFRESH Shopee dulu.');

        $itemIds = collect(preg_split('/[\s,]+/', trim((string) $data['item_id']), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => preg_match('/^\d+$/', $value) === 1)
            ->values()
            ->all();

        abort_if($itemIds === [], 422, 'Item ID Shopee wajib diisi dengan angka.');

        $apiName = (string) $data['api_name'];
        $path = $apiName === 'get_model_list'
            ? '/api/v2/product/get_model_list'
            : '/api/v2/product/get_item_base_info';

        $params = $apiName === 'get_model_list'
            ? ['item_id' => $itemIds[0]]
            : [
                'item_id_list' => implode(',', $itemIds),
                'need_tax_info' => $this->boolString($data['need_tax_info'] ?? 'false'),
                'need_complaint_policy' => $this->boolString($data['need_complaint_policy'] ?? 'false'),
            ];

        $timestamp = time();
        $query = [
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'shop_id' => $shopId,
            'sign' => $this->generateShopeeApiSign($config['partner_id'], $config['partner_key'], $path, $timestamp, $accessToken, $shopId),
            ...$params,
        ];

        try {
            $response = Http::timeout(45)
                ->acceptJson()
                ->get($config['host'].$path, $query);
        } catch (\Throwable $exception) {
            logger()->warning('Shopee API testing request exception', [
                'api_name' => $apiName,
                'item_id' => $data['item_id'],
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Shopee API testing request gagal dipanggil.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        $body = $response->body();
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            logger()->warning('Shopee API testing returned invalid JSON', [
                'api_name' => $apiName,
                'item_id' => $data['item_id'],
                'http_status' => $response->status(),
                'body' => $body,
            ]);

            return response()->json([
                'message' => 'Shopee tidak mengembalikan JSON valid.',
                'raw' => $body,
            ], 502);
        }

        return response($body, $response->status())
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    public function shopeeApiTestContext(): JsonResponse
    {
        $context = $this->resolveShopeeApiTestContext([]);

        abort_if(trim((string) ($context['access_token'] ?? '')) === '', 422, 'Token Shopee aktif belum tersedia.');
        abort_if((int) ($context['shop_id'] ?? 0) <= 0, 422, 'Shop ID Shopee aktif belum tersedia.');

        return response()->json([
            'status' => 'ok',
            'message' => 'Context Shopee aktif berhasil diambil.',
            'data' => [
                'account_key' => $context['account_key'] ?? null,
                'account_name' => $context['account_name'] ?? null,
                'shop_id' => (string) ($context['shop_id'] ?? ''),
                'access_token' => $context['access_token'] ?? null,
            ],
        ]);
    }

    public function shopeeAddVariant(Request $request): JsonResponse
    {
        $this->ensureSkuMappingTables();

        $data = $request->validate([
            'item_id' => ['required', 'string'],
            'shop_id' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'account_key' => ['nullable', 'string'],
            'dry_run' => ['nullable'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.stock_master_id' => ['nullable'],
            'variants.*.variant_name' => ['required', 'string'],
            'variants.*.seller_sku' => ['nullable', 'string'],
            'variants.*.price' => ['nullable'],
            'variants.*.stock' => ['nullable'],
            'variants.*.image_url' => ['nullable', 'string'],
            'variants.*.tiktok_product_id' => ['nullable', 'string'],
            'variants.*.tiktok_sku_id' => ['nullable', 'string'],
        ]);

        $config = $this->shopeeConfig();
        $context = $this->resolveShopeeApiTestContext($data);
        $shopId = (int) ($context['shop_id'] ?? 0);
        $accessToken = trim((string) ($context['access_token'] ?? ''));
        $itemId = (int) trim((string) $data['item_id']);
        $dryRun = $this->boolString($data['dry_run'] ?? true) === 'true';

        abort_if($itemId <= 0, 422, 'Item ID Shopee wajib diisi.');
        abort_if($shopId <= 0 || $accessToken === '', 422, 'Token Shopee aktif belum lengkap. Jalankan AUTH / REFRESH Shopee dulu.');

        $modelPayload = $this->fetchShopeeModelList($config, $shopId, $accessToken, $itemId);
        $models = data_get($modelPayload, 'model', []);
        $standardiseTierVariation = data_get($modelPayload, 'standardise_tier_variation', []);
        $tierVariation = data_get($modelPayload, 'tier_variation', []);
        $useStandardiseTier = is_array($standardiseTierVariation) && $standardiseTierVariation !== [];
        $activeTierVariation = $useStandardiseTier ? $standardiseTierVariation : $tierVariation;

        abort_if(! is_array($activeTierVariation) || $activeTierVariation === [], 422, 'Produk Shopee belum punya tier variation. Untuk produk tanpa varian perlu init_tier_variation dulu.');
        abort_if(count($activeTierVariation) > 2, 422, 'Tool ini sementara hanya menangani produk Shopee maksimal 2 level varian.');

        $requestedVariants = $this->normalizeShopeeAddVariantRows($data['variants'], (string) $itemId);
        abort_if($requestedVariants === [], 422, 'Tidak ada varian valid untuk ditambahkan ke Shopee.');

        $tierCount = count($activeTierVariation);
        $targetTierIndex = $this->resolveShopeeAddVariantTierIndex($activeTierVariation, $useStandardiseTier, $requestedVariants);
        $defaultTierIndexes = $this->defaultShopeeTierIndexes(is_array($models) ? $models : [], $tierCount);

        $tierOptionsByTier = [];
        $optionIndexByTierName = [];
        foreach ($activeTierVariation as $tierIndex => $tier) {
            $tierOptionsByTier[$tierIndex] = $this->shopeeTierOptionList(is_array($tier) ? $tier : [], $useStandardiseTier);
            $optionIndexByTierName[$tierIndex] = [];

            foreach ($tierOptionsByTier[$tierIndex] as $index => $option) {
                $name = $this->shopeeTierOptionName($option, $useStandardiseTier);
                $key = $this->normalizeSkuMatchValue($name);
                if ($key !== '') {
                    $optionIndexByTierName[$tierIndex][$key] = (int) $index;
                }
            }
        }

        $existingModelTierKeys = [];
        $existingModelByTierKey = [];
        $existingModelList = [];
        $sellerStockLocationId = '';
        $modelWeight = null;

        foreach (is_array($models) ? $models : [] as $model) {
            if (! is_array($model)) {
                continue;
            }

            $tierIndex = array_map('intval', $this->normalizeShopeeIndexList($model['tier_index'] ?? []));
            if ($tierIndex !== []) {
                $tierKey = implode('|', $tierIndex);
                $existingModelTierKeys[$tierKey] = true;
                $existingModelByTierKey[$tierKey] = $model;
            }

            if (! empty($model['model_id'])) {
                $existingModelList[] = [
                    'model_id' => (int) $model['model_id'],
                    'tier_index' => $tierIndex,
                ];
            }

            if ($sellerStockLocationId === '') {
                $sellerStockLocationId = trim((string) data_get($model, 'stock_info_v2.seller_stock.0.location_id', ''));
            }

            if ($modelWeight === null && isset($model['weight']) && is_numeric($model['weight'])) {
                $modelWeight = (float) $model['weight'];
            }
        }

        $newOptionCount = 0;
        $addModels = [];
        $updateModels = [];
        $plannedVariants = [];
        $plannedUpdateVariants = [];
        $skippedVariants = [];
        $seenVariantNames = [];

        foreach ($requestedVariants as $variant) {
            $variantName = $variant['variant_name'];
            $tierValues = $this->splitShopeeVariantTierValues($variantName, $tierCount, $targetTierIndex);
            foreach ($tierValues as $tierIndex => $value) {
                if (trim((string) $value) !== '') {
                    continue;
                }

                $fallbackIndex = (int) ($defaultTierIndexes[$tierIndex] ?? 0);
                $tierValues[$tierIndex] = $this->fallbackShopeeTierOptionName($tierOptionsByTier[$tierIndex] ?? [], $fallbackIndex, $useStandardiseTier);
            }
            $variantKey = implode('|', array_map(fn ($value) => $this->normalizeSkuMatchValue($value), $tierValues));
            if ($variantKey === '' || isset($seenVariantNames[$variantKey])) {
                continue;
            }
            $seenVariantNames[$variantKey] = true;

            $imageUpload = null;
            $imageId = '';
            $modelTierIndex = $defaultTierIndexes;
            $allOptionsAlreadyExist = true;

            foreach ($tierValues as $tierIndex => $tierValue) {
                $tierValue = trim((string) $tierValue);
                $tierValueKey = $this->normalizeSkuMatchValue($tierValue);
                if ($tierValueKey === '') {
                    continue;
                }

                $optionAlreadyExists = array_key_exists($tierValueKey, $optionIndexByTierName[$tierIndex] ?? []);
                if (! $optionAlreadyExists) {
                    $allOptionsAlreadyExist = false;
                    if ($tierIndex === $targetTierIndex && ! $dryRun && $variant['image_url'] !== '' && $imageUpload === null) {
                        $imageUpload = $this->uploadShopeeProductImage($config, $variant['image_url']);
                        if (($imageUpload['ok'] ?? false) !== true) {
                            return response()->json([
                                'status' => 'error',
                                'message' => $imageUpload['message'] ?? 'Upload gambar Shopee gagal.',
                                'variant' => $variantName,
                                'upload' => $imageUpload,
                            ], 422);
                        }

                        $imageId = trim((string) ($imageUpload['image_id'] ?? ''));
                    }

                    $optionIndex = count($tierOptionsByTier[$tierIndex] ?? []);
                    $tierOptionsByTier[$tierIndex][] = $this->buildShopeeTierOption(
                        $tierValue,
                        $useStandardiseTier,
                        $tierIndex === $targetTierIndex ? $imageId : '',
                        $tierIndex === $targetTierIndex ? $variant['image_url'] : ''
                    );
                    $optionIndexByTierName[$tierIndex][$tierValueKey] = $optionIndex;
                    $newOptionCount += 1;
                } else {
                    $optionIndex = $optionIndexByTierName[$tierIndex][$tierValueKey];
                }

                $modelTierIndex[$tierIndex] = $optionIndex;
            }

            $tierKey = implode('|', $modelTierIndex);
            if (isset($existingModelTierKeys[$tierKey])) {
                $existingModel = $existingModelByTierKey[$tierKey] ?? null;
                if (is_array($existingModel) && ! empty($existingModel['model_id'])) {
                    $updateModels[] = $this->buildShopeeUpdateModel($variant, $existingModel);
                    $plannedUpdateVariants[] = [
                        ...$variant,
                        'model_id' => (int) $existingModel['model_id'],
                        'tier_index' => $modelTierIndex,
                        'tier_values' => $tierValues,
                        'target_tier_index' => $targetTierIndex,
                        'default_tier_indexes' => $defaultTierIndexes,
                        'option_already_exists' => $allOptionsAlreadyExist,
                    ];
                } else {
                    $skippedVariants[] = [
                        'variant_name' => $variantName,
                        'reason' => 'Model Shopee untuk opsi ini sudah ada, tetapi model_id tidak terbaca.',
                        'tier_index' => $modelTierIndex,
                    ];
                }
                continue;
            }

            $addModels[] = $this->buildShopeeAddModel($variant, $modelTierIndex, $sellerStockLocationId, $modelWeight);
            $plannedVariants[] = [
                ...$variant,
                'tier_index' => $modelTierIndex,
                'tier_values' => $tierValues,
                'target_tier_index' => $targetTierIndex,
                'default_tier_indexes' => $defaultTierIndexes,
                'image_upload' => $imageUpload,
                'image_id' => $imageId ?: null,
                'option_already_exists' => $allOptionsAlreadyExist,
            ];
        }

        $updateTierPayload = null;
        if ($newOptionCount > 0) {
            $updatedTierVariation = $activeTierVariation;

            foreach ($tierOptionsByTier as $tierIndex => $tierOptions) {
                if ($useStandardiseTier) {
                    $updatedTierVariation[$tierIndex]['variation_option_list'] = $tierOptions;
                } else {
                    $updatedTierVariation[$tierIndex]['option_list'] = $tierOptions;
                }
            }

            if ($useStandardiseTier) {
                $updateTierPayload = [
                    'item_id' => $itemId,
                    'model_list' => $existingModelList,
                    'standardise_tier_variation' => $updatedTierVariation,
                ];
            } else {
                $updateTierPayload = [
                    'item_id' => $itemId,
                    'model_list' => $existingModelList,
                    'tier_variation' => $updatedTierVariation,
                ];
            }
        }

        $addModelPayload = [
            'item_id' => $itemId,
            'model_list' => $addModels,
        ];
        $updateModelPayload = [
            'item_id' => $itemId,
            'model' => $updateModels,
        ];

        $plan = [
            'item_id' => (string) $itemId,
            'shop_id' => (string) $shopId,
            'dry_run' => $dryRun,
            'tier_count' => $tierCount,
            'target_tier_index' => $targetTierIndex,
            'default_tier_indexes' => $defaultTierIndexes,
            'new_option_count' => $newOptionCount,
            'new_model_count' => count($addModels),
            'existing_model_update_count' => count($updateModels),
            'skipped' => $skippedVariants,
            'planned_variants' => $plannedVariants,
            'planned_update_variants' => $plannedUpdateVariants,
            'requests' => [
                'update_tier_variation' => $updateTierPayload,
                'update_model' => $updateModelPayload,
                'add_model' => $addModelPayload,
            ],
        ];

        if ($dryRun) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Dry run Shopee berhasil. Belum ada perubahan dikirim ke Shopee.',
                'plan' => $plan,
            ]);
        }

        if ($addModels === [] && $updateModels === []) {
            return response()->json([
                'status' => 'ok',
                'message' => 'Tidak ada model baru atau model existing yang perlu dikirim ke Shopee.',
                'plan' => $plan,
            ]);
        }

        $responses = [];

        if ($updateTierPayload !== null) {
            $responses['update_tier_variation'] = $this->shopeeSignedPost($config, '/api/v2/product/update_tier_variation', $shopId, $accessToken, $updateTierPayload);
            if (($responses['update_tier_variation']['error'] ?? '') !== '') {
                return response()->json([
                    'status' => 'error',
                    'message' => $responses['update_tier_variation']['message'] ?? 'Shopee menolak update_tier_variation.',
                    'plan' => $plan,
                    'responses' => $responses,
                ], 422);
            }

            $rebuiltAddModels = null;
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                if ($attempt > 1) {
                    usleep(600000);
                }

                $freshModelPayload = $this->fetchShopeeModelList($config, $shopId, $accessToken, $itemId);
                $rebuiltAddModels = $this->rebuildShopeeAddModelsFromFreshTier(
                    $plannedVariants,
                    $freshModelPayload,
                    $sellerStockLocationId,
                    $modelWeight
                );

                if ($rebuiltAddModels !== null) {
                    $responses['post_update_model_list'] = [
                        'attempt' => $attempt,
                        'message' => 'Tier variation Shopee terbaru berhasil dibaca sebelum add_model.',
                    ];
                    break;
                }
            }

            if ($rebuiltAddModels === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Shopee sudah menerima update opsi varian, tetapi opsi baru belum terbaca ulang untuk add_model. Coba klik Tambah ke Shopee sekali lagi.',
                    'plan' => $plan,
                    'responses' => $responses,
                ], 422);
            }

            $addModels = $rebuiltAddModels['model_list'];
            $plannedVariants = $rebuiltAddModels['planned_variants'];
            $addModelPayload = [
                'item_id' => $itemId,
                'model_list' => $addModels,
            ];
            $plan['new_model_count'] = count($addModels);
            $plan['planned_variants'] = $plannedVariants;
            $plan['requests']['add_model'] = $addModelPayload;
        }

        if ($updateModels !== []) {
            $responses['update_model'] = $this->shopeeSignedPost($config, '/api/v2/product/update_model', $shopId, $accessToken, $updateModelPayload);
            if (($responses['update_model']['error'] ?? '') !== '') {
                return response()->json([
                    'status' => 'error',
                    'message' => $responses['update_model']['message'] ?? 'Shopee menolak update_model.',
                    'plan' => $plan,
                    'responses' => $responses,
                ], 422);
            }
        }

        if ($addModels !== []) {
            $responses['add_model'] = $this->shopeeSignedPost($config, '/api/v2/product/add_model', $shopId, $accessToken, $addModelPayload);
            if (($responses['add_model']['error'] ?? '') !== '') {
                return response()->json([
                    'status' => 'error',
                    'message' => $responses['add_model']['message'] ?? 'Shopee menolak add_model.',
                    'plan' => $plan,
                    'responses' => $responses,
                ], 422);
            }
        }

        $freshBaseItems = $this->fetchShopeeBaseInfo($config, $shopId, $accessToken, [$itemId]);
        $freshModelPayload = $this->fetchShopeeModelList($config, $shopId, $accessToken, $itemId);
        foreach ($freshBaseItems as $freshBaseItem) {
            $this->storeShopeeProductPayload(
                $freshBaseItem,
                data_get($freshModelPayload, 'model', []),
                data_get($freshModelPayload, 'tier_variation', []),
                $shopId
            );
        }

        foreach ([...$plannedVariants, ...$plannedUpdateVariants] as $variant) {
            $stockMasterId = (int) ($variant['stock_master_id'] ?? 0);
            if ($stockMasterId <= 0) {
                continue;
            }

            DB::table('sku_variant_actions')->updateOrInsert(
                [
                    'stock_master_id' => $stockMasterId,
                    'target_channel' => 'shopee',
                    'action_type' => in_array($variant, $plannedUpdateVariants, true) ? 'update_variant' : 'create_variant',
                ],
                [
                    'source_channel' => 'tiktok',
                    'payload' => json_encode([
                        'plan' => $plan,
                        'responses' => $responses,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => 'submitted',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        return response()->json([
            'status' => 'ok',
            'message' => count($addModels).' varian baru dan '.count($updateModels).' varian existing Shopee berhasil diproses.',
            'plan' => $plan,
            'responses' => $responses,
        ]);
    }

    public function tiktokGetProduct(Request $request): Response|JsonResponse
    {
        $this->ensureTiktokAuthTables();

        $data = $request->validate([
            'product_id' => ['required', 'string'],
            'version' => ['nullable', 'string'],
            'shop_id' => ['nullable', 'string'],
            'shop_cipher' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'return_under_review_version' => ['nullable'],
            'return_draft_version' => ['nullable'],
            'locale' => ['nullable', 'string'],
        ]);

        $config = $this->tiktokConfig();
        $context = $this->resolveTiktokGetProductContext($data);
        $productId = trim((string) $data['product_id']);
        $version = trim((string) ($context['version'] ?? ($data['version'] ?? '202309'))) ?: '202309';
        $accessToken = trim((string) ($context['access_token'] ?? ''));
        abort_if($accessToken === '', 422, 'Token TikTok belum aktif. Jalankan login/refresh token dulu.');

        $shopId = trim((string) ($context['shop_id'] ?? ''));
        $shopCipher = trim((string) ($context['shop_cipher'] ?? ''));
        abort_if($shopId === '' || $shopCipher === '', 422, 'Shop TikTok belum lengkap untuk request get-product.');

        $query = [
            'app_key' => $config['app_key'],
            'access_token' => $accessToken,
            'shop_cipher' => $shopCipher,
            'shop_id' => $shopId,
            'timestamp' => time(),
            'version' => $version,
        ];

        $returnUnderReviewVersion = trim((string) ($data['return_under_review_version'] ?? ''));
        if ($returnUnderReviewVersion !== '') {
            $query['return_under_review_version'] = filter_var($returnUnderReviewVersion, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $query['return_under_review_version'] = $query['return_under_review_version'] === null
                ? $returnUnderReviewVersion
                : ($query['return_under_review_version'] ? 'true' : 'false');
        }

        $returnDraftVersion = trim((string) ($data['return_draft_version'] ?? ''));
        if ($returnDraftVersion !== '') {
            $query['return_draft_version'] = filter_var($returnDraftVersion, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $query['return_draft_version'] = $query['return_draft_version'] === null
                ? $returnDraftVersion
                : ($query['return_draft_version'] ? 'true' : 'false');
        }

        $locale = trim((string) ($data['locale'] ?? ''));
        if ($locale !== '') {
            $query['locale'] = $locale;
        }

        $path = '/product/'.$version.'/products/'.$productId;
        $query['sign'] = $this->generateTiktokSign($path, $query, $config['app_secret']);

        try {
            $response = Http::timeout(45)
                ->withHeaders([
                    'x-tts-access-token' => $accessToken,
                    'content-type' => 'application/json',
                ])
                ->get($config['api_host'].$path, $query);
        } catch (\Throwable $exception) {
            logger()->warning('TikTok get-product request exception', [
                'product_id' => $productId,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'TikTok get-product request gagal dipanggil.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        $body = $response->body();
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            logger()->warning('TikTok get-product returned invalid JSON', [
                'product_id' => $productId,
                'http_status' => $response->status(),
                'body' => $body,
            ]);

            return response()->json([
                'message' => 'TikTok tidak mengembalikan JSON valid.',
                'raw' => $body,
            ], 502);
        }

        if ((int) ($decoded['code'] ?? -1) === 0) {
            $productPayload = data_get($decoded, 'data');
            if (is_array($productPayload)) {
                $productPayload = is_array($productPayload['product'] ?? null)
                    ? $productPayload['product']
                    : $productPayload;

                try {
                    $this->storeTiktokProductPayload($productPayload);
                } catch (\Throwable $exception) {
                    logger()->warning('TikTok get-product cache sync failed', [
                        'product_id' => $productId,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return response($body, $response->status())
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    public function tiktokSubmitGeneratedPayload(Request $request): Response|JsonResponse
    {
        $this->ensureTiktokAuthTables();

        $data = $request->validate([
            'product_id' => ['required', 'string'],
            'version' => ['nullable', 'string'],
            'shop_id' => ['nullable', 'string'],
            'shop_cipher' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'payload_json' => ['required', 'string'],
        ]);

        $config = $this->tiktokConfig();
        $context = $this->resolveTiktokGetProductContext($data);

        $productId = trim((string) $data['product_id']);
        $version = trim((string) ($data['version'] ?? '202509')) ?: '202509';
        $accessToken = trim((string) ($data['access_token'] ?? ''));
        if ($accessToken === '') {
            $accessToken = trim((string) ($context['access_token'] ?? ''));
        }

        $shopId = trim((string) ($data['shop_id'] ?? ''));
        if ($shopId === '') {
            $shopId = trim((string) ($context['shop_id'] ?? ''));
        }

        $shopCipher = trim((string) ($data['shop_cipher'] ?? ''));
        if ($shopCipher === '') {
            $shopCipher = trim((string) ($context['shop_cipher'] ?? ''));
        }
        $payloadJson = trim((string) $data['payload_json']);

        abort_if($accessToken === '', 422, 'Token TikTok belum aktif. Jalankan login/refresh token dulu.');
        abort_if($shopId === '' || $shopCipher === '', 422, 'Shop TikTok belum lengkap untuk request submit payload.');

        $decodedPayload = json_decode($payloadJson, true);
        if (! is_array($decodedPayload)) {
            return response()->json([
                'message' => 'Payload JSON tidak valid.',
                'raw' => $payloadJson,
            ], 422);
        }

        try {
            $decodedPayload = $this->normalizeTiktokGeneratedPayloadDefaults($decodedPayload);
            $decodedPayload = $this->normalizeTiktokGeneratedPayloadWeights($decodedPayload);
            $decodedPayload = $this->normalizeTiktokGeneratedPayloadDimensions($decodedPayload);
            $decodedPayload = $this->normalizeTiktokGeneratedPayloadImages($decodedPayload, $accessToken);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Gagal menyiapkan gambar varian untuk submit TikTok.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        $payloadBody = json_encode($decodedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadBody === false) {
            return response()->json([
                'message' => 'Payload JSON tidak bisa dinormalisasi.',
                'raw' => $payloadJson,
            ], 422);
        }

        $path = '/product/'.$version.'/products/'.$productId;
        $query = [
            'access_token' => $accessToken,
            'app_key' => $config['app_key'],
            'shop_cipher' => $shopCipher,
            'shop_id' => $shopId,
            'timestamp' => time(),
            'version' => $version,
        ];
        $query['sign'] = $this->generateTiktokSign($path, $query, $config['app_secret'], $payloadBody);
        $submitUrl = $config['api_host'].$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        try {
            $curlResult = $this->executeTiktokSubmitPayloadRequest($submitUrl, $payloadBody, $accessToken);
            $responseStatus = (int) ($curlResult['status'] ?? 0);
            $responseBody = (string) ($curlResult['body'] ?? '');
        } catch (\Throwable $exception) {
            logger()->warning('TikTok submit generated payload request exception', [
                'product_id' => $productId,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'TikTok submit payload request gagal dipanggil.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        $body = $responseBody;
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            logger()->warning('TikTok submit generated payload returned invalid JSON', [
                'product_id' => $productId,
                'http_status' => $responseStatus,
                'body' => $body,
            ]);

            return response()->json([
                'message' => 'TikTok tidak mengembalikan JSON valid.',
                'raw' => $body,
            ], 502);
        }

        return response($body, $responseStatus)
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    private function normalizeTiktokGeneratedPayloadDefaults(array $payload): array
    {
        if (! is_array($payload['skus'] ?? null)) {
            return $payload;
        }

        $defaultPreSale = $this->resolveTiktokDefaultSkuPreSale($payload['skus']);

        foreach ($payload['skus'] as $skuIndex => $sku) {
            if (! is_array($sku)) {
                continue;
            }

            $preSale = $sku['pre_sale'] ?? null;
            $preSaleType = is_array($preSale) ? trim((string) ($preSale['type'] ?? '')) : '';

            if (! is_array($preSale) || $preSaleType === '') {
                $payload['skus'][$skuIndex]['pre_sale'] = $defaultPreSale;
            }

            $normalizedType = trim((string) data_get($payload, 'skus.'.$skuIndex.'.pre_sale.type', ''));
            if ($normalizedType === 'NONE') {
                unset($payload['skus'][$skuIndex]['pre_sale']['fulfillment_type']);
            }
        }

        return $payload;
    }

    private function normalizeTiktokGeneratedPayloadDimensions(array $payload): array
    {
        $payload = $this->normalizeTiktokDimensionNodes($payload);

        if (! is_array($payload['package_dimensions'] ?? null)) {
            $payload['package_dimensions'] = $this->normalizedTiktokDimensions([]);
        }

        $fallbackDimensions = $this->normalizedTiktokDimensions(is_array($payload['package_dimensions'] ?? null) ? $payload['package_dimensions'] : []);
        foreach ($this->tiktokSkuListPaths() as $path) {
            $skus = data_get($payload, $path);
            if (! is_array($skus)) {
                continue;
            }

            foreach ($skus as $skuIndex => $sku) {
                if (! is_array($sku)) {
                    continue;
                }

                $skuDimensions = is_array($sku['sku_dimensions'] ?? null)
                    ? $sku['sku_dimensions']
                    : $fallbackDimensions;
                data_set($payload, $path.'.'.$skuIndex.'.sku_dimensions', $this->normalizedTiktokDimensions($skuDimensions));
            }
        }

        return $payload;
    }

    private function normalizeTiktokDimensionNodes(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($this->isTiktokDimensionNodeKey((string) $key)) {
                $payload[$key] = $this->normalizedTiktokDimensions(is_array($value) ? $value : []);
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->normalizeTiktokDimensionNodes($value);
            }
        }

        return $payload;
    }

    private function isTiktokDimensionNodeKey(string $key): bool
    {
        return $key === 'dimensions' || str_ends_with($key, '_dimensions');
    }

    private function normalizedTiktokDimensions(array $dimensions): array
    {
        $normalize = static function (mixed $value): string {
            $raw = str_replace(',', '.', trim((string) $value));
            $numeric = is_numeric($raw) ? (float) $raw : 0.0;

            return (string) max(1, (int) round($numeric > 0 ? $numeric : 1));
        };

        return array_merge($dimensions, [
            'unit' => 'CENTIMETER',
            'height' => $normalize($dimensions['height'] ?? null),
            'length' => $normalize($dimensions['length'] ?? null),
            'width' => $normalize($dimensions['width'] ?? null),
        ]);
    }

    private function normalizeTiktokGeneratedPayloadWeights(array $payload): array
    {
        $payload = $this->normalizeTiktokWeightNodes($payload);

        if (! is_array($payload['package_weight'] ?? null)) {
            $payload['package_weight'] = $this->normalizedTiktokWeight([]);
        }

        $fallbackWeight = $this->normalizedTiktokWeight(is_array($payload['package_weight'] ?? null) ? $payload['package_weight'] : []);
        foreach ($this->tiktokSkuListPaths() as $path) {
            $skus = data_get($payload, $path);
            if (! is_array($skus)) {
                continue;
            }

            foreach ($skus as $skuIndex => $sku) {
                if (! is_array($sku)) {
                    continue;
                }

                $skuWeight = is_array($sku['sku_weight'] ?? null)
                    ? $sku['sku_weight']
                    : $fallbackWeight;
                data_set($payload, $path.'.'.$skuIndex.'.sku_weight', $this->normalizedTiktokWeight($skuWeight));
            }
        }

        return $payload;
    }

    private function tiktokSkuListPaths(): array
    {
        return ['skus', 'data.skus', 'data.product.skus', 'product.skus'];
    }

    private function normalizeTiktokWeightNodes(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if ($this->isTiktokWeightNodeKey((string) $key)) {
                $payload[$key] = $this->normalizedTiktokWeight(is_array($value) ? $value : []);
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->normalizeTiktokWeightNodes($value);
            }
        }

        return $payload;
    }

    private function isTiktokWeightNodeKey(string $key): bool
    {
        return $key === 'weight' || str_ends_with($key, '_weight');
    }

    private function normalizedTiktokWeight(array $packageWeight): array
    {
        $unit = strtoupper(trim((string) ($packageWeight['unit'] ?? '')));
        $rawValue = str_replace(',', '.', trim((string) ($packageWeight['value'] ?? '')));
        $value = is_numeric($rawValue) ? (float) $rawValue : 0.0;

        if ($value <= 0) {
            $value = 200.0;
        } elseif (in_array($unit, ['KILOGRAM', 'KILOGRAMS', 'KG'], true)) {
            $value *= 1000;
        }

        return array_merge($packageWeight, [
            'unit' => 'GRAM',
            'value' => (string) max(1, (int) round($value)),
        ]);
    }

    private function resolveTiktokDefaultSkuPreSale(array $skus): array
    {
        foreach ($skus as $sku) {
            if (! is_array($sku)) {
                continue;
            }

            $preSale = $sku['pre_sale'] ?? null;
            if (! is_array($preSale)) {
                continue;
            }

            if (trim((string) ($preSale['type'] ?? '')) !== '') {
                return $preSale;
            }
        }

        return ['type' => 'NONE'];
    }

    private function normalizeTiktokGeneratedPayloadImages(array $payload, string $accessToken): array
    {
        $uploadCache = [];

        if (! is_array($payload['skus'] ?? null)) {
            return $payload;
        }

        foreach ($payload['skus'] as $skuIndex => $sku) {
            if (! is_array($sku)) {
                continue;
            }

            $attributes = $sku['sales_attributes'] ?? [];
            if (! is_array($attributes)) {
                continue;
            }

            foreach ($attributes as $attributeIndex => $attribute) {
                $sourceUri = trim((string) data_get($attribute, 'sku_img.uri', ''));

                if ($sourceUri === '' || $this->isTiktokImageUri($sourceUri)) {
                    continue;
                }

                if (! array_key_exists($sourceUri, $uploadCache)) {
                    $uploadResult = $this->uploadTiktokProductImage((object) [], $accessToken, $sourceUri, 'MAIN_IMAGE');
                    $uploadedUri = trim((string) ($uploadResult['uri'] ?? ''));

                    if (($uploadResult['ok'] ?? false) !== true || $uploadedUri === '') {
                        $message = (string) ($uploadResult['message'] ?? 'Gagal upload gambar ke TikTok.');
                        throw new \RuntimeException($message);
                    }

                    $uploadCache[$sourceUri] = $uploadedUri;
                }

                $payload['skus'][$skuIndex]['sales_attributes'][$attributeIndex]['sku_img']['uri'] = $uploadCache[$sourceUri];
            }
        }

        return $payload;
    }

    private function isTiktokImageUri(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('#^tos-[^/]+/.+#i', $trimmed) === 1) {
            return true;
        }

        return (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://'))
            && str_contains($trimmed, '/tos-');
    }

    private function executeTiktokSubmitPayloadRequest(string $submitUrl, string $payloadBody, string $accessToken): array
    {
        $curlBinary = $this->resolveTiktokCurlBinary();
        $tempDir = storage_path('app/tiktok-submit');
        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0777, true) && ! is_dir($tempDir)) {
            throw new \RuntimeException('Tidak bisa menyiapkan folder sementara untuk request TikTok.');
        }

        $payloadFile = tempnam($tempDir, 'payload_');
        $responseFile = tempnam($tempDir, 'response_');

        if ($payloadFile === false || $responseFile === false) {
            throw new \RuntimeException('Tidak bisa membuat file sementara untuk request TikTok.');
        }

        try {
            if (file_put_contents($payloadFile, $payloadBody) === false) {
                throw new \RuntimeException('Tidak bisa menulis payload sementara untuk request TikTok.');
            }

            $command = [
                $curlBinary,
                '--insecure',
                '--silent',
                '--show-error',
                '--request',
                'PUT',
                '--header',
                'Content-Type: application/json',
                '--header',
                'x-tts-access-token: '.$accessToken,
                '--data-binary',
                '@'.$payloadFile,
                '--output',
                $responseFile,
                '--write-out',
                '%{http_code}',
                $submitUrl,
            ];

            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new \RuntimeException('curl TikTok gagal dijalankan.');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $responseStatus = (int) trim((string) $stdout);
            $responseBody = is_file($responseFile) ? (string) file_get_contents($responseFile) : '';
            $stderr = trim((string) $stderr);

            if ($responseStatus <= 0) {
                throw new \RuntimeException($stderr !== '' ? $stderr : 'HTTP status dari curl TikTok tidak terbaca.');
            }

            if ($exitCode !== 0 && $responseBody === '' && $stderr !== '') {
                throw new \RuntimeException($stderr);
            }

            return [
                'status' => $responseStatus,
                'body' => $responseBody,
                'stderr' => $stderr,
            ];
        } finally {
            if (is_file($payloadFile)) {
                @unlink($payloadFile);
            }
            if (is_file($responseFile)) {
                @unlink($responseFile);
            }
        }
    }

    private function resolveTiktokCurlBinary(): string
    {
        $candidates = [
            'C:\\Windows\\System32\\curl.exe',
            'C:\\Windows\\SysWOW64\\curl.exe',
            'curl.exe',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === 'curl.exe' || is_file($candidate)) {
                return $candidate;
            }
        }

        return 'curl.exe';
    }

    public function tiktokGetProductContext(): JsonResponse
    {
        $this->ensureTiktokAuthTables();

        $context = $this->resolveTiktokGetProductContext([]);

        abort_if(trim((string) ($context['access_token'] ?? '')) === '', 422, 'Token TikTok aktif belum tersedia.');
        abort_if(trim((string) ($context['shop_id'] ?? '')) === '' || trim((string) ($context['shop_cipher'] ?? '')) === '', 422, 'Shop TikTok aktif belum tersedia.');

        return response()->json([
            'status' => 'ok',
            'message' => 'Context TikTok aktif berhasil diambil.',
            'data' => [
                'account_key' => $context['account_key'] ?? null,
                'account_name' => $context['account_name'] ?? null,
                'version' => $context['version'] ?? '202309',
                'shop_id' => $context['shop_id'] ?? null,
                'shop_cipher' => $context['shop_cipher'] ?? null,
                'access_token' => $context['access_token'] ?? null,
            ],
        ]);
    }

    public function stockMaster(): JsonResponse
    {
        $summary = DB::selectOne("
            SELECT
                COUNT(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
                          AND COALESCE(tp.is_active, true) = true
                    )
                ) AS total_match,
                COUNT(*) FILTER (
                    WHERE EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND COALESCE(tp.is_active, true) = true
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
                          AND COALESCE(tp.is_active, true) = true
                    )
                ) AS total_variant_missing,
                COUNT(*) FILTER (
                    WHERE NOT EXISTS (
                        SELECT 1 FROM tiktok_products tp
                        WHERE LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
                          AND COALESCE(tp.is_active, true) = true
                    )
                ) AS total_product_missing,
                COUNT(*) AS total_all
            FROM stock_master sm
        ");

        $items = DB::select("
            SELECT
                sm.id,
                sm.internal_sku,
                sm.product_name,
                sm.variant_name,
                sm.stock_qty AS stock_shopee,
                COALESCE(tp.stock_qty, 0) AS stock_tiktok,
                sm.updated_at::text,
                CASE
                    WHEN tp.sku_name IS NOT NULL THEN 'MATCH'
                    WHEN EXISTS (
                        SELECT 1 FROM tiktok_products tpx
                        WHERE LOWER(TRIM(tpx.product_name)) = LOWER(TRIM(sm.product_name))
                    ) THEN 'VARIANT MISSING'
                    ELSE 'PRODUCT MISSING'
                END AS status_tiktok,
                CASE
                    WHEN tp.sku_name IS NOT NULL THEN 1
                    WHEN EXISTS (
                        SELECT 1 FROM tiktok_products tpx
                        WHERE LOWER(TRIM(tpx.product_name)) = LOWER(TRIM(sm.product_name))
                    ) THEN 2
                    ELSE 3
                END AS status_order
            FROM stock_master sm
            LEFT JOIN tiktok_products tp
              ON LOWER(TRIM(tp.product_name)) = LOWER(TRIM(sm.product_name))
             AND LOWER(TRIM(tp.sku_name)) = LOWER(TRIM(sm.variant_name))
             AND COALESCE(tp.is_active, true) = true
            ORDER BY status_order, sm.product_name, sm.variant_name
        ");

        return response()->json([
            'summary' => $summary,
            'items' => $items,
        ]);
    }

    private function formatRupiah(int $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }

    private function latestShopeeTokens(): array
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return [];
        }

        return DB::table('shopee_tokens')
            ->select([
                'id',
                'account_key',
                'account_name',
                'partner_id',
                'shop_id',
                'merchant_id',
                'supplier_id',
                'user_id',
                'access_token',
                'refresh_token',
                'expire_in',
                'expire_at',
                'access_token_expire_at',
                'refresh_token_expire_at',
                'request_id',
                'error',
                'message',
                'is_active',
                'created_at',
            ])
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'account_key' => $token->account_key,
                'account_name' => $token->account_name,
                'partner_id' => $token->partner_id,
                'shop_id' => $token->shop_id,
                'merchant_id' => $token->merchant_id,
                'supplier_id' => $token->supplier_id,
                'user_id' => $token->user_id,
                'access_token' => $this->maskToken($token->access_token),
                'refresh_token' => $this->maskToken($token->refresh_token),
                'expire_in' => $token->expire_in,
                'expire_at' => $token->expire_at,
                'access_token_expire_at' => $token->access_token_expire_at,
                'refresh_token_expire_at' => $token->refresh_token_expire_at,
                'request_id' => $token->request_id,
                'error' => $token->error,
                'message' => $token->message,
                'is_active' => $this->isLatestActiveToken('shopee_tokens', (int) $token->id, (string) $token->account_key),
                'created_at' => $token->created_at,
            ])
            ->all();
    }

    private function shopeeShopNames(): array
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return [];
        }

        return DB::table('shopee_tokens')
            ->whereNotNull('shop_id')
            ->orderByDesc('created_at')
            ->get(['shop_id', 'account_name'])
            ->reduce(function (array $names, object $token) {
                $key = (string) $token->shop_id;

                if (! isset($names[$key])) {
                    $names[$key] = $token->account_name ?: 'Shopee';
                }

                return $names;
            }, []);
    }

    private function latestTiktokTokens(): array
    {
        if (! Schema::hasTable('tiktok_tokens')) {
            return [];
        }

        return DB::table('tiktok_tokens')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(function ($token) {
                $row = (array) $token;

                $accountKey = $row['account_key'] ?? 'tiktok-agnishopbjm';

                return [
                    'id' => $row['id'] ?? null,
                    'account_key' => $accountKey,
                    'account_name' => $row['account_name'] ?? 'TikTok AgniShopBJM',
                    'shop_id' => $row['shop_id'] ?? $row['seller_id'] ?? $row['shop_cipher'] ?? null,
                    'access_token' => $this->maskToken($row['access_token'] ?? null),
                    'refresh_token' => $this->maskToken($row['refresh_token'] ?? null),
                    'expire_in' => $row['expire_in'] ?? $row['expires_in'] ?? null,
                    'expire_at' => $row['expire_at'] ?? $row['access_token_expire_at'] ?? null,
                    'request_id' => $row['request_id'] ?? null,
                    'error' => $row['error'] ?? null,
                    'message' => $row['message'] ?? null,
                    'is_active' => $this->isLatestActiveToken('tiktok_tokens', (int) ($row['id'] ?? 0), (string) $accountKey),
                    'created_at' => $row['created_at'] ?? null,
                ];
            })
            ->all();
    }

    private function isLatestActiveToken(string $table, int $id, string $accountKey): bool
    {
        if ($id <= 0 || ! Schema::hasTable($table)) {
            return false;
        }

        return (int) DB::table($table)
            ->where('account_key', $accountKey)
            ->whereRaw('is_active = true')
            ->latest('created_at')
            ->value('id') === $id;
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    private function latestTokenPreview(string $table): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::table($table)->latest('created_at')->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $data['access_token'] = $this->maskToken($data['access_token'] ?? null);
        $data['refresh_token'] = $this->maskToken($data['refresh_token'] ?? null);

        return $data;
    }

    private function databaseInfo(): array
    {
        $row = DB::selectOne('select current_database() as name, current_user as username');

        return [
            'name' => $row->name ?? config('database.connections.pgsql.database'),
            'username' => $row->username ?? config('database.connections.pgsql.username'),
        ];
    }

    private function maskToken(?string $token): string
    {
        if (! $token) {
            return '-';
        }

        if (strlen($token) <= 12) {
            return $token;
        }

        return substr($token, 0, 8).'...'.substr($token, -6);
    }

    private function maskShopeeTokenPayload(array $payload): array
    {
        foreach (['access_token', 'refresh_token'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->maskToken($payload[$key]);
            }
        }

        return $payload;
    }

    private function maskTiktokTokenPayload(array $payload): array
    {
        foreach (['access_token', 'refresh_token'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->maskToken($payload[$key]);
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach (['access_token', 'refresh_token'] as $key) {
                if (array_key_exists($key, $payload['data'])) {
                    $payload['data'][$key] = $this->maskToken($payload['data'][$key]);
                }
            }
        }

        return $payload;
    }

    private function renderShopeeCallbackPage(string $title, string $message, array $result): string
    {
        $rows = [
            'Status' => $result['status'] ?? '-',
            'Action' => $result['action'] ?? '-',
            'Akun' => $result['account_name'] ?? '-',
            'Shop ID' => implode(', ', $result['shop_id_list'] ?? []),
            'Access Token' => $this->maskToken($result['access_token'] ?? null),
            'Refresh Token' => $this->maskToken($result['refresh_token'] ?? null),
            'Expire In' => $result['expire_in'] ?? '-',
            'Request ID' => $result['request_id'] ?? '-',
            'Error' => $result['error'] ?? '-',
            'Message' => $result['message'] ?? '-',
        ];

        $tableRows = collect($rows)->map(function ($value, string $label) {
            return '<tr><th>'.e($label).'</th><td>'.e((string) ($value ?: '-')).'</td></tr>';
        })->implode('');

        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>'.e($title).'</title><style>body{font-family:Arial,sans-serif;padding:32px;line-height:1.5;color:#0f172a}h1{margin-bottom:12px}table{border-collapse:collapse;width:100%;margin:18px 0;background:#fff}th,td{border:1px solid #d9e2ec;padding:10px 12px;text-align:left}th{width:180px;background:#f8fafc}a{color:#0f5fc7}</style></head><body><h1>'.e($title).'</h1><p>'.e($message).'</p><table>'.$tableRows.'</table><p><a href="/dashboard">Kembali ke Dashboard</a></p></body></html>';
    }

    private function buildShopeeAuthUrl(array $account): string
    {
        $config = $this->shopeeConfig();
        $path = '/api/v2/shop/auth_partner';
        $timestamp = time();
        $sign = $this->generateShopeeSign($config['partner_id'], $config['partner_key'], $path, $timestamp);
        $redirectUrl = $config['redirect_url'].(str_contains($config['redirect_url'], '?') ? '&' : '?').http_build_query([
            'account' => $account['key'],
        ]);

        return $config['host'].$path.'?'.http_build_query([
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
            'redirect' => $redirectUrl,
        ]);
    }

    private function connectShopee(array $account): array
    {
        $token = $this->latestActiveShopeeToken($account);

        if ($token && ! $this->shopeeAccessTokenNeedsRefresh($token)) {
            return [
                'status' => 'ok',
                'action' => 'connect-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Token '.$account['name'].' masih aktif. AUTH ulang tidak diperlukan.',
                'access_token' => $token->access_token,
                'refresh_token' => $token->refresh_token,
                'expire_in' => $token->expire_in,
                'expire_at' => $token->expire_at,
                'access_token_expire_at' => $token->access_token_expire_at ?? $token->expire_at,
                'refresh_token_expire_at' => $this->shopeeRefreshTokenExpireAt($token)?->toDateTimeString(),
            ];
        }

        if ($token && $this->shopeeRefreshTokenIsUsable($token)) {
            $result = $this->refreshShopeeToken($account);

            if (($result['status'] ?? '') === 'ok') {
                return $result;
            }

            if (! $this->shopeeRefreshFailureNeedsAuth($result)) {
                return $result;
            }
        }

        $callback = DB::table('shopee_callbacks')
            ->where('account_key', $account['key'])
            ->whereNull('used_at')
            ->latest('created_at')
            ->first();

        if ($callback) {
            return $this->exchangeShopeeToken($callback);
        }

        return [
            'status' => 'redirect',
            'action' => 'connect-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => 'Token '.$account['name'].' perlu authorization ulang.',
            'redirect_url' => $this->buildShopeeAuthUrl($account),
        ];
    }

    private function exchangeShopeeToken(object $callback): array
    {
        $config = $this->shopeeConfig();
        $path = '/api/v2/auth/token/get';
        $timestamp = time();
        $sign = $this->generateShopeeSign($config['partner_id'], $config['partner_key'], $path, $timestamp);
        $url = $config['host'].$path.'?'.http_build_query([
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]);

        $payload = [
            'code' => $callback->code,
            'partner_id' => $config['partner_id'],
        ];

        if (! empty($callback->shop_id)) {
            $payload['shop_id'] = (int) $callback->shop_id;
        }

        if (! empty($callback->main_account_id)) {
            $payload['main_account_id'] = (int) $callback->main_account_id;
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        $data = $response->json() ?: [
            'error' => 'error_network',
            'message' => $response->body(),
        ];

        if (($data['error'] ?? '') === '' && ! empty($data['access_token'])) {
            $this->storeShopeeToken($data, $config['partner_id'], $callback);

            DB::table('shopee_callbacks')
                ->where('id', $callback->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);
        }

        return [
            'status' => ($data['error'] ?? '') === '' ? 'ok' : 'error',
            'action' => 'get-token-'.$callback->account_key,
            'account_key' => $callback->account_key,
            'account_name' => $callback->account_name,
            'access_token_expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null)?->toDateTimeString(),
            'refresh_token_expire_at' => (($data['error'] ?? '') === '' ? now()->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS)->toDateTimeString() : null),
            ...$data,
        ];
    }

    private function refreshShopeeToken(array $account): array
    {
        $token = DB::table('shopee_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();

        if (! $token) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada token aktif '.$account['name'].' yang bisa di-refresh. Jalankan AUTH dan GET TOKEN dulu.',
            ];
        }

        if (! $this->shopeeRefreshTokenIsUsable($token)) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'error' => 'refresh_token_expired',
                'message' => 'Refresh token '.$account['name'].' sudah kedaluwarsa. Jalankan AUTH Shopee ulang.',
                'refresh_token_expire_at' => $this->shopeeRefreshTokenExpireAt($token)?->toDateTimeString(),
            ];
        }

        $identifier = $this->shopeeRefreshIdentifier($token);

        if (! $identifier) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Token aktif '.$account['name'].' tidak memiliki shop_id, merchant_id, supplier_id, atau user_id.',
            ];
        }

        $config = $this->shopeeConfig();
        $path = '/api/v2/auth/access_token/get';
        $timestamp = time();
        $sign = $this->generateShopeeSign($config['partner_id'], $config['partner_key'], $path, $timestamp);
        $url = $config['host'].$path.'?'.http_build_query([
            'partner_id' => $config['partner_id'],
            'timestamp' => $timestamp,
            'sign' => $sign,
        ]);

        $payload = [
            'refresh_token' => $token->refresh_token,
            'partner_id' => $config['partner_id'],
            $identifier['key'] => $identifier['value'],
        ];

        $response = Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        $data = $response->json() ?: [
            'error' => 'error_network',
            'message' => $response->body(),
        ];

        if (($data['error'] ?? '') === '' && ! empty($data['access_token']) && ! empty($data['refresh_token'])) {
            $this->storeShopeeRefreshToken($data, $config['partner_id'], $account, $token);

            return [
                ...$data,
                'status' => 'ok',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Refresh token '.$account['name'].' berhasil. Token baru sudah disimpan.',
                'access_token_expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null)?->toDateTimeString(),
                'refresh_token_expire_at' => now()->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS)->toDateTimeString(),
            ];
        }

        return [
            'status' => 'error',
            'action' => 'refresh-token-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            ...$data,
        ];
    }

    private function latestActiveShopeeToken(array $account): ?object
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return null;
        }

        return DB::table('shopee_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();
    }

    private function storeShopeeToken(array $data, int $partnerId, object $callback): void
    {
        $shopIdList = $data['shop_id_list'] ?? [];
        $merchantIdList = $data['merchant_id_list'] ?? [];
        $supplierIdList = $data['supplier_id_list'] ?? [];
        $userIdList = $data['user_id_list'] ?? [];
        $shopId = $callback->shop_id ?: ($shopIdList[0] ?? null);

        if ($shopId) {
            DB::table('shopee_tokens')
                ->where('shop_id', $shopId)
                ->where('account_key', $callback->account_key)
                ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);
        }

        DB::table('shopee_tokens')->insert([
            'account_key' => $callback->account_key,
            'account_name' => $callback->account_name,
            'partner_id' => $partnerId,
            'shop_id' => $shopId,
            'merchant_id' => $merchantIdList[0] ?? null,
            'supplier_id' => $supplierIdList[0] ?? null,
            'user_id' => $userIdList[0] ?? null,
            'shop_id_list' => json_encode($shopIdList),
            'merchant_id_list' => json_encode($merchantIdList),
            'supplier_id_list' => json_encode($supplierIdList),
            'user_id_list' => json_encode($userIdList),
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expire_in' => $data['expire_in'] ?? null,
            'expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null),
            'access_token_expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null),
            'refresh_token_expire_at' => now()->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS),
            'request_id' => $data['request_id'] ?? null,
            'error' => $data['error'] ?? null,
            'message' => $data['message'] ?? null,
            'raw_response' => json_encode($data),
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeShopeeRefreshToken(array $data, int $partnerId, array $account, object $previousToken): void
    {
        $shopId = $data['shop_id'] ?? $previousToken->shop_id ?? null;
        $merchantId = $data['merchant_id'] ?? $previousToken->merchant_id ?? null;
        $supplierId = $data['supplier_id'] ?? $previousToken->supplier_id ?? null;
        $userId = $data['user_id'] ?? $previousToken->user_id ?? null;

        DB::table('shopee_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);

        DB::table('shopee_tokens')->insert([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'partner_id' => $data['partner_id'] ?? $partnerId,
            'shop_id' => $shopId,
            'merchant_id' => $merchantId,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'shop_id_list' => json_encode($shopId ? [(int) $shopId] : []),
            'merchant_id_list' => json_encode($merchantId ? [(int) $merchantId] : []),
            'supplier_id_list' => json_encode($supplierId ? [(int) $supplierId] : []),
            'user_id_list' => json_encode($userId ? [(int) $userId] : []),
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expire_in' => $data['expire_in'] ?? null,
            'expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null),
            'access_token_expire_at' => $this->resolveShopeeExpireAt($data['expire_in'] ?? null),
            'refresh_token_expire_at' => now()->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS),
            'request_id' => $data['request_id'] ?? null,
            'error' => $data['error'] ?? null,
            'message' => $data['message'] ?? null,
            'raw_response' => json_encode($data),
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function shopeeRefreshIdentifier(object $token): ?array
    {
        foreach (['shop_id', 'merchant_id', 'supplier_id', 'user_id'] as $key) {
            if (! empty($token->{$key})) {
                return ['key' => $key, 'value' => (int) $token->{$key}];
            }
        }

        return null;
    }

    private function shopeeAccessTokenNeedsRefresh(object $token): bool
    {
        $expireAt = $this->shopeeAccessTokenExpireAt($token);

        if (! $expireAt) {
            return true;
        }

        return $expireAt->lessThanOrEqualTo(now()->addMinutes(self::SHOPEE_ACCESS_TOKEN_REFRESH_BUFFER_MINUTES));
    }

    private function shopeeAccessTokenIsExpired(object $token): bool
    {
        $expireAt = $this->shopeeAccessTokenExpireAt($token);

        return ! $expireAt || $expireAt->isPast();
    }

    private function shopeeAccessTokenExpireAt(object $token): ?Carbon
    {
        $value = $token->access_token_expire_at ?? $token->expire_at ?? null;

        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function shopeeRefreshTokenIsUsable(object $token): bool
    {
        $expireAt = $this->shopeeRefreshTokenExpireAt($token);

        return $expireAt && $expireAt->isFuture();
    }

    private function shopeeRefreshTokenExpireAt(object $token): ?Carbon
    {
        $value = $token->refresh_token_expire_at ?? null;

        if ($value) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        if (! empty($token->created_at)) {
            try {
                return Carbon::parse($token->created_at)->addDays(self::SHOPEE_REFRESH_TOKEN_VALID_DAYS);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function shopeeRefreshFailureNeedsAuth(array $result): bool
    {
        $error = strtolower((string) ($result['error'] ?? ''));
        $message = strtolower((string) ($result['message'] ?? ''));

        foreach (['refresh_token_expired', 'access_expired', 'no_linked', 'invalid refresh_token'] as $needle) {
            if (str_contains($error, $needle) || str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function resolveShopeeExpireAt(?int $expireIn): ?Carbon
    {
        if (! $expireIn) {
            return null;
        }

        return $expireIn > time()
            ? Carbon::createFromTimestamp($expireIn)
            : now()->addSeconds($expireIn);
    }

    private function buildTiktokAuthUrl(array $account): string
    {
        $config = $this->tiktokConfig();
        $timestamp = time();
        $params = [
            'app_key' => $config['app_key'],
            'timestamp' => $timestamp,
            'redirect_uri' => $config['redirect_url'],
            'state' => $account['key'],
        ];
        $params['sign'] = $this->generateTiktokSign('/openapi/v2/oauth/authorize', $params, $config['app_secret']);

        return $config['auth_host'].'/openapi/v2/oauth/authorize?'.http_build_query($params);
    }

    private function connectTiktok(array $account): array
    {
        $token = $this->latestActiveTiktokToken($account);

        if ($token && ! $this->tiktokAccessTokenNeedsRefresh($token)) {
            return [
                'status' => 'ok',
                'action' => 'connect-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Token '.$account['name'].' masih aktif. AUTH ulang tidak diperlukan.',
                'access_token' => $token->access_token,
                'refresh_token' => $token->refresh_token,
                'expire_in' => $token->expire_in,
                'expire_at' => $token->expire_at,
                'access_token_expire_at' => $token->access_token_expire_at ?? $token->expire_at,
                'refresh_token_expire_at' => $this->tiktokRefreshTokenExpireAt($token)?->toDateTimeString(),
            ];
        }

        if ($token && $this->tiktokRefreshTokenIsUsable($token)) {
            $result = $this->refreshTiktokToken($account);

            if (($result['status'] ?? '') === 'ok') {
                return $result;
            }

            if (! $this->tiktokRefreshFailureNeedsAuth($result)) {
                return $result;
            }
        }

        $callback = DB::table('tiktok_callbacks')
            ->where('account_key', $account['key'])
            ->whereNull('used_at')
            ->latest('created_at')
            ->first();

        if ($callback && $this->callbackIsFresh($callback->created_at ?? null)) {
            $result = $this->exchangeTiktokToken($account);

            if (($result['status'] ?? '') === 'ok') {
                return $result;
            }

            if (str_contains(strtolower((string) ($result['message'] ?? '')), 'invalid auth code')) {
                DB::table('tiktok_callbacks')
                    ->where('id', $callback->id)
                    ->update(['used_at' => now(), 'updated_at' => now()]);
            }
        } elseif ($callback) {
            DB::table('tiktok_callbacks')
                ->where('id', $callback->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);
        }

        return [
            'status' => 'redirect',
            'action' => 'connect-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => 'Token '.$account['name'].' perlu authorization ulang.',
            'redirect_url' => $this->buildTiktokAuthUrl($account),
        ];
    }

    private function exchangeTiktokToken(array $account): array
    {
        $this->ensureTiktokAuthTables();

        $callback = DB::table('tiktok_callbacks')
            ->where('account_key', $account['key'])
            ->whereNull('used_at')
            ->latest('created_at')
            ->first();

        if (! $callback) {
            return [
                'status' => 'error',
                'action' => 'get-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada callback '.$account['name'].' yang bisa ditukar menjadi token. Klik AUTH dulu.',
            ];
        }

        $config = $this->tiktokConfig();
        $response = Http::timeout(20)->get($config['auth_host'].'/api/v2/token/get', [
            'app_key' => $config['app_key'],
            'app_secret' => $config['app_secret'],
            'auth_code' => $callback->code,
            'grant_type' => 'authorized_code',
        ]);

        $data = $response->json() ?: ['code' => $response->status(), 'message' => $response->body()];
        $ok = (int) ($data['code'] ?? -1) === 0 && ! empty($data['data']['access_token']);

        if ($ok) {
            $this->storeTiktokToken($data, $account);

            DB::table('tiktok_callbacks')
                ->where('id', $callback->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);
        } elseif (str_contains(strtolower((string) ($data['message'] ?? '')), 'invalid auth code')) {
            DB::table('tiktok_callbacks')
                ->where('id', $callback->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);
        }

        return [
            'status' => $ok ? 'ok' : 'error',
            'action' => 'get-token-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => $ok ? 'Token TikTok berhasil disimpan.' : ($data['message'] ?? 'TikTok mengembalikan error.'),
            ...$data,
        ];
    }

    private function refreshTiktokToken(array $account): array
    {
        $this->ensureTiktokAuthTables();

        $token = DB::table('tiktok_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();

        if (! $token) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada token TikTok yang bisa di-refresh. Jalankan AUTH dan GET TOKEN dulu.',
            ];
        }

        if (! $this->tiktokRefreshTokenIsUsable($token)) {
            return [
                'status' => 'error',
                'action' => 'refresh-token-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'code' => 'refresh_token_expired',
                'message' => 'Refresh token '.$account['name'].' sudah kedaluwarsa. Jalankan AUTH TikTok ulang.',
                'refresh_token_expire_at' => $this->tiktokRefreshTokenExpireAt($token)?->toDateTimeString(),
            ];
        }

        $config = $this->tiktokConfig();
        $response = Http::timeout(20)->get($config['auth_host'].'/api/v2/token/refresh', [
            'app_key' => $config['app_key'],
            'app_secret' => $config['app_secret'],
            'refresh_token' => $token->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $data = $response->json() ?: ['code' => $response->status(), 'message' => $response->body()];
        $ok = (int) ($data['code'] ?? -1) === 0 && ! empty($data['data']['access_token']);

        if ($ok) {
            $this->storeTiktokToken($data, $account);
        }

        return [
            ...$data,
            'status' => $ok ? 'ok' : 'error',
            'action' => 'refresh-token-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => $ok ? 'Refresh token TikTok berhasil. Token baru sudah disimpan.' : ($data['message'] ?? 'TikTok mengembalikan error.'),
            'access_token_expire_at' => $ok ? $this->resolveTiktokExpireAt($data['data']['access_token_expire_in'] ?? null)?->toDateTimeString() : null,
            'refresh_token_expire_at' => $ok ? $this->resolveTiktokExpireAt($data['data']['refresh_token_expire_in'] ?? null)?->toDateTimeString() : null,
        ];
    }

    private function latestActiveTiktokToken(array $account): ?object
    {
        if (! Schema::hasTable('tiktok_tokens')) {
            return null;
        }

        return DB::table('tiktok_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->whereNotNull('refresh_token')
            ->latest('created_at')
            ->first();
    }

    private function tiktokAccessTokenNeedsRefresh(object $token): bool
    {
        $expireAt = $this->tiktokAccessTokenExpireAt($token);

        if (! $expireAt) {
            return true;
        }

        return $expireAt->lessThanOrEqualTo(now()->addMinutes(self::TIKTOK_ACCESS_TOKEN_REFRESH_BUFFER_MINUTES));
    }

    private function tiktokAccessTokenIsExpired(object $token): bool
    {
        $expireAt = $this->tiktokAccessTokenExpireAt($token);

        return ! $expireAt || $expireAt->isPast();
    }

    private function tiktokAccessTokenExpireAt(object $token): ?Carbon
    {
        return $this->parseTokenDate($token->access_token_expire_at ?? $token->expire_at ?? null);
    }

    private function tiktokRefreshTokenIsUsable(object $token): bool
    {
        $expireAt = $this->tiktokRefreshTokenExpireAt($token);

        return $expireAt && $expireAt->isFuture();
    }

    private function tiktokRefreshTokenExpireAt(object $token): ?Carbon
    {
        return $this->parseTokenDate($token->refresh_token_expire_at ?? null);
    }

    private function tiktokRefreshFailureNeedsAuth(array $result): bool
    {
        $code = strtolower((string) ($result['code'] ?? ''));
        $message = strtolower((string) ($result['message'] ?? ''));

        foreach (['refresh_token_expired', 'invalid refresh_token', 'invalid refresh token', 'refresh token expired'] as $needle) {
            if (str_contains($code, $needle) || str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function getTiktokAuthorizedShops(array $account): array
    {
        $this->ensureTiktokAuthTables();

        $token = $this->latestActiveTiktokToken($account);

        if (! $token) {
            return [
                'status' => 'error',
                'action' => 'get-auth-shop-'.$account['key'],
                'account_key' => $account['key'],
                'account_name' => $account['name'],
                'message' => 'Belum ada access token TikTok. Jalankan AUTH dan GET TOKEN dulu.',
            ];
        }

        if ($this->tiktokAccessTokenNeedsRefresh($token)) {
            $refreshResult = $this->refreshTiktokToken($account);

            if (($refreshResult['status'] ?? '') !== 'ok') {
                return [
                    ...$refreshResult,
                    'status' => 'error',
                    'action' => 'get-auth-shop-'.$account['key'],
                    'account_key' => $account['key'],
                    'account_name' => $account['name'],
                    'message' => $refreshResult['message'] ?? 'Access token TikTok perlu refresh, tetapi refresh gagal.',
                ];
            }

            $token = $this->latestActiveTiktokToken($account);
        }

        $config = $this->tiktokConfig();
        $path = '/authorization/202309/shops';
        $timestamp = time();
        $params = [
            'app_key' => $config['app_key'],
            'timestamp' => $timestamp,
        ];
        $params['sign'] = $this->generateTiktokSign($path, $params, $config['app_secret']);

        $response = Http::timeout(20)
            ->withHeaders(['x-tts-access-token' => $token->access_token])
            ->get($config['api_host'].$path, $params);

        $data = $response->json() ?: ['code' => $response->status(), 'message' => $response->body()];
        $shops = $data['data']['shops'] ?? [];
        $ok = (int) ($data['code'] ?? -1) === 0 && is_array($shops) && count($shops) > 0;

        if ($ok) {
            $this->storeTiktokShops($shops);
        }

        return [
            'status' => $ok ? 'ok' : 'error',
            'action' => 'get-auth-shop-'.$account['key'],
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'message' => $ok ? count($shops).' shop TikTok berhasil disimpan.' : ($data['message'] ?? 'TikTok tidak mengembalikan data shop.'),
            ...$data,
        ];
    }

    private function storeTiktokToken(array $response, array $account): void
    {
        $this->ensureTiktokAuthTables();

        $data = $response['data'] ?? [];
        $expireAt = $this->resolveTiktokExpireAt($data['access_token_expire_in'] ?? null);
        $refreshExpireAt = $this->resolveTiktokExpireAt($data['refresh_token_expire_in'] ?? null);

        $expireIn = $expireAt ? (int) floor(now()->diffInSeconds($expireAt, false)) : null;

        DB::table('tiktok_tokens')
            ->where('account_key', $account['key'])
            ->whereRaw('is_active = true')
            ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);

        DB::table('tiktok_tokens')->insert([
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'open_id' => $data['open_id'] ?? null,
            'seller_name' => $data['seller_name'] ?? null,
            'seller_region' => $data['seller_base_region'] ?? null,
            'access_token' => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expire_at' => $expireAt,
            'expire_in' => $expireIn,
            'access_token_expire_at' => $expireAt,
            'refresh_token_expire_at' => $refreshExpireAt,
            'granted_scopes' => json_encode($data['granted_scopes'] ?? []),
            'request_id' => $response['request_id'] ?? null,
            'message' => $response['message'] ?? null,
            'raw_response' => json_encode($response),
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeTiktokShops(array $shops): void
    {
        $this->ensureTiktokAuthTables();

        foreach ($shops as $shop) {
            $shopId = (string) ($shop['id'] ?? $shop['shop_id'] ?? '');
            $shopCipher = $shop['cipher'] ?? $shop['shop_cipher'] ?? null;

            if ($shopId === '') {
                continue;
            }

            DB::table('tiktok_shops')->updateOrInsert(
                ['id' => $shopId],
                [
                    'shop_id' => $shopId,
                    'code' => $shop['code'] ?? null,
                    'name' => $shop['name'] ?? null,
                    'region' => $shop['region'] ?? null,
                    'seller_type' => $shop['seller_type'] ?? null,
                    'cipher' => $shopCipher,
                    'shop_cipher' => $shopCipher,
                    'raw_response' => json_encode($shop),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function latestTiktokShop(): ?object
    {
        if (! Schema::hasTable('tiktok_shops')) {
            return null;
        }

        return DB::table('tiktok_shops')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function resolveTiktokGetProductContext(array $data): array
    {
        $account = $this->resolveAccount('tiktok-agnishopbjm', 'tiktok');
        $token = $this->latestActiveTiktokToken($account);
        $shop = $this->latestTiktokShop();

        $accessToken = trim((string) ($token->access_token ?? $data['access_token'] ?? ''));
        $shopId = trim((string) (
            $shop->shop_id ?? $shop->id ?? $token->shop_id ?? $data['shop_id'] ?? ''
        ));
        $shopCipher = trim((string) (
            $shop->cipher ?? $shop->shop_cipher ?? $token->shop_cipher ?? $data['shop_cipher'] ?? ''
        ));

        return [
            'account_key' => $account['key'],
            'account_name' => $account['name'],
            'version' => trim((string) ($data['version'] ?? '202309')) ?: '202309',
            'shop_id' => $shopId,
            'shop_cipher' => $shopCipher,
            'access_token' => $accessToken,
        ];
    }

    private function resolveTiktokExpireAt(null|int|string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        $seconds = (int) $value;

        return $seconds > time()
            ? Carbon::createFromTimestamp($seconds)
            : now()->addSeconds($seconds);
    }

    private function tokenDateIsFuture(mixed $value): bool
    {
        if (! $value) {
            return false;
        }

        return (bool) $this->parseTokenDate($value)?->isFuture();
    }

    private function parseTokenDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function callbackIsFresh(mixed $value): bool
    {
        if (! $value) {
            return false;
        }

        try {
            return Carbon::parse($value)->greaterThan(now()->subMinutes(30));
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeActiveMarketplaceTokens(): void
    {
        $tables = [
            'shopee_tokens' => 'shopee',
            'tiktok_tokens' => 'tiktok',
        ];

        foreach ($tables as $table => $channel) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

            $accountColumn = Schema::hasColumn($table, 'account_key') ? 'account_key' : (Schema::hasColumn($table, 'account_name') ? 'account_name' : null);

            if (! $accountColumn) {
                continue;
            }

            foreach (self::MARKETPLACE_ACCOUNTS as $key => $account) {
                if ($account['channel'] !== $channel) {
                    continue;
                }

                $latestId = DB::table($table)
                    ->where($accountColumn, $accountColumn === 'account_key' ? $key : $account['name'])
                    ->whereRaw('is_active = true')
                    ->latest('created_at')
                    ->value('id');

                if (! $latestId) {
                    continue;
                }

                DB::table($table)
                    ->where($accountColumn, $accountColumn === 'account_key' ? $key : $account['name'])
                    ->where('id', '<>', $latestId)
                    ->whereRaw('is_active = true')
                    ->update(['is_active' => DB::raw('false'), 'updated_at' => now()]);
            }
        }
    }

    private function tiktokConfig(): array
    {
        $this->ensureTiktokAuthTables();

        $row = SchemaCache::activeTiktokConfig();
        $envAppKey = trim((string) config('tiktok.app_key'));
        $envAppSecret = trim((string) config('tiktok.app_secret'));
        $dbAppKey = trim((string) ($row->app_key ?? ''));
        $dbAppSecret = trim((string) ($row->app_secret ?? ''));

        $appKey = $envAppKey !== '' ? $envAppKey : $dbAppKey;
        $appSecret = $envAppSecret !== '' ? $envAppSecret : $dbAppSecret;
        $redirectUrl = trim((string) ($row->redirect_url ?? config('tiktok.redirect_url')));
        $authHost = trim((string) config('tiktok.auth_host'));
        $apiHost = trim((string) config('tiktok.api_host'));

        if ($appKey === '' || $appSecret === '') {
            abort(422, 'Konfigurasi TikTok belum valid. Isi `TIKTOK_APP_KEY` / `TIKTOK_APP_SECRET` dengan kredensial asli dari TikTok Shop Partner.');
        }

        return [
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'auth_host' => rtrim($authHost, '/'),
            'api_host' => rtrim($apiHost, '/'),
            'redirect_url' => $redirectUrl,
        ];
    }

    private function ensureShopeeAuthColumns(): void
    {
        if (! Schema::hasTable('shopee_tokens')) {
            return;
        }

        foreach ([
            'access_token_expire_at TIMESTAMP NULL',
            'refresh_token_expire_at TIMESTAMP NULL',
        ] as $definition) {
            DB::statement('ALTER TABLE shopee_tokens ADD COLUMN IF NOT EXISTS '.$definition);
        }

        DB::table('shopee_tokens')
            ->whereNotNull('expire_at')
            ->whereNull('access_token_expire_at')
            ->update(['access_token_expire_at' => DB::raw('expire_at')]);

        DB::table('shopee_tokens')
            ->whereNotNull('refresh_token')
            ->whereNull('refresh_token_expire_at')
            ->whereNotNull('created_at')
            ->update(['refresh_token_expire_at' => DB::raw("created_at + INTERVAL '".self::SHOPEE_REFRESH_TOKEN_VALID_DAYS." days'")]);

        DB::table('shopee_tokens')
            ->whereNotNull('refresh_token')
            ->whereNotNull('refresh_token_expire_at')
            ->whereNotNull('created_at')
            ->whereRaw("refresh_token_expire_at < created_at + INTERVAL '".self::SHOPEE_REFRESH_TOKEN_VALID_DAYS." days'")
            ->update(['refresh_token_expire_at' => DB::raw("created_at + INTERVAL '".self::SHOPEE_REFRESH_TOKEN_VALID_DAYS." days'")]);
    }

    private function generateTiktokSign(string $path, array $params, string $secret, ?string $body = null): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $stringToSign = $secret.$path;
        foreach ($params as $key => $value) {
            $stringToSign .= $key.$value;
        }

        $stringToSign .= $body ?? '';
        $stringToSign .= $secret;

        return hash_hmac('sha256', $stringToSign, $secret);
    }

    private function generateTiktokWriteSign(string $path, array $params, string $secret): string
    {
        unset($params['sign'], $params['access_token']);
        ksort($params);

        $stringToSign = $secret.$path;
        foreach ($params as $key => $value) {
            $stringToSign .= $key.$value;
        }
        $stringToSign .= $secret;

        return hash_hmac('sha256', $stringToSign, $secret);
    }

    private function resolveUploadImagePath(?string $imageUrl): ?string
    {
        if (! is_string($imageUrl)) {
            return null;
        }

        $value = trim($imageUrl);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '/cached-images/')) {
            $relative = ltrim(substr($value, strlen('/cached-images/')), '/');
            $path = storage_path('app/public/'.$relative);

            return is_file($path) ? $path : null;
        }

        if (! preg_match('#^https?://#i', $value)) {
            $path = storage_path('app/public/'.ltrim($value, '/'));
            return is_file($path) ? $path : null;
        }

        $cached = $this->cacheMarketplaceImageUrl($value, 'tiktok', 'upload', sha1($value));
        if (is_string($cached) && str_starts_with($cached, '/cached-images/')) {
            $relative = ltrim(substr($cached, strlen('/cached-images/')), '/');
            $path = storage_path('app/public/'.$relative);

            return is_file($path) ? $path : null;
        }

        return null;
    }

    private function ensureTiktokAuthTables(): void
    {
        $this->ensureTiktokProductTables();

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_config (
                id BIGSERIAL PRIMARY KEY,
                app_key TEXT NOT NULL,
                app_secret TEXT NOT NULL,
                auth_host TEXT DEFAULT 'https://auth.tiktok-shops.com',
                api_host TEXT DEFAULT 'https://open-api.tiktokglobalshop.com',
                redirect_url TEXT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_callbacks (
                id SERIAL PRIMARY KEY,
                account_key TEXT,
                account_name TEXT,
                code TEXT,
                app_key TEXT,
                shop_region TEXT,
                state TEXT,
                query_payload JSONB,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_tokens (
                id SERIAL PRIMARY KEY,
                account_key TEXT,
                account_name TEXT,
                open_id TEXT,
                seller_name TEXT,
                seller_region TEXT,
                access_token TEXT,
                refresh_token TEXT,
                expire_at TIMESTAMP NULL,
                expire_in INTEGER NULL,
                access_token_expire_at TIMESTAMP NULL,
                refresh_token_expire_at TIMESTAMP NULL,
                granted_scopes JSONB,
                shop_id TEXT,
                request_id TEXT,
                message TEXT,
                raw_response JSONB,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS tiktok_shops (
                id TEXT PRIMARY KEY,
                shop_id TEXT NULL,
                code TEXT,
                name TEXT,
                region TEXT,
                seller_type TEXT,
                cipher TEXT,
                shop_cipher TEXT NULL,
                raw_response JSONB,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        $existingTiktokConfig = DB::table('tiktok_config')->where('id', 1)->exists();

        if (! $existingTiktokConfig) {
            $appKey = trim((string) config('tiktok.app_key'));
            $appSecret = trim((string) config('tiktok.app_secret'));

            if ($appKey !== '' && $appSecret !== '') {
                DB::table('tiktok_config')->insert([
                    'id' => 1,
                    'app_key' => $appKey,
                    'app_secret' => $appSecret,
                    'auth_host' => rtrim((string) config('tiktok.auth_host'), '/'),
                    'api_host' => rtrim((string) config('tiktok.api_host'), '/'),
                    'redirect_url' => trim((string) config('tiktok.redirect_url')),
                    'is_active' => DB::raw('true'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach ([
            'tiktok_callbacks' => [
                'account_key TEXT',
                'account_name TEXT',
                'query_payload JSONB',
                'used_at TIMESTAMP NULL',
                'updated_at TIMESTAMP DEFAULT NOW()',
            ],
            'tiktok_tokens' => [
                'account_key TEXT',
                'account_name TEXT',
                'expire_in INTEGER NULL',
                'access_token_expire_at TIMESTAMP NULL',
                'refresh_token_expire_at TIMESTAMP NULL',
                'granted_scopes JSONB',
                'shop_id TEXT',
                'request_id TEXT',
                'message TEXT',
                'raw_response JSONB',
                'is_active BOOLEAN DEFAULT TRUE',
                'updated_at TIMESTAMP DEFAULT NOW()',
            ],
            'tiktok_shops' => [
                'shop_id TEXT',
                'raw_response JSONB',
                'shop_cipher TEXT NULL',
                'created_at TIMESTAMP DEFAULT NOW()',
                'updated_at TIMESTAMP DEFAULT NOW()',
            ],
            'tiktok_products' => [
                'image_url TEXT',
                'sku_id TEXT NULL',
            ],
        ] as $table => $columns) {
            foreach ($columns as $definition) {
                DB::statement('ALTER TABLE '.$table.' ADD COLUMN IF NOT EXISTS '.$definition);
            }
        }
    }

    private function shopeeConfig(): array
    {
        $row = SchemaCache::activeShopeeConfig();

        $partnerId = (int) ($row->partner_id ?? config('shopee.partner_id'));
        $partnerKey = (string) ($row->partner_key ?? config('shopee.partner_key'));

        abort_if($partnerId <= 0 || $partnerKey === '', 422, 'Konfigurasi Shopee belum lengkap.');

        return [
            'partner_id' => $partnerId,
            'partner_key' => $partnerKey,
            'host' => rtrim((string) ($row->host ?? config('shopee.host')), '/'),
            'redirect_url' => (string) ($row->redirect_url ?? config('shopee.redirect_url')),
        ];
    }

    private function generateShopeeSign(int $partnerId, string $partnerKey, string $path, int $timestamp): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp, $partnerKey);
    }

    private function generateShopeeApiSign(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp.$accessToken.$shopId, $partnerKey);
    }

    private function ensureShopeeProductTables(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product (
                item_id BIGINT PRIMARY KEY,
                shop_id BIGINT NULL,
                name TEXT NULL,
                description TEXT NULL,
                category_id BIGINT NULL,
                price_min BIGINT DEFAULT 0,
                price_max BIGINT DEFAULT 0,
                price_before_discount BIGINT DEFAULT 0,
                currency TEXT NULL,
                stock INTEGER DEFAULT 0,
                sold INTEGER DEFAULT 0,
                liked_count INTEGER DEFAULT 0,
                rating NUMERIC(8,2) DEFAULT 0,
                historical_sold INTEGER DEFAULT 0,
                status TEXT NULL,
                create_time TIMESTAMP NULL,
                update_time TIMESTAMP NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product_model (
                model_id TEXT NOT NULL,
                item_id BIGINT NOT NULL,
                name TEXT NULL,
                model_sku TEXT NULL,
                price BIGINT DEFAULT 0,
                original_price BIGINT DEFAULT 0,
                stock INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                PRIMARY KEY (model_id, item_id)
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_product_image (
                id BIGSERIAL PRIMARY KEY,
                item_id BIGINT NOT NULL,
                model_id TEXT NULL,
                image_url TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS stock_master (
                id BIGSERIAL PRIMARY KEY,
                internal_sku TEXT UNIQUE NOT NULL,
                shopee_product_id TEXT NULL,
                shopee_sku TEXT NULL,
                shopee_seller_sku TEXT NULL,
                product_name TEXT NULL,
                variant_name TEXT NULL,
                stock_qty INTEGER DEFAULT 0,
                tiktok_product_id TEXT NULL,
                tiktok_sku TEXT NULL,
                tiktok_seller_sku TEXT NULL,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS shopee_sync_logs (
                id BIGSERIAL PRIMARY KEY,
                status TEXT NULL,
                message TEXT NULL,
                product_count INTEGER DEFAULT 0,
                variant_count INTEGER DEFAULT 0,
                synced_at TIMESTAMP DEFAULT NOW(),
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        foreach ([
            'shopee_product' => ['created_at TIMESTAMP DEFAULT NOW()', 'updated_at TIMESTAMP DEFAULT NOW()', 'price_before_discount BIGINT DEFAULT 0'],
            'shopee_product_model' => ['created_at TIMESTAMP DEFAULT NOW()', 'original_price BIGINT DEFAULT 0', 'model_sku TEXT NULL'],
            'shopee_product_image' => ['updated_at TIMESTAMP DEFAULT NOW()'],
            'stock_master' => ['created_at TIMESTAMP DEFAULT NOW()', 'updated_at TIMESTAMP DEFAULT NOW()', 'shopee_seller_sku TEXT NULL', 'tiktok_product_id TEXT NULL', 'tiktok_sku TEXT NULL', 'tiktok_seller_sku TEXT NULL', 'is_hidden_from_mapping BOOLEAN DEFAULT FALSE', 'hidden_from_mapping_reason TEXT NULL', 'hidden_from_mapping_at TIMESTAMP NULL', 'hidden_from_mapping_by VARCHAR(255) NULL'],
        ] as $table => $columns) {
            foreach ($columns as $definition) {
                DB::statement('ALTER TABLE '.$table.' ADD COLUMN IF NOT EXISTS '.$definition);
            }
        }
    }

    private function shopeePrice(mixed $value): int
    {
        $number = $this->toInt($value);

        if (abs($number) > 1000000) {
            return (int) floor($number / 100000);
        }

        return $number;
    }

    private function shopeePriceInfoValue(mixed $priceInfo, string $key, mixed $fallback = 0): mixed
    {
        if (is_array($priceInfo)) {
            if (array_key_exists($key, $priceInfo)) {
                return $priceInfo[$key];
            }

            if (isset($priceInfo[0]) && is_array($priceInfo[0]) && array_key_exists($key, $priceInfo[0])) {
                return $priceInfo[0][$key];
            }
        }

        return $fallback;
    }

    private function shopeeStock(array $item): int
    {
        $stock = data_get($item, 'stock_info.normal_stock');

        if ($stock === null) {
            $stock = data_get($item, 'stock_info.0.normal_stock', $item['stock'] ?? 0);
        }

        return $this->toInt($stock);
    }

    private function shopeeModelStock(array $model): int
    {
        $stock = data_get($model, 'stock_info_v2.summary_info.total_available_stock');

        if ($stock !== null) {
            return $this->toInt($stock);
        }

        return $this->toInt($model['stock'] ?? 0);
    }

    private function toInt(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $normalized = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($normalized) ? (int) $normalized : 0;
    }

    private function timestampToDate(mixed $value): ?Carbon
    {
        $timestamp = $this->toInt($value);

        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
    }

    private function timestampToDateString(mixed $value): ?string
    {
        return $this->timestampToDate($value)?->toDateTimeString();
    }

    private function sanitizeSkuFragment(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/[^A-Z0-9_-]+/', '-', $normalized);
        $normalized = trim((string) $normalized, '-');

        return substr($normalized !== '' ? $normalized : 'X', 0, 30);
    }

    private function resolveAccountFromAction(string $action): ?array
    {
        foreach (self::MARKETPLACE_ACCOUNTS as $key => $account) {
            if (str_ends_with($action, $key)) {
                return ['key' => $key, ...$account];
            }
        }

        return match ($action) {
            'connect-shopee', 'auth-shopee', 'get-token-shopee', 'refresh-token-shopee' => $this->resolveAccount('shopee-agnishopbjm', 'shopee'),
            'connect-tiktok', 'auth-tiktok', 'get-token-tiktok', 'refresh-token-tiktok', 'get-auth-shop-tiktok' => $this->resolveAccount('tiktok-agnishopbjm', 'tiktok'),
            default => null,
        };
    }

    private function resolveAccount(string $key, string $channel): array
    {
        $resolvedKey = array_key_exists($key, self::MARKETPLACE_ACCOUNTS)
            ? $key
            : ($channel === 'tiktok' ? 'tiktok-agnishopbjm' : 'shopee-agnishopbjm');
        $account = self::MARKETPLACE_ACCOUNTS[$resolvedKey];

        abort_if($account['channel'] !== $channel, 422, 'Klasifikasi akun marketplace tidak valid.');

        return ['key' => $resolvedKey, ...$account];
    }
}

final class SchemaCache
{
    public static function activeShopeeConfig(): ?object
    {
        try {
            return DB::table('shopee_config')->whereRaw('is_active = true')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function activeTiktokConfig(): ?object
    {
        try {
            return DB::table('tiktok_config')->whereRaw('is_active = true')->orderByDesc('id')->first()
                ?: DB::table('tiktok_config')->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
