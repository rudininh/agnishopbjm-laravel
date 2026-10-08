<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only source ownership and SKU recommendations shared by both destinations. */
class MarketplaceSourceSkuRepairService
{
    private const SOURCE = 'shopee-agnishopbjm';
    private ?array $catalog = null;

    public function __construct(private AgniSkuRepairPlan $planner, private MarketplaceStockMirrorGateway $gateway) {}

    public function plan(string $account, string $productId, array $variants, bool $fresh = false): array
    {
        if (!in_array($account, ['shopee-gitacollectionbjm', 'tiktok-agnishopbjm'], true)) { throw new \RuntimeException('Akun tujuan perbaikan SKU tidak didukung.'); }
        $catalog = $this->catalog(); $candidates = []; $links = []; $invalidLink = false;
        foreach ($this->links($account, $productId) as $link) {
            $sourceId = (string) $link['product_id'];
            if (!isset($catalog['products'][$sourceId])) { $invalidLink = true; continue; }
            $candidates[$sourceId] = true;
            if ($link['target_variant_id'] !== '' && $link['variant_id'] !== '') { $links[$link['target_variant_id']][] = $link['variant_id']; }
        }
        foreach ($variants as $variant) {
            $sku = (string) ($variant['seller_sku'] ?? '');
            foreach ($catalog['sku_products'][$sku] ?? [] as $sourceId) { $candidates[$sourceId] = true; }
            if (preg_match('/^INT-([0-9]+)(?:-|$)/i', $sku, $m) && isset($catalog['products'][$m[1]])) { $candidates[$m[1]] = true; }
        }
        $source = !$invalidLink && count($candidates) === 1 ? $catalog['products'][(string) array_key_first($candidates)] : null;
        if ($fresh && $source) {
            try {
                $live = $this->gateway->product(self::SOURCE, $source['product_id']);
                if (!$live['active'] || !$live['complete'] || $live['shop_id'] !== $catalog['shop_id']) { throw new \RuntimeException(); }
                $source = $live;
            } catch (\Throwable) { throw new \RuntimeException('Data SKU Agni belum dapat dibaca. Periksa token dan sinkronkan Agni.'); }
        }
        $rows = $this->planner->build($variants, $source, $links);
        foreach ($rows as &$row) {
            if ($row['target'] !== '' && count($catalog['sku_owners'][$row['target']] ?? []) > 1) {
                $row['blocked'] = 'SKU Agni dipakai beberapa varian sumber. Perbaiki duplikat di Agni.';
            }
        }
        return $rows;
    }

    public function target(string $account, string $productId, array $variants, string $variantId, array $expected, bool $checkPreview = true): string
    {
        foreach ($this->plan($account, $productId, $variants, true) as $row) {
            if ($row['id'] !== $variantId) { continue; }
            if ($row['blocked'] !== '') { throw new \RuntimeException($row['blocked']); }
            if ($checkPreview && ($row['current'] !== (string) ($expected['expected_sku'] ?? '') || $row['name'] !== (string) ($expected['expected_name'] ?? ''))) {
                throw new \RuntimeException('Data varian tujuan berubah. Sync dan buka ulang preview.');
            }
            if ($row['target'] !== (string) ($expected['seller_sku'] ?? '')) { throw new \RuntimeException('SKU Agni berubah atau usulan bukan SKU Agni. Sync dan buka ulang preview.'); }
            return $row['target'];
        }
        throw new \RuntimeException('Varian tujuan tidak ditemukan. Sync dan buka ulang preview.');
    }

    private function catalog(): array
    {
        if ($this->catalog !== null) { return $this->catalog; }
        $result = ['shop_id' => '', 'products' => [], 'sku_products' => [], 'sku_owners' => []];
        if (!Schema::hasColumn('shopee_tokens', 'account_key') || !Schema::hasColumn('shopee_product_model', 'model_id') || !Schema::hasColumn('shopee_product_model', 'name')) { return $this->catalog = $result; }
        $shops = DB::table('shopee_tokens')->where('account_key', self::SOURCE)->whereRaw('COALESCE(is_active, true) = true')->pluck('shop_id')->unique();
        if ($shops->count() !== 1 || (int) $shops->first() <= 0) { return $this->catalog = $result; }
        $result['shop_id'] = (string) $shops->first();
        $query = DB::table('shopee_product as p')->join('shopee_product_model as m', 'p.item_id', '=', 'm.item_id')->where('p.shop_id', $result['shop_id']);
        if (Schema::hasColumn('shopee_product', 'is_active')) { $query->whereRaw('COALESCE(p.is_active, true) = true'); }
        foreach ($query->get(['p.item_id', 'm.model_id', 'm.name', 'm.model_sku']) as $row) {
            $pid = (string) $row->item_id; $vid = (string) $row->model_id; $sku = (string) ($row->model_sku ?? '');
            $result['products'][$pid]['product_id'] = $pid;
            $result['products'][$pid]['variants'][] = ['id' => $vid, 'name' => (string) $row->name, 'seller_sku' => $sku];
            if ($sku !== '' && $sku !== '-') { $result['sku_products'][$sku][$pid] = $pid; $result['sku_owners'][$sku][] = $pid.':'.$vid; }
        }
        return $this->catalog = $result;
    }

    private function links(string $account, string $productId): array
    {
        $links = [];
        if (Schema::hasColumn('marketplace_listings', 'remote_variant_id')) {
            $rows = DB::table('marketplace_listings as t')->join('marketplace_listings as s', 's.stock_master_id', '=', 't.stock_master_id')
                ->where('t.account_key', $account)->where('s.account_key', self::SOURCE)->where('t.remote_product_id', $productId)
                ->whereRaw('t.is_active = true AND s.is_active = true')->get(['s.remote_product_id', 's.remote_variant_id', 't.remote_variant_id as target_variant_id']);
            foreach ($rows as $row) { $links[] = ['product_id' => (string) $row->remote_product_id, 'variant_id' => (string) $row->remote_variant_id, 'target_variant_id' => (string) $row->target_variant_id]; }
        }
        if ($account === 'tiktok-agnishopbjm') {
            if (Schema::hasColumn('sku_mappings', 'shopee_model_id')) {
                foreach (DB::table('sku_mappings')->where('tiktok_product_id', $productId)->get() as $row) {
                    $links[] = ['product_id' => (string) $row->shopee_item_id, 'variant_id' => (string) $row->shopee_model_id, 'target_variant_id' => (string) $row->tiktok_sku_id];
                }
            }
            if (Schema::hasColumn('stock_master', 'tiktok_product_id') && Schema::hasColumn('stock_master', 'shopee_product_id')) {
                foreach (DB::table('stock_master')->where('tiktok_product_id', $productId)->whereNotNull('shopee_product_id')->get() as $row) {
                    $links[] = ['product_id' => (string) $row->shopee_product_id, 'variant_id' => (string) ($row->shopee_sku ?? ''), 'target_variant_id' => (string) ($row->tiktok_sku ?? '')];
                }
            }
        }
        $table = $account === 'tiktok-agnishopbjm' ? 'stock_hub_tiktok_product_runs' : 'stock_hub_gita_product_runs';
        if (Schema::hasColumn($table, 'remote_product_id')) {
            foreach (DB::table($table)->where('remote_product_id', $productId)->get(['source_product_id']) as $row) {
                $links[] = ['product_id' => (string) $row->source_product_id, 'variant_id' => '', 'target_variant_id' => ''];
            }
        }
        return $links;
    }
}
