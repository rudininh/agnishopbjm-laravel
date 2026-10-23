<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ShopeeSkuTiktokVariantCleanupService
{
    private const ACTION_TYPE = 'shopee_sku_tiktok_delete';

    private const EXECUTION_LEASE_SECONDS = 900;

    private const SECRET_KEYS = [
        'access_token',
        'api_key',
        'app_key',
        'app_secret',
        'client_secret',
        'partner_key',
        'refresh_token',
        'secret',
        'sign',
        'shop_cipher',
        'token',
        'authorization',
        'cookie',
        'cookies',
        'password',
        'x-tts-access-token',
    ];

    public function __construct(
        private readonly ShopeeSellerSkuTemplate $sellerSkuTemplate,
        private readonly MarketplaceApiService $marketplaceApi,
        private readonly TiktokPartialEditSkuPayloadBuilder $tiktokPayloadBuilder,
    ) {
    }

    public function createPreview(Collection $mappingOnlyGroups): array
    {
        $preview = $this->buildPreview($mappingOnlyGroups);
        $runId = (string) Str::uuid();

        DB::transaction(function () use ($runId, $preview): void {
            DB::table('tiktok_reconciliation_runs')->insert([
                'id' => $runId,
                'revision' => $preview['revision'],
                'status' => 'ready_for_review',
                'summary' => $this->encodeJson($this->redact($preview['summary'])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($preview['items'] as $item) {
                $isReady = $item['status'] === 'ready';
                DB::table('tiktok_reconciliation_run_items')->insert([
                    'run_id' => $runId,
                    'stock_master_id' => $item['stock_master_ids'][0] ?? null,
                    'item_key' => $item['item_key'],
                    'action_type' => self::ACTION_TYPE,
                    'status' => $item['status'],
                    'source_fingerprint' => $item['source_fingerprint'],
                    'target_product_id' => $isReady ? $item['tiktok_product_id'] : null,
                    'target_sku_id' => $isReady ? $item['tiktok_sku_id'] : null,
                    'block_reason' => $item['block_reason'],
                    'payload' => $this->encodeJson($this->redact($item)),
                    'result' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->loadRun($runId);
    }

    public function loadRun(string $runId): array
    {
        $run = DB::table('tiktok_reconciliation_runs')->where('id', $runId)->first();
        if (! $run) {
            return ['status' => 'not_found', 'run_id' => $runId];
        }

        $items = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $runId)
            ->where('action_type', self::ACTION_TYPE)
            ->orderBy('item_key')
            ->get()
            ->map(function (object $row): array {
                $item = $this->decodeJson($row->payload) ?? [];
                $item['item_key'] = (string) $row->item_key;
                $item['action_type'] = self::ACTION_TYPE;
                $item['status'] = (string) $row->status;
                $item['source_fingerprint'] = (string) $row->source_fingerprint;
                $item['block_reason'] = $row->block_reason === null ? null : (string) $row->block_reason;
                $item['stock_master_ids'] = $item['stock_master_ids']
                    ?? array_values(array_filter([(int) ($row->stock_master_id ?? 0)]));
                if ($row->result !== null) {
                    $item['result'] = $this->decodeJson($row->result);
                }

                return $item;
            })
            ->all();

        return [
            'status' => (string) $run->status,
            'run_id' => $runId,
            'revision' => (string) $run->revision,
            'summary' => $this->decodeJson($run->summary) ?? [],
            'items' => $items,
        ];
    }

    public function currentRevision(Collection $mappingOnlyGroups): string
    {
        return $this->buildPreview($mappingOnlyGroups)['revision'];
    }

    public function submit(string $runId, string $revision, Collection $currentGroups): array
    {
        try {
            return Cache::lock('shopee-sku-tiktok-cleanup', 900)
                ->block(5, fn (): array => $this->submitLocked($runId, $revision, $currentGroups));
        } catch (LockTimeoutException) {
            return ['status' => 'busy', 'run_id' => $runId];
        }
    }

    private function submitLocked(string $runId, string $revision, Collection $currentGroups): array
    {
        $claim = DB::transaction(function () use ($runId, $revision, $currentGroups): array {
            $run = DB::table('tiktok_reconciliation_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $run) {
                return ['status' => 'not_found', 'run_id' => $runId];
            }

            if ((string) $run->status === 'completed') {
                return $this->loadRun($runId);
            }

            if ((string) $run->revision !== $revision) {
                return ['status' => 'stale_revision', 'run_id' => $runId];
            }

            if ((string) $run->status === 'ready_for_review') {
                if ($this->currentRevision($currentGroups) !== $revision) {
                    return ['status' => 'stale_revision', 'run_id' => $runId];
                }

                DB::table('tiktok_reconciliation_runs')->where('id', $runId)->update([
                    'status' => 'claimed',
                    'submitted_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $this->loadRun($runId);
        });

        if (! in_array($claim['status'] ?? '', ['claimed', 'partial', 'failed'], true)) {
            return $claim;
        }

        return $this->executeClaimedRun($runId);
    }

    private function executeClaimedRun(string $runId): array
    {
        $rows = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $runId)
            ->where('action_type', self::ACTION_TYPE)
            ->whereIn('status', ['ready', 'partial', 'failed', 'submitted_unverified'])
            ->orderBy('item_key')
            ->get();

        $items = $rows->map(fn (object $row): array => $this->hydrateExecutionItem($row));

        $collidingItems = $this->plannedTargetCollisionItems($items);
        $blockedProductIds = $collidingItems->pluck('tiktok_product_id')->uniqueStrict();

        foreach ($items->groupBy('tiktok_product_id') as $productItems) {
            $owner = (string) Str::uuid();
            $claimedItems = $this->claimProductGroup($productItems->values(), $owner);
            if ($claimedItems === null) {
                continue;
            }

            try {
                if ($blockedProductIds->containsStrict($claimedItems->first()['tiktok_product_id'] ?? null)) {
                    $status = $this->itemsHaveIrreversibleDeleteEvidence($claimedItems) ? 'partial' : 'failed';
                    $this->persistProductFailure(
                        $claimedItems,
                        $status,
                        'planned_shopee_target_collision',
                        ['precondition' => 'planned_shopee_target_collision'],
                        $owner,
                    );

                    continue;
                }

                $this->executeProductGroup($claimedItems, $owner);
            } catch (Throwable) {
                $this->persistUnexpectedProductExceptionIfOwned($claimedItems, $owner);
            } finally {
                $this->releaseProductLease($claimedItems, $owner);
            }
        }

        $this->updateRunStatus($runId);

        return $this->loadRun($runId);
    }

    private function executeProductGroup(Collection $items, string $owner): void
    {
        $productId = $this->stringValue($items->first()['tiktok_product_id'] ?? '');
        $targetIds = $items
            ->pluck('tiktok_sku_id')
            ->map(fn (mixed $id): string => $this->stringValue($id))
            ->filter()
            ->uniqueStrict()
            ->values()
            ->all();

        $tiktokBeforeResult = $this->marketplaceCall(
            $items,
            $owner,
            fn (): array => $this->marketplaceApi->fetchTiktokProduct($productId),
        );
        $productBefore = data_get($tiktokBeforeResult, 'data.product');
        if (! ($tiktokBeforeResult['ok'] ?? false) || ! is_array($productBefore)) {
            $this->persistProductFailure($items, 'failed', 'fresh_tiktok_unavailable', [
                'tiktok_before' => $tiktokBeforeResult,
            ], $owner);

            return;
        }

        $modelsByItem = [];
        $modelFetchResults = [];
        foreach ($items->pluck('shopee_item_id')->map(fn (mixed $id): string => $this->stringValue($id))->uniqueStrict() as $itemId) {
            $result = $this->marketplaceCall(
                $items,
                $owner,
                fn (): array => $this->marketplaceApi->fetchShopeeModels($itemId),
            );
            $models = data_get($result, 'data.models');
            $modelFetchResults[$itemId] = $result;
            if (! ($result['ok'] ?? false) || ! is_array($models)) {
                $this->persistProductFailure($items, 'failed', 'fresh_shopee_unavailable', [
                    'tiktok_before' => $tiktokBeforeResult,
                    'shopee_before' => $modelFetchResults,
                ], $owner);

                return;
            }
            $modelsByItem[$itemId] = $models;
        }

        $freshSkus = $this->productSkus($productBefore);
        $preconditionFailure = $this->productPreconditionFailure($items, $freshSkus, $modelsByItem);
        if ($preconditionFailure !== null) {
            $failureStatus = $items->contains(
                fn (array $item): bool => data_get($item, '_persisted_result.tiktok_verified') === true,
            ) ? 'partial' : 'failed';
            $this->persistProductFailure($items, $failureStatus, $preconditionFailure, [
                'tiktok_before' => $tiktokBeforeResult,
                'shopee_before' => $modelFetchResults,
            ], $owner);

            return;
        }

        $presentIds = array_values(array_intersect($targetIds, $this->skuIds($freshSkus)));
        $expectedNonTargetIds = array_values(array_diff($this->skuIds($freshSkus), $targetIds));
        $persistedExpectedIds = $this->persistedExpectedNonTargetIds($items);
        $deleteWasAttempted = $this->persistedDeleteWasAttempted($items);
        $deleteResult = $this->persistedTiktokDeleteResult($items);
        $verificationResult = $tiktokBeforeResult;

        if (! $deleteWasAttempted && count($presentIds) !== count($targetIds)) {
            $this->persistProductFailure($items, 'failed', 'tiktok_targets_missing_before_submit', [
                'tiktok_before' => $tiktokBeforeResult,
                'shopee_before' => $modelFetchResults,
            ], $owner);

            return;
        } elseif ($presentIds === []) {
            if (! $deleteWasAttempted || $persistedExpectedIds === []) {
                $this->persistProductFailure($items, 'failed', 'tiktok_targets_missing_before_submit', [
                    'tiktok_before' => $tiktokBeforeResult,
                    'shopee_before' => $modelFetchResults,
                ], $owner);

                return;
            }
            $expectedNonTargetIds = $persistedExpectedIds;
        } elseif ($deleteWasAttempted) {
            if ($persistedExpectedIds === []) {
                $this->persistProductFailure($items, 'submitted_unverified', 'tiktok_delete_unverified', [
                    'tiktok_delete_attempted' => true,
                    'tiktok_before' => $tiktokBeforeResult,
                    'shopee_before' => $modelFetchResults,
                ], $owner);

                return;
            }
            $expectedNonTargetIds = $persistedExpectedIds;
        } else {
            try {
                $payload = $this->tiktokPayloadBuilder->deleteSkuIds($productBefore, $presentIds);
            } catch (RuntimeException $exception) {
                $this->persistProductFailure($items, 'failed', 'invalid_tiktok_delete_contract', [
                    'message' => $exception->getMessage(),
                    'tiktok_before' => $tiktokBeforeResult,
                    'shopee_before' => $modelFetchResults,
                ], $owner);

                return;
            }

            try { StockHubTiktokVariantInterlock::assertRepairAvailable(null, $productId); }
            catch (\DomainException $e) {
                $this->persistProductFailure($items, 'failed', 'tiktok_repair_pending', ['message' => $e->getMessage()], $owner);
                return;
            }
            $this->persistDeleteIntent($items, $expectedNonTargetIds, $tiktokBeforeResult, $owner);
            $deleteResult = $this->marketplaceCall(
                $items,
                $owner,
                fn (): array => $this->marketplaceApi->partialEditTiktokProduct($productId, $payload),
            );
            $this->persistProductEvidence($items, [
                'tiktok_delete_attempted' => true,
                'expected_non_target_sku_ids' => $expectedNonTargetIds,
                'tiktok_delete' => $deleteResult,
            ], $owner);
            if (! ($deleteResult['ok'] ?? false)) {
                $this->persistProductFailure($items, 'failed', 'tiktok_delete_failed', [
                    'tiktok_delete_attempted' => true,
                    'expected_non_target_sku_ids' => $expectedNonTargetIds,
                    'tiktok_before' => $tiktokBeforeResult,
                    'tiktok_delete' => $deleteResult,
                    'shopee_before' => $modelFetchResults,
                ], $owner);

                return;
            }

            $verificationResult = $this->marketplaceCall(
                $items,
                $owner,
                fn (): array => $this->marketplaceApi->fetchTiktokProduct($productId),
            );
        }

        $verifiedProduct = data_get($verificationResult, 'data.product');
        $verifiedSkuIds = is_array($verifiedProduct) ? $this->skuIds($this->productSkus($verifiedProduct)) : [];
        $tiktokVerified = ($verificationResult['ok'] ?? false)
            && is_array($verifiedProduct)
            && array_intersect($targetIds, $verifiedSkuIds) === []
            && array_diff($expectedNonTargetIds, $verifiedSkuIds) === [];

        if (! $tiktokVerified) {
            $this->persistProductFailure($items, 'submitted_unverified', 'tiktok_delete_unverified', [
                'tiktok_delete_attempted' => true,
                'expected_non_target_sku_ids' => $expectedNonTargetIds,
                'tiktok_before' => $tiktokBeforeResult,
                'tiktok_delete' => $deleteResult,
                'tiktok_verification' => $verificationResult,
                'shopee_before' => $modelFetchResults,
            ], $owner);

            return;
        }

        $baseResult = [
            'tiktok_verified' => true,
            'tiktok_delete_attempted' => true,
            'expected_non_target_sku_ids' => $expectedNonTargetIds,
            'tiktok_delete' => $deleteResult,
            'tiktok_verification' => $verificationResult,
        ];
        $this->persistProductOutcome($items, 'partial', 'shopee_update_pending', $baseResult, $owner);

        foreach ($items as $item) {
            $reflectionFailure = $this->reflectVerifiedTiktokDeletion($item, $owner);
            if ($reflectionFailure !== null) {
                $this->persistItemOutcome($item, 'partial', $reflectionFailure, $baseResult, $owner);

                continue;
            }
            $this->executeShopeeUpdate(
                $item,
                $modelsByItem[$item['shopee_item_id']] ?? [],
                $baseResult,
                $items,
                $owner,
            );
        }
    }

    private function executeShopeeUpdate(
        array $item,
        array $modelsBefore,
        array $baseResult,
        Collection $productItems,
        string $owner,
    ): void
    {
        $itemId = $this->stringValue($item['shopee_item_id'] ?? '');
        $modelId = $this->stringValue($item['shopee_model_id'] ?? '');
        $oldSku = $this->stringValue($item['old_sku'] ?? '');
        $targetSku = $this->stringValue($item['target_sku'] ?? '');
        $model = $this->findShopeeModel($modelsBefore, $modelId);
        $freshSku = $this->stringValue($model['model_sku'] ?? '');
        $updateResult = null;

        if ($freshSku !== $targetSku) {
            if ($freshSku !== $oldSku) {
                $this->persistItemOutcome($item, 'partial', 'shopee_source_changed', $baseResult, $owner);

                return;
            }

            $updateResult = $this->marketplaceCall(
                $productItems,
                $owner,
                fn (): array => $this->marketplaceApi->updateShopeeModelSku($itemId, $modelId, $targetSku),
            );
            if (! ($updateResult['ok'] ?? false)) {
                $this->persistItemOutcome($item, 'partial', 'shopee_update_failed', [
                    ...$baseResult,
                    'shopee_update' => $updateResult,
                ], $owner);

                return;
            }

            $verificationResult = $this->marketplaceCall(
                $productItems,
                $owner,
                fn (): array => $this->marketplaceApi->fetchShopeeModels($itemId),
            );
            $verifiedModels = data_get($verificationResult, 'data.models');
            $verifiedModel = is_array($verifiedModels) ? $this->findShopeeModel($verifiedModels, $modelId) : null;
            if (! ($verificationResult['ok'] ?? false)
                || ! is_array($verifiedModel)
                || $this->stringValue($verifiedModel['model_sku'] ?? '') !== $targetSku) {
                $this->persistItemOutcome($item, 'submitted_unverified', 'shopee_update_unverified', [
                    ...$baseResult,
                    'shopee_update' => $updateResult,
                    'shopee_verification' => $verificationResult,
                ], $owner);

                return;
            }
        } else {
            $verificationResult = [
                'ok' => true,
                'message' => 'Shopee target SKU already verified by the fresh precondition read.',
                'data' => ['item_id' => $itemId, 'models' => $modelsBefore],
            ];
        }

        $reconciliationFailure = $this->reconcileVerifiedShopeeUpdate($item, $owner);
        if ($reconciliationFailure !== null) {
            $this->persistItemOutcome($item, 'partial', $reconciliationFailure, [
                ...$baseResult,
                'shopee_update' => $updateResult,
                'shopee_verification' => $verificationResult,
            ], $owner);

            return;
        }
        $this->persistItemOutcome($item, 'updated', null, [
            ...$baseResult,
            'shopee_update' => $updateResult,
            'shopee_verification' => $verificationResult,
        ], $owner);
    }

    private function productPreconditionFailure(Collection $items, array $freshSkus, array $modelsByItem): ?string
    {
        foreach ($items as $item) {
            if (! $this->localIdentityIsCurrent($item)) {
                return 'local_identity_changed';
            }

            $itemId = $this->stringValue($item['shopee_item_id'] ?? '');
            $modelId = $this->stringValue($item['shopee_model_id'] ?? '');
            $oldSku = $this->stringValue($item['old_sku'] ?? '');
            $targetSku = $this->stringValue($item['target_sku'] ?? '');
            $targetTiktokSkuId = $this->stringValue($item['tiktok_sku_id'] ?? '');
            $models = $modelsByItem[$itemId] ?? [];
            $model = $this->findShopeeModel($models, $modelId);

            if (! is_array($model)) {
                return 'shopee_model_missing';
            }

            $freshName = $this->shopeeModelName($model);
            if ($freshName !== $this->stringValue($item['shopee_variant_name'] ?? '')) {
                return 'shopee_model_name_changed';
            }

            try {
                $freshTargetSku = $this->sellerSkuTemplate->build($itemId, $freshName);
            } catch (\InvalidArgumentException) {
                return 'invalid_target_sku';
            }
            if ($freshTargetSku !== $targetSku) {
                return 'shopee_target_changed';
            }

            $freshSourceSku = $this->stringValue($model['model_sku'] ?? '');
            $canAlreadyBeTarget = data_get($item, '_persisted_result.tiktok_verified') === true
                || in_array($item['_persisted_status'], ['partial', 'submitted_unverified'], true);
            if ($freshSourceSku !== $oldSku && (! $canAlreadyBeTarget || $freshSourceSku !== $targetSku)) {
                return 'shopee_source_changed';
            }

            foreach ($models as $candidate) {
                $candidate = $this->arrayValue($candidate);
                if ($this->stringValue($candidate['model_id'] ?? '') !== $modelId
                    && $this->normalizedSku($candidate['model_sku'] ?? '') === $this->normalizedSku($targetSku)) {
                    return 'shopee_target_collision';
                }
            }

            $targetTiktokSku = $this->findTiktokSku($freshSkus, $targetTiktokSkuId);
            if (is_array($targetTiktokSku)
                && $this->normalizedSku($this->tiktokSellerSku($targetTiktokSku)) !== $this->normalizedSku($oldSku)) {
                return 'source_sku_mismatch';
            }

            foreach ($freshSkus as $candidate) {
                $candidate = $this->arrayValue($candidate);
                if ($this->stringValue($candidate['id'] ?? $candidate['sku_id'] ?? '') !== $targetTiktokSkuId
                    && $this->normalizedSku($this->tiktokSellerSku($candidate)) === $this->normalizedSku($targetSku)) {
                    return 'tiktok_target_collision';
                }
            }
        }

        return null;
    }

    private function localIdentityIsCurrent(
        array $item,
        bool $lockForUpdate = false,
        ?bool $allowClearedTiktokIdentity = null,
    ): bool
    {
        $stockMasterIds = array_values(array_filter(array_map('intval', $item['stock_master_ids'] ?? [])));
        if ($stockMasterIds === []) {
            return true;
        }

        $itemId = $this->stringValue($item['shopee_item_id'] ?? '');
        $modelId = $this->stringValue($item['shopee_model_id'] ?? '');
        $productId = $this->stringValue($item['tiktok_product_id'] ?? '');
        $tiktokSkuId = $this->stringValue($item['tiktok_sku_id'] ?? '');
        $oldSku = $this->stringValue($item['old_sku'] ?? '');
        $targetSku = $this->stringValue($item['target_sku'] ?? '');
        $allowClearedTiktokIdentity ??= data_get($item, '_persisted_result.tiktok_verified') === true;

        foreach ($stockMasterIds as $stockMasterId) {
            $stockQuery = DB::table('stock_master')->where('id', $stockMasterId);
            $mappingQuery = DB::table('sku_mappings')->where('stock_master_id', $stockMasterId);
            if ($lockForUpdate) {
                $stockQuery->lockForUpdate();
                $mappingQuery->lockForUpdate();
            }
            $stock = $stockQuery->first();
            $mapping = $mappingQuery->first();
            if (! $stock && ! $mapping) {
                return false;
            }

            if ($stock) {
                $stockTiktokSku = $this->stringValue($stock->tiktok_sku ?? '');
                $stockTiktokSellerSku = $this->stringValue($stock->tiktok_seller_sku ?? '');
                $allowedShopeeSkus = $allowClearedTiktokIdentity ? [$oldSku, $targetSku] : [$oldSku];
                if ($this->stringValue($stock->shopee_product_id ?? '') !== $itemId
                    || $this->stringValue($stock->shopee_sku ?? '') !== $modelId
                    || ! in_array($this->stringValue($stock->shopee_seller_sku ?? ''), $allowedShopeeSkus, true)
                    || $this->stringValue($stock->tiktok_product_id ?? '') !== $productId
                    || (! $allowClearedTiktokIdentity && $stockTiktokSku !== $tiktokSkuId)
                    || ($allowClearedTiktokIdentity && ! in_array($stockTiktokSku, ['', $tiktokSkuId], true))
                    || (! $allowClearedTiktokIdentity && $stockTiktokSellerSku !== $oldSku)
                    || ($allowClearedTiktokIdentity && ! in_array($stockTiktokSellerSku, ['', $oldSku], true))) {
                    return false;
                }
            }

            if ($mapping) {
                $mappingTiktokSku = $this->stringValue($mapping->tiktok_sku_id ?? '');
                $allowedMappingSellerSkus = $allowClearedTiktokIdentity ? [$oldSku, $targetSku] : [$oldSku];
                if ($this->stringValue($mapping->shopee_item_id ?? '') !== $itemId
                    || $this->stringValue($mapping->shopee_model_id ?? '') !== $modelId
                    || ! in_array($this->stringValue($mapping->seller_sku ?? ''), $allowedMappingSellerSkus, true)
                    || $this->stringValue($mapping->tiktok_product_id ?? '') !== $productId
                    || (! $allowClearedTiktokIdentity && $mappingTiktokSku !== $tiktokSkuId)
                    || ($allowClearedTiktokIdentity && ! in_array($mappingTiktokSku, ['', $tiktokSkuId], true))) {
                    return false;
                }
            }
        }

        return true;
    }

    private function reflectVerifiedTiktokDeletion(array $item, string $owner): ?string
    {
        return DB::transaction(function () use ($item, $owner): ?string {
            if (! $this->executionItemIsOwned($item, $owner)) {
                return 'execution_lease_lost';
            }

            $productId = $this->stringValue($item['tiktok_product_id'] ?? '');
            $skuId = $this->stringValue($item['tiktok_sku_id'] ?? '');
            $oldSku = $this->stringValue($item['old_sku'] ?? '');
            $tiktokRow = DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->where('sku_id', $skuId)
                ->lockForUpdate()
                ->first();
            if (! $tiktokRow
                || $this->normalizedSku($tiktokRow->seller_sku ?? '') !== $this->normalizedSku($oldSku)) {
                return 'tiktok_cache_identity_changed';
            }

            $tiktokUpdate = ['is_active' => DB::raw('false'), 'updated_at' => now()];
            if (Schema::hasColumn('tiktok_products', 'stock_qty')) {
                $tiktokUpdate['stock_qty'] = 0;
            }
            $affected = DB::table('tiktok_products')->where('id', $tiktokRow->id)->update($tiktokUpdate);
            $reflectedRow = DB::table('tiktok_products')->where('id', $tiktokRow->id)->first();
            if ($affected !== 1
                || ! $reflectedRow
                || (bool) ($reflectedRow->is_active ?? true)
                || (Schema::hasColumn('tiktok_products', 'stock_qty') && (int) $reflectedRow->stock_qty !== 0)) {
                return 'tiktok_cache_identity_changed';
            }

            if (! $this->localIdentityIsCurrent($item, true)) {
                return 'local_identity_changed';
            }

            $stockMasterIds = array_values(array_filter(array_map('intval', $item['stock_master_ids'] ?? [])));
            if ($stockMasterIds !== []) {
                DB::table('stock_master')->whereIn('id', $stockMasterIds)->update($this->stockMasterTiktokClearValues());
                DB::table('sku_mappings')->whereIn('stock_master_id', $stockMasterIds)->update($this->mappingTiktokClearValues());
            }

            return null;
        });
    }

    private function reconcileVerifiedShopeeUpdate(array $item, string $owner): ?string
    {
        return DB::transaction(function () use ($item, $owner): ?string {
            if (! $this->executionItemIsOwned($item, $owner)) {
                return 'execution_lease_lost';
            }

            $itemId = $this->stringValue($item['shopee_item_id'] ?? '');
            $modelId = $this->stringValue($item['shopee_model_id'] ?? '');
            $oldSku = $this->stringValue($item['old_sku'] ?? '');
            $targetSku = $this->stringValue($item['target_sku'] ?? '');
            $expectedName = $this->stringValue($item['shopee_variant_name'] ?? '');
            $modelRow = DB::table('shopee_product_model')
                ->where('item_id', $itemId)
                ->where('model_id', $modelId)
                ->lockForUpdate()
                ->first();
            if (! $modelRow
                || $this->stringValue($modelRow->name ?? '') !== $expectedName
                || ! in_array($this->stringValue($modelRow->model_sku ?? ''), [$oldSku, $targetSku], true)) {
                return 'shopee_cache_identity_changed';
            }

            if (! $this->localIdentityIsCurrent($item, true, true)) {
                return 'local_identity_changed';
            }

            $affected = DB::table('shopee_product_model')
                ->where('item_id', $itemId)
                ->where('model_id', $modelId)
                ->whereIn('model_sku', [$oldSku, $targetSku])
                ->update(['model_sku' => $targetSku, 'updated_at' => now()]);
            $persistedSku = DB::table('shopee_product_model')
                ->where('item_id', $itemId)
                ->where('model_id', $modelId)
                ->value('model_sku');
            if ($affected !== 1 || $this->stringValue($persistedSku) !== $targetSku) {
                return 'shopee_cache_identity_changed';
            }

            $stockMasterIds = array_values(array_filter(array_map('intval', $item['stock_master_ids'] ?? [])));
            if ($stockMasterIds === []) {
                return null;
            }

            DB::table('stock_master')->whereIn('id', $stockMasterIds)->update([
                ...$this->stockMasterTiktokClearValues(),
                'shopee_seller_sku' => $targetSku,
            ]);
            DB::table('sku_mappings')->whereIn('stock_master_id', $stockMasterIds)->update([
                ...$this->mappingTiktokClearValues(),
                'seller_sku' => $targetSku,
            ]);

            return null;
        });
    }

    private function stockMasterTiktokClearValues(): array
    {
        $values = ['tiktok_sku' => null, 'updated_at' => now()];
        foreach (['tiktok_seller_sku', 'tiktok_image_url'] as $column) {
            if (Schema::hasColumn('stock_master', $column)) {
                $values[$column] = null;
            }
        }

        return $values;
    }

    private function mappingTiktokClearValues(): array
    {
        $values = ['tiktok_sku_id' => null, 'updated_at' => now()];
        foreach (['tiktok_sku_name', 'tiktok_image_url'] as $column) {
            if (Schema::hasColumn('sku_mappings', $column)) {
                $values[$column] = null;
            }
        }

        return $values;
    }

    private function hydrateExecutionItem(object $row): array
    {
        $payload = $this->decodeJson($row->payload) ?? [];
        $payload['_row_id'] = (int) $row->id;
        $payload['_persisted_status'] = (string) $row->status;
        $payload['_persisted_result'] = $this->decodeJson($row->result) ?? [];

        return $payload;
    }

    private function claimProductGroup(Collection $items, string $owner): ?Collection
    {
        return DB::transaction(function () use ($items, $owner): ?Collection {
            $ids = $items->pluck('_row_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $productId = $this->stringValue($items->first()['tiktok_product_id'] ?? '');
            $allRows = DB::table('tiktok_reconciliation_run_items')
                ->where('action_type', self::ACTION_TYPE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $rows = $allRows
                ->filter(fn (object $row): bool => in_array((int) $row->id, $ids, true))
                ->values();
            if ($rows->count() !== count($ids)) {
                throw new RuntimeException('Cleanup execution rows changed before claim.');
            }

            $productRows = $allRows->filter(function (object $row) use ($productId): bool {
                $payload = $this->decodeJson($row->payload) ?? [];

                return $this->stringValue($payload['tiktok_product_id'] ?? '') === $productId;
            });
            $now = now();
            foreach ($rows as $row) {
                $currentOwner = $this->stringValue($row->execution_owner ?? '');
                $leaseUntil = $row->execution_lease_until ?? null;
                if ($currentOwner !== ''
                    && $currentOwner !== $owner
                    && $leaseUntil !== null
                    && Carbon::parse($leaseUntil)->greaterThan($now)) {
                    return null;
                }
            }
            foreach ($rows as $row) {
                if (! in_array((string) $row->status, ['ready', 'partial', 'failed', 'submitted_unverified'], true)) {
                    return null;
                }
            }

            if ($this->hasOverlappingProductDeleteHistory($productRows, $rows)) {
                $this->persistClaimBlock(
                    $rows,
                    'overlapping_tiktok_delete_history',
                    'retry_original_run_or_manual_reconciliation',
                    $now,
                );

                return null;
            }
            if ($this->hasCrossRunShopeeTargetCollision($allRows, $rows, $now)) {
                $this->persistClaimBlock(
                    $rows,
                    'cross_run_shopee_target_collision',
                    'resolve_conflicting_cleanup_run_or_manual_reconciliation',
                    $now,
                );

                return null;
            }
            foreach ($productRows as $row) {
                if (in_array((int) $row->id, $ids, true)) {
                    continue;
                }

                $currentOwner = $this->stringValue($row->execution_owner ?? '');
                $leaseUntil = $row->execution_lease_until ?? null;
                if ($currentOwner !== ''
                    && $currentOwner !== $owner
                    && $leaseUntil !== null
                    && Carbon::parse($leaseUntil)->greaterThan($now)) {
                    return null;
                }
            }

            $expiredOwnerIds = $productRows
                ->filter(fn (object $row): bool => $this->stringValue($row->execution_owner ?? '') !== ''
                    && $this->stringValue($row->execution_owner ?? '') !== $owner)
                ->pluck('id')
                ->all();
            if ($expiredOwnerIds !== []) {
                DB::table('tiktok_reconciliation_run_items')->whereIn('id', $expiredOwnerIds)->update([
                    'execution_owner' => null,
                    'execution_lease_until' => null,
                    'updated_at' => $now,
                ]);
            }

            $sharedEvidence = $this->matchingProductDeleteEvidence($productRows, $items);
            foreach ($rows as $row) {
                DB::table('tiktok_reconciliation_run_items')->where('id', $row->id)->update([
                    'result' => $this->encodeJson($this->mergeAuditResult(
                        $this->decodeJson($row->result) ?? [],
                        $sharedEvidence,
                    )),
                    'execution_owner' => $owner,
                    'execution_lease_until' => $now->copy()->addSeconds(self::EXECUTION_LEASE_SECONDS),
                    'execution_attempts' => DB::raw('execution_attempts + 1'),
                    'updated_at' => $now,
                ]);
            }

            return DB::table('tiktok_reconciliation_run_items')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => $this->hydrateExecutionItem($row));
        });
    }

    private function hasCrossRunShopeeTargetCollision(Collection $allRows, Collection $rows, Carbon $now): bool
    {
        $currentRunId = $this->stringValue($rows->first()->run_id ?? '');
        $currentProductId = $this->stringValue(($this->decodeJson($rows->first()->payload) ?? [])['tiktok_product_id'] ?? '');
        $currentOperationKeys = $this->operationIdentityKeys($rows);
        $currentTargets = $rows->map(function (object $row): array {
            $payload = $this->decodeJson($row->payload) ?? [];

            return [
                'item_id' => $this->stringValue($payload['shopee_item_id'] ?? ''),
                'target_sku' => $this->normalizedSku($payload['target_sku'] ?? ''),
            ];
        });

        return $allRows->contains(function (object $row) use (
            $allRows,
            $currentOperationKeys,
            $currentProductId,
            $currentRunId,
            $currentTargets,
            $now,
        ): bool {
            if ($this->stringValue($row->run_id ?? '') === $currentRunId
                || ! in_array((string) $row->status, ['ready', 'partial', 'failed', 'submitted_unverified'], true)
                || $this->isPassiveTerminalConflictRow($row, $now)) {
                return false;
            }

            $payload = $this->decodeJson($row->payload) ?? [];
            $itemId = $this->stringValue($payload['shopee_item_id'] ?? '');
            $targetSku = $this->normalizedSku($payload['target_sku'] ?? '');
            if ($itemId === '' || $targetSku === '') {
                return false;
            }

            $plannedKeyMatches = $currentTargets->contains(
                fn (array $target): bool => $target['item_id'] === $itemId
                    && $target['target_sku'] === $targetSku,
            );
            if (! $plannedKeyMatches) {
                return false;
            }

            $otherRunId = $this->stringValue($row->run_id ?? '');
            $otherProductId = $this->stringValue($payload['tiktok_product_id'] ?? '');
            if ($otherProductId !== $currentProductId) {
                return true;
            }

            $otherOperationRows = $this->cleanupOperationRows($allRows->filter(function (object $candidate) use (
                $otherProductId,
                $otherRunId,
            ): bool {
                $candidatePayload = $this->decodeJson($candidate->payload) ?? [];

                return $this->stringValue($candidate->run_id ?? '') === $otherRunId
                    && $this->stringValue($candidatePayload['tiktok_product_id'] ?? '') === $otherProductId;
            }));

            if ($this->operationIdentityKeys($otherOperationRows) !== $currentOperationKeys) {
                return true;
            }

            return ! $otherOperationRows->contains(
                fn (object $candidate): bool => $this->resultHasIrreversibleDeleteEvidence(
                    $this->decodeJson($candidate->result) ?? [],
                ),
            );
        });
    }

    private function isPassiveTerminalConflictRow(object $row, Carbon $now): bool
    {
        if ((string) $row->status !== 'failed'
            || $this->resultHasIrreversibleDeleteEvidence($this->decodeJson($row->result) ?? [])) {
            return false;
        }

        $owner = $this->stringValue($row->execution_owner ?? '');
        $leaseUntil = $row->execution_lease_until ?? null;

        return $owner === ''
            || $leaseUntil === null
            || ! Carbon::parse($leaseUntil)->greaterThan($now);
    }

    private function hasOverlappingProductDeleteHistory(Collection $productRows, Collection $rows): bool
    {
        $currentRunId = $this->stringValue($rows->first()->run_id ?? '');
        $currentTargetIds = $this->runTargetIds($rows);

        foreach ($productRows->groupBy('run_id') as $runId => $runRows) {
            if ($this->stringValue($runId) === $currentRunId) {
                continue;
            }

            $operationRows = $this->cleanupOperationRows($runRows);
            $priorTargetIds = $this->runTargetIds($operationRows);
            if ($priorTargetIds === []
                || $priorTargetIds === $currentTargetIds
                || array_intersect($priorTargetIds, $currentTargetIds) === []) {
                continue;
            }

            if ($operationRows->contains(
                fn (object $row): bool => $this->resultHasIrreversibleDeleteEvidence(
                    $this->decodeJson($row->result) ?? [],
                ),
            )) {
                return true;
            }
        }

        return false;
    }

    private function persistClaimBlock(
        Collection $rows,
        string $reason,
        string $resolution,
        Carbon $now,
    ): void
    {
        $items = $rows->map(fn (object $row): array => $this->hydrateExecutionItem($row));
        $status = $this->itemsHaveIrreversibleDeleteEvidence($items) ? 'partial' : 'failed';
        foreach ($rows as $row) {
            DB::table('tiktok_reconciliation_run_items')->where('id', $row->id)->update([
                'status' => $status,
                'block_reason' => $reason,
                'result' => $this->encodeJson($this->mergeAuditResult(
                    $this->decodeJson($row->result) ?? [],
                    ['precondition' => $reason, 'resolution' => $resolution],
                )),
                'execution_owner' => null,
                'execution_lease_until' => null,
                'updated_at' => $now,
            ]);
        }
    }

    private function cleanupOperationRows(Collection $rows): Collection
    {
        return $rows->filter(
            fn (object $row): bool => in_array(
                (string) $row->status,
                ['ready', 'partial', 'failed', 'submitted_unverified', 'updated'],
                true,
            ),
        );
    }

    private function runTargetIds(Collection $rows): array
    {
        return $rows
            ->map(fn (object $row): array => $this->decodeJson($row->payload) ?? [])
            ->pluck('tiktok_sku_id')
            ->map(fn (mixed $id): string => $this->stringValue($id))
            ->filter()
            ->uniqueStrict()
            ->sort()
            ->values()
            ->all();
    }

    private function operationIdentityKeys(Collection $rows): array
    {
        return $rows
            ->map(function (object $row): string {
                $payload = $this->decodeJson($row->payload) ?? [];

                return implode('|', [
                    $this->stringValue($payload['shopee_item_id'] ?? ''),
                    $this->stringValue($payload['shopee_model_id'] ?? ''),
                    $this->stringValue($payload['tiktok_product_id'] ?? ''),
                    $this->stringValue($payload['tiktok_sku_id'] ?? ''),
                    $this->normalizedSku($payload['target_sku'] ?? ''),
                ]);
            })
            ->filter()
            ->uniqueStrict()
            ->sort()
            ->values()
            ->all();
    }

    private function resultHasIrreversibleDeleteEvidence(array $result): bool
    {
        return ($result['tiktok_delete_attempted'] ?? false) === true
            || ($result['tiktok_verified'] ?? false) === true
            || is_array($result['tiktok_delete'] ?? null);
    }

    private function matchingProductDeleteEvidence(Collection $productRows, Collection $items): array
    {
        $targetIds = $items
            ->pluck('tiktok_sku_id')
            ->map(fn (mixed $id): string => $this->stringValue($id))
            ->filter()
            ->uniqueStrict()
            ->sort()
            ->values()
            ->all();
        $evidence = [];

        foreach ($productRows->groupBy('run_id') as $runRows) {
            $operationRows = $this->cleanupOperationRows($runRows);
            $runTargetIds = $this->runTargetIds($operationRows);
            if ($runTargetIds !== $targetIds) {
                continue;
            }

            foreach ($operationRows as $row) {
                $result = $this->decodeJson($row->result) ?? [];
                $incoming = [];
                if (($result['tiktok_delete_attempted'] ?? false) === true) {
                    $incoming['tiktok_delete_attempted'] = true;
                }
                if (is_array($result['expected_non_target_sku_ids'] ?? null)
                    && $result['expected_non_target_sku_ids'] !== []) {
                    $incoming['expected_non_target_sku_ids'] = $result['expected_non_target_sku_ids'];
                }
                if (is_array($result['tiktok_delete'] ?? null)) {
                    $incoming['tiktok_delete'] = $result['tiktok_delete'];
                }
                if (($result['tiktok_verified'] ?? false) === true) {
                    $incoming['tiktok_verified'] = true;
                }
                $evidence = $this->mergeAuditResult($evidence, $incoming);
            }
        }

        return $evidence;
    }

    private function marketplaceCall(Collection $items, string $owner, callable $call): array
    {
        $this->renewProductLease($items, $owner);

        return $call();
    }

    private function renewProductLease(Collection $items, string $owner): void
    {
        DB::transaction(function () use ($items, $owner): void {
            $rows = $this->lockedItemRows($items, $owner);
            DB::table('tiktok_reconciliation_run_items')
                ->whereIn('id', $rows->pluck('id')->all())
                ->where('execution_owner', $owner)
                ->update([
                    'execution_lease_until' => now()->addSeconds(self::EXECUTION_LEASE_SECONDS),
                    'updated_at' => now(),
                ]);
        });
    }

    private function releaseProductLease(Collection $items, string $owner): void
    {
        DB::transaction(function () use ($items, $owner): void {
            $ids = $items->pluck('_row_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $ownedIds = DB::table('tiktok_reconciliation_run_items')
                ->whereIn('id', $ids)
                ->where('execution_owner', $owner)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();
            if ($ownedIds === []) {
                return;
            }

            DB::table('tiktok_reconciliation_run_items')
                ->whereIn('id', $ownedIds)
                ->where('execution_owner', $owner)
                ->update([
                    'execution_owner' => null,
                    'execution_lease_until' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    private function executionItemIsOwned(array $item, string $owner): bool
    {
        $row = DB::table('tiktok_reconciliation_run_items')
            ->where('id', (int) ($item['_row_id'] ?? 0))
            ->lockForUpdate()
            ->first();

        return $row && (string) ($row->execution_owner ?? '') === $owner;
    }

    private function persistUnexpectedProductExceptionIfOwned(Collection $items, string $owner): void
    {
        DB::transaction(function () use ($items, $owner): void {
            try {
                $rows = $this->lockedItemRows($items, $owner);
            } catch (RuntimeException) {
                return;
            }

            $freshItems = $rows->map(fn (object $row): array => $this->hydrateExecutionItem($row));
            $status = $this->itemsHaveIrreversibleDeleteEvidence($freshItems) ? 'partial' : 'failed';
            foreach ($rows as $row) {
                $result = $this->mergeAuditResult($this->decodeJson($row->result) ?? [], [
                    'execution_error' => ['type' => 'unexpected_product_exception'],
                ]);
                DB::table('tiktok_reconciliation_run_items')->where('id', $row->id)->update([
                    'status' => $status,
                    'block_reason' => 'product_execution_exception',
                    'result' => $this->encodeJson($result),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function persistProductFailure(
        Collection $items,
        string $status,
        string $reason,
        array $result,
        string $owner,
    ): void
    {
        $this->persistProductOutcome($items, $status, $reason, $result, $owner);
    }

    private function persistDeleteIntent(
        Collection $items,
        array $expectedNonTargetIds,
        array $tiktokBeforeResult,
        string $owner,
    ): void
    {
        $this->persistProductEvidence($items, [
            'tiktok_delete_attempted' => true,
            'expected_non_target_sku_ids' => $expectedNonTargetIds,
            'tiktok_before' => $tiktokBeforeResult,
        ], $owner);
    }

    private function persistProductEvidence(Collection $items, array $result, string $owner): void
    {
        DB::transaction(function () use ($items, $result, $owner): void {
            $rows = $this->lockedItemRows($items, $owner);
            foreach ($rows as $row) {
                $merged = $this->mergeAuditResult($this->decodeJson($row->result) ?? [], $result);
                DB::table('tiktok_reconciliation_run_items')->where('id', $row->id)->update([
                    'result' => $this->encodeJson($merged),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function persistItemOutcome(
        array $item,
        string $status,
        ?string $reason,
        array $result,
        string $owner,
    ): void
    {
        DB::transaction(function () use ($item, $status, $reason, $result, $owner): void {
            $row = DB::table('tiktok_reconciliation_run_items')
                ->where('id', (int) $item['_row_id'])
                ->lockForUpdate()
                ->first();
            if (! $row || (string) ($row->execution_owner ?? '') !== $owner) {
                throw new RuntimeException('Cleanup execution lease was lost.');
            }

            DB::table('tiktok_reconciliation_run_items')->where('id', $row->id)->update([
                'status' => $status,
                'block_reason' => $reason,
                'result' => $this->encodeJson($this->mergeAuditResult($this->decodeJson($row->result) ?? [], $result)),
                'updated_at' => now(),
            ]);
        });
    }

    private function persistProductOutcome(
        Collection $items,
        string $status,
        ?string $reason,
        array $result,
        string $owner,
    ): void
    {
        DB::transaction(function () use ($items, $status, $reason, $result, $owner): void {
            $rows = $this->lockedItemRows($items, $owner);
            if ($status === 'failed') {
                $freshItems = $rows->map(fn (object $row): array => $this->hydrateExecutionItem($row));
                if ($this->itemsHaveIrreversibleDeleteEvidence($freshItems)) {
                    $status = 'partial';
                }
            }
            foreach ($rows as $row) {
                DB::table('tiktok_reconciliation_run_items')->where('id', $row->id)->update([
                    'status' => $status,
                    'block_reason' => $reason,
                    'result' => $this->encodeJson($this->mergeAuditResult($this->decodeJson($row->result) ?? [], $result)),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function lockedItemRows(Collection $items, ?string $owner = null): Collection
    {
        $ids = $items->pluck('_row_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        $rows = DB::table('tiktok_reconciliation_run_items')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($rows->count() !== count($ids)
            || ($owner !== null && $rows->contains(
                fn (object $row): bool => (string) ($row->execution_owner ?? '') !== $owner,
            ))) {
            throw new RuntimeException('Cleanup execution lease was lost.');
        }

        return $rows;
    }

    private function mergeAuditResult(array $existing, array $incoming): array
    {
        $existing = $this->redact($existing);
        $merged = array_replace_recursive($existing, $this->redact($incoming));

        if (($existing['tiktok_delete_attempted'] ?? false) === true) {
            $merged['tiktok_delete_attempted'] = true;
        }
        if (is_array($existing['expected_non_target_sku_ids'] ?? null)
            && $existing['expected_non_target_sku_ids'] !== []) {
            $merged['expected_non_target_sku_ids'] = $existing['expected_non_target_sku_ids'];
        }
        if (is_array($existing['tiktok_delete'] ?? null)) {
            $merged['tiktok_delete'] = $existing['tiktok_delete'];
        }
        if (($existing['tiktok_verified'] ?? false) === true) {
            $merged['tiktok_verified'] = true;
        }

        return $merged;
    }

    private function updateRunStatus(string $runId): void
    {
        $statuses = DB::table('tiktok_reconciliation_run_items')
            ->where('run_id', $runId)
            ->where('action_type', self::ACTION_TYPE)
            ->pluck('status');
        $updated = $statuses->filter(fn (mixed $status): bool => $status === 'updated')->count();
        $failed = $statuses->filter(fn (mixed $status): bool => $status === 'failed')->count();
        $partial = $statuses->contains(fn (mixed $status): bool => in_array($status, ['partial', 'submitted_unverified'], true));
        $pending = $statuses->contains(fn (mixed $status): bool => $status === 'ready');

        if ($pending) {
            $status = ($partial || $failed > 0 || $updated > 0) ? 'partial' : 'claimed';
        } elseif ($partial || ($failed > 0 && $updated > 0)) {
            $status = 'partial';
        } elseif ($failed > 0) {
            $status = 'failed';
        } else {
            $status = 'completed';
        }

        DB::table('tiktok_reconciliation_runs')->where('id', $runId)->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
            'updated_at' => now(),
        ]);
    }

    private function persistedExpectedNonTargetIds(Collection $items): array
    {
        return $items
            ->flatMap(fn (array $item): array => is_array($item['_persisted_result']['expected_non_target_sku_ids'] ?? null)
                ? $item['_persisted_result']['expected_non_target_sku_ids']
                : [])
            ->map(fn (mixed $id): string => is_string($id) ? trim($id) : '')
            ->filter()
            ->uniqueStrict()
            ->sort()
            ->values()
            ->all();
    }

    private function persistedDeleteWasAttempted(Collection $items): bool
    {
        return $items->isNotEmpty() && $items->every(function (array $item): bool {
            $result = $item['_persisted_result'] ?? [];

            return ($result['tiktok_delete_attempted'] ?? false) === true
                || ($result['tiktok_verified'] ?? false) === true
                || data_get($result, 'tiktok_delete.ok') === true;
        });
    }

    private function persistedTiktokDeleteResult(Collection $items): ?array
    {
        foreach ($items as $item) {
            $result = data_get($item, '_persisted_result.tiktok_delete');
            if (is_array($result)) {
                return $result;
            }
        }

        return null;
    }

    private function productSkus(array $product): array
    {
        foreach (['skus', 'sku_list', 'sku_info_list', 'sku_infos', 'skus_info', 'variants', 'model_list', 'product_skus'] as $key) {
            $candidate = data_get($product, $key);
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function skuIds(array $skus): array
    {
        return collect($skus)
            ->map(function (mixed $sku): string {
                $sku = $this->arrayValue($sku);
                $id = $sku['id'] ?? $sku['sku_id'] ?? null;

                return is_string($id) ? trim($id) : '';
            })
            ->filter()
            ->uniqueStrict()
            ->values()
            ->all();
    }

    private function findTiktokSku(array $skus, string $skuId): ?array
    {
        foreach ($skus as $sku) {
            $sku = $this->arrayValue($sku);
            $candidateId = $sku['id'] ?? $sku['sku_id'] ?? null;
            if (is_string($candidateId) && trim($candidateId) === $skuId) {
                return $sku;
            }
        }

        return null;
    }

    private function findShopeeModel(array $models, string $modelId): ?array
    {
        foreach ($models as $model) {
            $model = $this->arrayValue($model);
            if ($this->stringValue($model['model_id'] ?? '') === $modelId) {
                return $model;
            }
        }

        return null;
    }

    private function shopeeModelName(array $model): string
    {
        return $this->stringValue($model['model_name'] ?? $model['name'] ?? '');
    }

    private function tiktokSellerSku(array $sku): string
    {
        foreach (['seller_sku', 'sku_code', 'sku_no', 'sellerSku'] as $key) {
            $value = $sku[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function buildPreview(Collection $mappingOnlyGroups): array
    {
        $itemsByKey = [];
        foreach ($mappingOnlyGroups as $group) {
            $group = $this->arrayValue($group);
            $productId = $this->stringValue($group['tiktok_product_id'] ?? '');
            foreach (collect($group['mapping_only_variants'] ?? []) as $variant) {
                $variant = $this->arrayValue($variant);
                $item = $this->buildItem($variant, $productId);
                $itemsByKey[$item['item_key']] = $item;
            }
        }

        ksort($itemsByKey, SORT_STRING);
        $items = array_values($itemsByKey);
        $this->blockPlannedTargetCollisions($items);
        $this->blockProductsWithoutSurvivors($items);

        $revisionItems = array_map(
            fn (array $item): array => [
                'item_key' => $item['item_key'],
                'status' => $item['status'],
                'block_reason' => $item['block_reason'],
                'source_fingerprint' => $item['source_fingerprint'],
                'target_sku' => $item['target_sku'],
            ],
            $items,
        );

        return [
            'revision' => hash('sha256', $this->canonicalJson($revisionItems)),
            'summary' => [
                'products' => count(array_unique(array_filter(array_column($items, 'tiktok_product_id')))),
                'conflicts' => count($items),
                'eligible' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'ready')),
                'unchanged' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'unchanged')),
                'blocked' => count(array_filter($items, fn (array $item): bool => $item['status'] === 'blocked')),
            ],
            'items' => $items,
        ];
    }

    private function buildItem(array $variant, string $groupProductId): array
    {
        $itemId = $this->stringValue($variant['shopee_item_id'] ?? '');
        $modelId = $this->stringValue($variant['shopee_model_id'] ?? '');
        $productId = $this->stringValue($variant['tiktok_product_id'] ?? $groupProductId);
        $tiktokSkuId = $this->stringValue($variant['tiktok_sku_id'] ?? '');
        $itemKey = "sku-cleanup:{$itemId}:{$modelId}:{$productId}:{$tiktokSkuId}";

        $model = $itemId === '' || $modelId === ''
            ? null
            : DB::table('shopee_product_model')->where('item_id', $itemId)->where('model_id', $modelId)->first();
        $tiktokSku = $productId === '' || $tiktokSkuId === ''
            ? null
            : DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->where('sku_id', $tiktokSkuId)
                ->whereRaw('COALESCE(is_active, true) = true')
                ->first();

        $currentName = $this->stringValue($model->name ?? '');
        $oldSku = $this->stringValue($model->model_sku ?? '');
        $tiktokName = $this->stringValue($tiktokSku->sku_name ?? '');
        $tiktokSellerSku = $this->stringValue($tiktokSku->seller_sku ?? '');
        $targetSku = '';
        $blockReason = null;

        if ($itemId === '' || $modelId === '' || $productId === '' || $tiktokSkuId === '' || ! $model || ! $tiktokSku) {
            $blockReason = 'incomplete_identity';
        } else {
            try {
                $targetSku = $this->sellerSkuTemplate->build($itemId, $currentName);
            } catch (\InvalidArgumentException) {
                $blockReason = 'invalid_target_sku';
            }
        }
        if ($blockReason === null && ($oldSku === '' || $tiktokSellerSku === '')) {
            $blockReason = 'incomplete_identity';
        }

        $status = 'ready';
        if ($blockReason !== null) {
            $status = 'blocked';
        } elseif ($this->normalizedSku($oldSku) === $this->normalizedSku($targetSku)) {
            $status = 'unchanged';
        } elseif ($this->normalizedSku($oldSku) !== $this->normalizedSku($tiktokSellerSku)) {
            $status = 'blocked';
            $blockReason = 'source_sku_mismatch';
        } elseif ($this->normalizedName($currentName) === $this->normalizedName($tiktokName)) {
            $status = 'blocked';
            $blockReason = 'variant_names_match';
        } elseif ($this->hasShopeeTargetCollision($itemId, $modelId, $targetSku)) {
            $status = 'blocked';
            $blockReason = 'shopee_target_collision';
        } elseif ($this->hasTiktokTargetCollision($productId, $tiktokSkuId, $targetSku)) {
            $status = 'blocked';
            $blockReason = 'tiktok_target_collision';
        }

        $stockMasterIds = $this->stockMasterIds($itemId, $modelId, $productId, $tiktokSkuId);
        $source = [
            'identity' => compact('itemId', 'modelId', 'productId', 'tiktokSkuId'),
            'shopee_model' => $model ? [
                'item_id' => $this->stringValue($model->item_id ?? ''),
                'model_id' => $this->stringValue($model->model_id ?? ''),
                'name' => $currentName,
                'model_sku' => $oldSku,
            ] : null,
            'tiktok_sku' => $tiktokSku ? [
                'product_id' => $this->stringValue($tiktokSku->product_id ?? ''),
                'sku_id' => $this->stringValue($tiktokSku->sku_id ?? ''),
                'seller_sku' => $tiktokSellerSku,
                'sku_name' => $tiktokName,
                'is_active' => (bool) ($tiktokSku->is_active ?? false),
            ] : null,
            'same_item_models' => $this->shopeeSourceRows($itemId),
            'same_product_active_skus' => $this->tiktokSourceRows($productId),
            'stock_master_ids' => $stockMasterIds,
            'target_sku' => $targetSku,
        ];

        return [
            'item_key' => $itemKey,
            'action_type' => self::ACTION_TYPE,
            'status' => $status,
            'source_fingerprint' => hash('sha256', $this->canonicalJson($source)),
            'stock_master_ids' => $stockMasterIds,
            'shopee_item_id' => $itemId,
            'shopee_model_id' => $modelId,
            'shopee_variant_name' => $currentName,
            'old_sku' => $oldSku,
            'target_sku' => $targetSku,
            'tiktok_product_id' => $productId,
            'tiktok_sku_id' => $tiktokSkuId,
            'tiktok_variant_name' => $tiktokName,
            'block_reason' => $blockReason,
        ];
    }

    private function blockProductsWithoutSurvivors(array &$items): void
    {
        $readyIdsByProduct = [];
        foreach ($items as $item) {
            if ($item['status'] === 'ready') {
                $readyIdsByProduct[$item['tiktok_product_id']][] = $item['tiktok_sku_id'];
            }
        }

        foreach ($readyIdsByProduct as $productId => $targetSkuIds) {
            $activeSkuIds = DB::table('tiktok_products')
                ->where('product_id', $productId)
                ->whereRaw('COALESCE(is_active, true) = true')
                ->pluck('sku_id')
                ->map(fn (mixed $skuId): string => $this->stringValue($skuId))
                ->filter()
                ->unique()
                ->values()
                ->all();
            if (count(array_diff($activeSkuIds, array_unique($targetSkuIds))) > 0) {
                continue;
            }

            foreach ($items as &$item) {
                if ($item['tiktok_product_id'] === $productId && $item['status'] === 'ready') {
                    $item['status'] = 'blocked';
                    $item['block_reason'] = 'last_tiktok_variant';
                }
            }
            unset($item);
        }
    }

    private function blockPlannedTargetCollisions(array &$items): void
    {
        $readyItems = collect($items)->filter(fn (array $item): bool => $item['status'] === 'ready');
        $collidingKeys = $this->plannedTargetCollisionItems($readyItems)
            ->pluck('item_key')
            ->flip();

        foreach ($items as &$item) {
            if ($collidingKeys->has($item['item_key'])) {
                $item['status'] = 'blocked';
                $item['block_reason'] = 'planned_shopee_target_collision';
            }
        }
        unset($item);
    }

    private function plannedTargetCollisionItems(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (array $item): string => $this->stringValue($item['shopee_item_id'] ?? '')
                .'|'.$this->normalizedSku($item['target_sku'] ?? ''))
            ->filter(function (Collection $group, string $key): bool {
                [$itemId, $targetSku] = array_pad(explode('|', $key, 2), 2, '');

                return $itemId !== '' && $targetSku !== '' && $group->pluck('item_key')->uniqueStrict()->count() > 1;
            })
            ->flatten(1)
            ->values();
    }

    private function itemsHaveIrreversibleDeleteEvidence(Collection $items): bool
    {
        return $items->contains(function (array $item): bool {
            $result = $item['_persisted_result'] ?? [];

            return ($result['tiktok_delete_attempted'] ?? false) === true
                || ($result['tiktok_verified'] ?? false) === true
                || is_array($result['tiktok_delete'] ?? null);
        });
    }

    private function hasShopeeTargetCollision(string $itemId, string $modelId, string $targetSku): bool
    {
        return DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->where('model_id', '!=', $modelId)
            ->get()
            ->contains(fn (object $row): bool => $this->normalizedSku($row->model_sku ?? '') === $this->normalizedSku($targetSku));
    }

    private function hasTiktokTargetCollision(string $productId, string $tiktokSkuId, string $targetSku): bool
    {
        return DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->where('sku_id', '!=', $tiktokSkuId)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->get()
            ->contains(fn (object $row): bool => $this->normalizedSku($row->seller_sku ?? '') === $this->normalizedSku($targetSku));
    }

    private function stockMasterIds(string $itemId, string $modelId, string $productId, string $tiktokSkuId): array
    {
        if ($itemId === '' || $modelId === '' || $productId === '' || $tiktokSkuId === '') {
            return [];
        }

        $mappingIds = DB::table('sku_mappings')
            ->where('shopee_item_id', $itemId)
            ->where('shopee_model_id', $modelId)
            ->where('tiktok_product_id', $productId)
            ->where('tiktok_sku_id', $tiktokSkuId)
            ->pluck('stock_master_id');
        $stockIds = DB::table('stock_master')
            ->where('shopee_product_id', $itemId)
            ->where('shopee_sku', $modelId)
            ->where('tiktok_product_id', $productId)
            ->where('tiktok_sku', $tiktokSkuId)
            ->pluck('id');

        return $mappingIds
            ->merge($stockIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function shopeeSourceRows(string $itemId): array
    {
        if ($itemId === '') {
            return [];
        }

        return DB::table('shopee_product_model')
            ->where('item_id', $itemId)
            ->get()
            ->map(fn (object $row): array => [
                'item_id' => $this->stringValue($row->item_id ?? ''),
                'model_id' => $this->stringValue($row->model_id ?? ''),
                'name' => $this->stringValue($row->name ?? ''),
                'model_sku' => $this->stringValue($row->model_sku ?? ''),
            ])
            ->all();
    }

    private function tiktokSourceRows(string $productId): array
    {
        if ($productId === '') {
            return [];
        }

        return DB::table('tiktok_products')
            ->where('product_id', $productId)
            ->whereRaw('COALESCE(is_active, true) = true')
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => $this->stringValue($row->product_id ?? ''),
                'sku_id' => $this->stringValue($row->sku_id ?? ''),
                'seller_sku' => $this->stringValue($row->seller_sku ?? ''),
                'sku_name' => $this->stringValue($row->sku_name ?? ''),
                'is_active' => (bool) ($row->is_active ?? false),
            ])
            ->all();
    }

    private function normalizedSku(mixed $value): string
    {
        return strtoupper($this->stringValue($value));
    }

    private function normalizedName(mixed $value): string
    {
        $normalized = strtolower($this->stringValue($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : (array) $value;
    }

    private function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                continue;
            }
            $redacted[$key] = $this->redact($item);
        }

        return $redacted;
    }

    private function canonicalJson(mixed $value): string
    {
        return $this->encodeJson($this->canonicalize($value));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (array_is_list($value)) {
            usort($value, fn (mixed $left, mixed $right): int => $this->canonicalJson($left) <=> $this->canonicalJson($right));

            return $value;
        }
        ksort($value, SORT_STRING);

        return $value;
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
