<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Product presence in saved catalogs only; never reads credentials or calls a marketplace. */
class MarketplaceProductPresenceService
{
    private const SOURCE = 'shopee-agnishopbjm';
    private const TIKTOK = 'tiktok-agnishopbjm';
    private const GITA = 'shopee-gitacollectionbjm';

    /** @param array<int, array{item_id:string, shop_id:mixed, skus:array}> $items */
    public function forProducts(array $items): array
    {
        if (! $items) {
            return [];
        }
        $sourceShop = $this->shopId(self::SOURCE);
        $catalogs = [self::TIKTOK => $this->tiktokCatalog(), self::GITA => $this->gitaCatalog()];
        $links = $this->linkedProducts(array_column($items, 'item_id'));
        $skuOwners = [];
        foreach ($items as $item) {
            if ($sourceShop === null || (string) $item['shop_id'] !== $sourceShop) {
                continue;
            }
            foreach ($item['skus'] as $sku) {
                $sku = $this->sku($sku);
                if ($sku !== '') {
                    $skuOwners[$sku][(string) $item['item_id']] = true;
                }
            }
        }

        $result = [];
        foreach ($items as $item) {
            $id = (string) $item['item_id'];
            foreach ($catalogs as $account => $catalog) {
                $state = 'unknown';
                if ($sourceShop !== null && (string) $item['shop_id'] === $sourceShop && $catalog['products']) {
                    $state = 'missing';
                    if (isset($catalog['source_ids'][$id]) || array_intersect_key($catalog['products'], $links[$id][$account] ?? [])) {
                        $state = 'present';
                    } else {
                        foreach ($item['skus'] as $sku) {
                            $sku = $this->sku($sku);
                            if ($sku === '' || ! isset($catalog['skus'][$sku])) {
                                continue;
                            }
                            // An INT SKU naming another source item contradicts this product's identity.
                            if (preg_match('/^INT-([0-9]+)-.+$/D', $sku, $owner) && $owner[1] !== $id) {
                                $state = 'unknown';
                                continue;
                            }
                            if (count($skuOwners[$sku] ?? []) === 1) {
                                $state = 'present';
                                break;
                            }
                            $state = 'unknown';
                        }
                    }
                }
                $result[$id][$account] = $state;
            }
        }

        return $result;
    }

    private function sku(mixed $sku): string
    {
        $sku = trim((string) $sku);

        return $sku === '-' ? '' : $sku;
    }

    private function shopId(string $account): ?string
    {
        if (! Schema::hasTable('shopee_tokens') || ! Schema::hasColumn('shopee_tokens', 'account_key')) {
            return null;
        }
        $shop = DB::table('shopee_tokens')->where('account_key', $account)->whereRaw('is_active = true')->orderByDesc('id')->value('shop_id');

        return (int) $shop > 0 ? (string) $shop : null;
    }

    private function tiktokCatalog(): array
    {
        if (! Schema::hasTable('tiktok_products') || ! Schema::hasColumn('tiktok_products', 'seller_sku')) {
            return $this->index([]);
        }
        $query = DB::table('tiktok_products');
        if (Schema::hasColumn('tiktok_products', 'account_key')) {
            $query->where('account_key', self::TIKTOK);
        }
        if (Schema::hasColumn('tiktok_products', 'is_active')) {
            $query->whereRaw('COALESCE(is_active, true) = true');
        }

        return $this->index($query->get(['product_id', 'seller_sku'])->all());
    }

    private function gitaCatalog(): array
    {
        $shop = $this->shopId(self::GITA);
        if ($shop === null || $shop === $this->shopId(self::SOURCE)
            || ! Schema::hasTable('shopee_product') || ! Schema::hasTable('shopee_product_model')
            || ! Schema::hasColumn('shopee_product_model', 'model_sku')) {
            return $this->index([]);
        }
        $query = DB::table('shopee_product as p')->leftJoin('shopee_product_model as m', 'p.item_id', '=', 'm.item_id')->where('p.shop_id', $shop);
        if (Schema::hasColumn('shopee_product', 'is_active')) {
            $query->whereRaw('COALESCE(p.is_active, true) = true');
        }

        return $this->index($query->get(['p.item_id as product_id', 'm.model_sku as seller_sku'])->all());
    }

    private function index(array $rows): array
    {
        $index = ['products' => [], 'skus' => [], 'source_ids' => []];
        foreach ($rows as $row) {
            $id = trim((string) $row->product_id);
            if ($id === '') {
                continue;
            }
            $index['products'][$id] = true;
            $sku = $this->sku($row->seller_sku);
            if ($sku !== '') {
                $index['skus'][$sku] = true;
                if (preg_match('/^INT-([0-9]+)-.+$/D', $sku, $match)) {
                    $index['source_ids'][$match[1]] = true;
                }
            }
        }

        return $index;
    }

    private function linkedProducts(array $sourceIds): array
    {
        $links = [];
        if (Schema::hasTable('marketplace_listings')) {
            $rows = DB::table('marketplace_listings as s')->join('marketplace_listings as t', 's.stock_master_id', '=', 't.stock_master_id')
                ->where('s.account_key', self::SOURCE)->whereIn('s.remote_product_id', $sourceIds)
                ->whereIn('t.account_key', [self::TIKTOK, self::GITA])->whereRaw('s.is_active = true AND t.is_active = true')
                ->get(['s.remote_product_id as source_id', 't.account_key', 't.remote_product_id as target_id']);
            foreach ($rows as $row) {
                $links[(string) $row->source_id][$row->account_key][(string) $row->target_id] = true;
            }
        }
        if (Schema::hasTable('sku_mappings')) {
            $rows = DB::table('sku_mappings')->whereIn('shopee_item_id', $sourceIds)->whereNotNull('tiktok_product_id')->get(['shopee_item_id', 'tiktok_product_id']);
            foreach ($rows as $row) {
                $links[(string) $row->shopee_item_id][self::TIKTOK][(string) $row->tiktok_product_id] = true;
            }
        }

        return $links;
    }
}
