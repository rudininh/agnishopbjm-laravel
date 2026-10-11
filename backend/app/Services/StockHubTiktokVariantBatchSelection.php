<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Local full-catalog selection and account readiness; never refreshes tokens or writes to a marketplace. */
class StockHubTiktokVariantBatchSelection
{
    public function __construct(private MarketplaceVariantCoverageService $coverage, private MarketplaceStockMirrorTransport $transport) {}

    public function assertReady(): void
    {
        // Called only during explicit steps. The transport proves legacy tokens
        // through authorized-shop GET and checks the account-scoped cached ID/cipher.
        // Catalog selection, start and status recovery remain local-only.
        $this->transport->context('shopee-agnishopbjm', false, 6);
        $this->transport->context('tiktok-agnishopbjm', false, 6);
    }

    public function forProducts(): array
    {
        $shops = DB::table('shopee_tokens')->where('account_key', 'shopee-agnishopbjm')->whereRaw('is_active = true')
            ->pluck('shop_id')->filter(fn ($id) => (int) $id > 0)->map(fn ($id) => (string) $id)->unique()->values();
        if ($shops->count() !== 1) { throw new \DomainException('Identitas sumber Agni belum pasti.'); }
        $query = DB::table('shopee_product')->where('shop_id', $shops[0]);
        if (Schema::hasColumn('shopee_product', 'is_active')) { $query->whereRaw('COALESCE(is_active, true) = true'); }
        $rows = $query->get()->all();
        usort($rows, fn ($a, $b) => strcmp((string) $a->item_id, (string) $b->item_id));
        $models = DB::table('shopee_product_model')->whereIn('item_id', array_column($rows, 'item_id'))->get()->groupBy('item_id');
        $products = array_map(fn ($p) => ['item_id' => (string) $p->item_id, 'shop_id' => (string) $p->shop_id,
            'models' => array_map(fn ($m) => (array) $m, ($models[(string) $p->item_id] ?? collect())->all())], $rows);
        $coverage = $this->coverage->forProducts($products);
        return array_map(fn ($p) => ['source_product_id' => (string) $p->item_id,
            'name' => (string) ($p->item_name ?? $p->name ?? $p->item_id),
            'coverage' => $coverage[(string) $p->item_id]['tiktok-agnishopbjm']], $rows);
    }
}
