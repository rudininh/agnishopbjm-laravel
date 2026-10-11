<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Saved-catalog indications only. No marketplace reads, token refreshes or writes. */
class MarketplaceVariantCoverageService
{
    private const SOURCE = 'shopee-agnishopbjm';
    private const TIKTOK = 'tiktok-agnishopbjm';
    private const GITA = 'shopee-gitacollectionbjm';

    public function forProducts(array $products): array
    {
        if ($products === []) {
            return [];
        }
        $sourceShop = $this->shopId(self::SOURCE);
        $sourceRows = $this->shopeeRows($sourceShop, true);
        $owners = [];
        foreach ($sourceRows ?? [] as $row) {
            $sku = $this->sku($row->seller_sku);
            if ($sku !== '') {
                $owners[$sku][(string) $row->product_id] = ($owners[$sku][(string) $row->product_id] ?? 0) + 1;
            }
        }
        // Merge caller rows without counting the same saved models twice. Ownership is
        // read across the entire source cache, even when the caller supplies one product.
        foreach ($products as $product) {
            if ($sourceShop === null || (string) ($product['shop_id'] ?? '') !== $sourceShop) {
                continue;
            }
            $counts = [];
            foreach ($product['models'] ?? [] as $model) {
                $sku = $this->sku($model['model_sku'] ?? null);
                if ($sku !== '') {
                    $counts[$sku] = ($counts[$sku] ?? 0) + 1;
                }
            }
            foreach ($counts as $sku => $count) {
                $id = (string) $product['item_id'];
                $owners[$sku][$id] = max($owners[$sku][$id] ?? 0, $count);
            }
        }
        $gitaShop = $this->shopId(self::GITA);
        $catalogs = [self::TIKTOK => $this->index($this->tiktokRows()), self::GITA => $this->index(
            $gitaShop !== $sourceShop ? $this->shopeeRows($gitaShop) : null
        )];
        $links = $this->linkedProducts();
        foreach ($links as $sourceId => $accounts) {
            foreach ($accounts as $account => $targets) {
                foreach ($targets as $target => $present) {
                    $catalogs[$account]['linked_sources'][$target][$sourceId] = true;
                }
            }
        }
        $result = [];
        foreach ($products as $product) {
            $id = (string) $product['item_id'];
            foreach ($catalogs as $account => $catalog) {
                $result[$id][$account] = $this->classify($product, $sourceShop, $sourceRows !== null, $owners, $catalog, $links[$id][$account] ?? []);
            }
        }

        return $result;
    }

    private function classify(array $product, ?string $sourceShop, bool $sourceAvailable, array $owners, array $catalog, array $links): array
    {
        $id = (string) $product['item_id'];
        $models = $product['models'] ?? [];
        $result = ['state' => 'unknown', 'target_product_id' => null, 'source_count' => count($models),
            'existing_count' => null, 'missing_count' => null, 'missing_model_ids' => [],
            'message' => 'Perlu diperiksa: data katalog atau identitas varian belum dapat dipastikan.'];
        if (! $sourceAvailable || $sourceShop === null || (string) ($product['shop_id'] ?? '') !== $sourceShop || ! $models || ! $catalog['products']) {
            return $result;
        }
        $skus = [];
        $modelIds = [];
        foreach ($models as $model) {
            $sku = $this->sku($model['model_sku'] ?? null);
            $modelId = trim((string) ($model['model_id'] ?? ''));
            if ($sku === '' || $modelId === '' || isset($modelIds[$modelId]) || array_sum($owners[$sku] ?? []) !== 1
                || $this->contradictoryPrefix($sku, $id)) {
                return $result;
            }
            $skus[$sku] = $modelId;
            $modelIds[$modelId] = true;
        }
        $candidates = $links + ($catalog['source_ids'][$id] ?? []);
        foreach ($skus as $sku => $modelId) {
            $matches = $catalog['skus'][$sku] ?? [];
            if (array_sum($matches) > 1) {
                return $result;
            }
            foreach ($matches as $target => $count) {
                $candidates[$target] = true;
            }
        }
        if (count($candidates) > 1) {
            return $result;
        }
        if (! $candidates) {
            return array_replace($result, ['state' => 'missing_product', 'existing_count' => 0,
                'message' => 'Produk tujuan belum ditemukan di katalog tersimpan.']);
        }
        $target = (string) array_key_first($candidates);
        if (! isset($catalog['products'][$target])) {
            return $result;
        }
        foreach ($catalog['linked_sources'][$target] ?? [] as $sourceId => $present) {
            if ((string) $sourceId !== $id) {
                return $result;
            }
        }
        foreach ($catalog['products'][$target] as $sku => $count) {
            if ($sku === '' || $count !== 1 || $this->contradictoryPrefix((string) $sku, $id)) {
                return $result;
            }
            // A target SKU owned by a different Agni product contradicts this container.
            foreach ($owners[$sku] ?? [] as $owner => $instances) {
                if ((string) $owner !== $id || $instances !== 1) {
                    return $result;
                }
            }
        }
        $missing = [];
        foreach ($skus as $sku => $modelId) {
            if (! isset($catalog['products'][$target][$sku])) {
                $missing[] = $modelId;
            }
        }

        return array_replace($result, ['state' => $missing ? 'missing_variants' : 'complete', 'target_product_id' => $target,
            'existing_count' => count($models) - count($missing), 'missing_count' => count($missing), 'missing_model_ids' => $missing,
            'message' => $missing ? 'Varian belum ditemukan di katalog tersimpan; perlu validasi terbaru sebelum ditambahkan.' : 'Semua SKU varian ditemukan di katalog tersimpan.']);
    }

    private function sku(mixed $sku): string
    {
        $sku = is_string($sku) || is_numeric($sku) ? trim((string) $sku) : '';

        return $sku === '-' ? '' : $sku;
    }

    private function contradictoryPrefix(string $sku, string $sourceId): bool
    {
        return preg_match('/^INT-([0-9]+)-.+$/D', $sku, $match) && $match[1] !== $sourceId;
    }

    private function hasColumns(string $table, array $columns): bool
    {
        return Schema::hasTable($table) && Schema::hasColumns($table, $columns);
    }

    private function shopId(string $account): ?string
    {
        if (! $this->hasColumns('shopee_tokens', ['account_key', 'shop_id', 'is_active'])) {
            return null;
        }
        $shops = DB::table('shopee_tokens')->where('account_key', $account)->whereRaw('is_active = true')->pluck('shop_id')
            ->filter(fn ($shop) => (int) $shop > 0)->map(fn ($shop) => (string) $shop)->unique()->values();

        return $shops->count() === 1 ? $shops[0] : null;
    }

    private function shopeeRows(?string $shop, bool $includeInactive = false): ?array
    {
        if ($shop === null || ! $this->hasColumns('shopee_product', ['item_id', 'shop_id'])
            || ! $this->hasColumns('shopee_product_model', ['item_id', 'model_sku'])) {
            return null;
        }
        $query = DB::table('shopee_product as p')->leftJoin('shopee_product_model as m', 'p.item_id', '=', 'm.item_id')->where('p.shop_id', $shop);
        if (! $includeInactive && Schema::hasColumn('shopee_product', 'is_active')) {
            $query->whereRaw('COALESCE(p.is_active, true) = true');
        }

        return $query->get(['p.item_id as product_id', 'm.model_sku as seller_sku'])->all();
    }

    private function tiktokRows(): ?array
    {
        if (! $this->hasColumns('tiktok_products', ['product_id', 'seller_sku'])) {
            return null;
        }
        $query = DB::table('tiktok_products');
        // The legacy table is exclusively TikTok Agni, matching the presence service.
        if (Schema::hasColumn('tiktok_products', 'account_key')) {
            $query->where('account_key', self::TIKTOK);
        }
        if (Schema::hasColumn('tiktok_products', 'is_active')) {
            $query->whereRaw('COALESCE(is_active, true) = true');
        }

        return $query->get(['product_id', 'seller_sku'])->all();
    }

    private function index(?array $rows): array
    {
        $index = ['products' => [], 'skus' => [], 'source_ids' => []];
        foreach ($rows ?? [] as $row) {
            $id = trim((string) $row->product_id);
            if ($id === '') {
                continue;
            }
            $sku = $this->sku($row->seller_sku);
            $index['products'][$id][$sku] = ($index['products'][$id][$sku] ?? 0) + 1;
            if ($sku !== '') {
                $index['skus'][$sku][$id] = ($index['skus'][$sku][$id] ?? 0) + 1;
                if (preg_match('/^INT-([0-9]+)-.+$/D', $sku, $match)) {
                    $index['source_ids'][$match[1]][$id] = true;
                }
            }
        }

        return $index;
    }

    private function linkedProducts(): array
    {
        $links = [];
        if ($this->hasColumns('marketplace_listings', ['stock_master_id', 'account_key', 'remote_product_id', 'is_active'])) {
            $rows = DB::table('marketplace_listings as s')->join('marketplace_listings as t', 's.stock_master_id', '=', 't.stock_master_id')
                ->where('s.account_key', self::SOURCE)
                ->whereIn('t.account_key', [self::TIKTOK, self::GITA])->whereRaw('s.is_active = true AND t.is_active = true')
                ->get(['s.remote_product_id as source_id', 't.account_key', 't.remote_product_id as target_id']);
            foreach ($rows as $row) {
                $links[(string) $row->source_id][$row->account_key][(string) $row->target_id] = true;
            }
        }
        if ($this->hasColumns('sku_mappings', ['shopee_item_id', 'tiktok_product_id'])) {
            $rows = DB::table('sku_mappings')->whereNotNull('shopee_item_id')->whereNotNull('tiktok_product_id')->get(['shopee_item_id', 'tiktok_product_id']);
            foreach ($rows as $row) {
                $links[(string) $row->shopee_item_id][self::TIKTOK][(string) $row->tiktok_product_id] = true;
            }
        }

        return $links;
    }
}
