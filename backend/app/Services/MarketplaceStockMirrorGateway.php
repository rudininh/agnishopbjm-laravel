<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketplaceStockMirrorGateway
{
    public const SOURCE = 'shopee-agnishopbjm';
    public const TARGETS = ['tiktok-agnishopbjm', 'shopee-gitacollectionbjm'];

    public function __construct(private MarketplaceStockMirrorTransport $transport, private MarketplaceApiService $api)
    {
    }

    private function account(string $key): array
    {
        if (! in_array($key, [self::SOURCE, ...self::TARGETS], true)) {
            throw new \RuntimeException('Akun tidak didukung.');
        }
        $ctx = $this->transport->context($key);
        if ('shopee-gitacollectionbjm' === $key && $ctx['shop_id'] === $this->transport->context(self::SOURCE)['shop_id']) {
            throw new \RuntimeException('Identitas toko sumber dan tujuan sama.');
        }

        return $ctx;
    }

    public function catalogPage(?string $cursor): array
    {
        return $this->page(self::SOURCE, $cursor);
    }

    private function page(string $key, ?string $cursor): array
    {
        $this->account($key);
        if (str_starts_with($key, 'shopee-')) {
            if (null !== $cursor && ! preg_match('/^[0-2]:[0-9]+$/D', $cursor)) {
                throw new \RuntimeException('Cursor katalog tidak valid.');
            }
            [$stage,$offset] = explode(':', $cursor ?? '0:0');
            $data = $this->transport->call($key, 'GET', '/api/v2/product/get_item_list', ['offset' => (int) $offset, 'page_size' => 50, 'item_status' => ['NORMAL', 'UNLIST', 'BANNED'][(int) $stage]]);
            $items = $data['item'] ?? null;
            // Shopee omits `item` for an explicitly empty status category.
            // A missing list without this evidence must still fail closed.
            if ($items === null && in_array($data['total_count'] ?? null, [0, '0'], true)
                && ($data['has_next_page'] ?? null) === false) {
                $items = [];
            }
            if (! is_array($items) || ! array_is_list($items) || ! is_bool($data['has_next_page'] ?? null)) {
                throw new \RuntimeException('Katalog tidak lengkap.');
            }
            if ($data['has_next_page']) {
                if (! isset($data['next_offset']) || ! ctype_digit((string) $data['next_offset']) || (int) $data['next_offset'] <= (int) $offset) {
                    throw new \RuntimeException('Paginasi tidak lengkap.');
                }
                $next = $stage.':'.$data['next_offset'];
            } else {
                $next = (int) $stage < 2 ? ((int) $stage + 1).':0' : null;
            }
            $ids = array_map(fn ($r) => (string) ($r['item_id'] ?? ''), $items);
        } else {
            $data = $this->transport->call($key, 'POST', '/product/202502/products/search', ['page_size' => 50, ...($cursor ? ['page_token' => $cursor] : [])], ['status' => 'ALL']);
            if (! is_array($data['products'] ?? null) || ! array_key_exists('next_page_token', $data)) {
                throw new \RuntimeException('Katalog tidak lengkap.');
            }
            $next = trim((string) $data['next_page_token']) ?: null;
            if (null !== $next && $next === $cursor) {
                throw new \RuntimeException('Paginasi berulang.');
            }
            $ids = array_map(fn ($r) => (string) ($r['id'] ?? ''), $data['products']);
        }
        foreach ($ids as $id) {
            if (! preg_match('/^[0-9]+$/D', $id)) {
                throw new \RuntimeException('Identitas katalog tidak lengkap.');
            }
        }

        return ['products' => array_values(array_unique($ids)), 'next_cursor' => $next, 'complete' => null === $next];
    }

    public function product(string $accountKey, string $productId): array
    {
        if (! preg_match('/^[0-9]+$/D', $productId)) {
            throw new \RuntimeException('ID produk tidak valid.');
        }
        $ctx = $this->account($accountKey);
        $variants = [];
        if (str_starts_with($accountKey, 'shopee-')) {
            $data = $this->transport->call($accountKey, 'GET', '/api/v2/product/get_item_base_info', ['item_id_list' => $productId]);
            $p = $data['item_list'][0] ?? null;
            if (! is_array($p) || (string) ($p['item_id'] ?? '') !== $productId) {
                throw new \RuntimeException('Produk tidak ditemukan di akun ini.');
            }
            $data = $this->transport->call($accountKey, 'GET', '/api/v2/product/get_model_list', ['item_id' => (int) $productId]);
            $rows = $data['model'] ?? $data['model_list'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new \RuntimeException('Varian tidak lengkap.');
            }
            // Non-variation products use item identity with model zero.
            if (! $rows && ($p['has_model'] ?? null) === false) {
                $rows = [['model_id' => 0, 'model_sku' => $p['item_sku'] ?? '', ...$p]];
            }
            foreach ($rows as $r) {
                $variants[] = ['id' => (string) ($r['model_id'] ?? ''), 'name' => (string) ($r['model_name'] ?? ''), 'seller_sku' => (string) ($r['model_sku'] ?? ''), 'stock' => $this->shopeeStock($r)];
            }
            $active = ($p['item_status'] ?? '') === 'NORMAL';
            $name = (string) ($p['item_name'] ?? '');
        } else {
            $data = $this->transport->call($accountKey, 'GET', '/product/202309/products/'.$productId);
            $p = $data['product'] ?? $data;
            if (! is_array($p) || (string) ($p['id'] ?? '') !== $productId || ! is_array($p['skus'] ?? null) || ! array_is_list($p['skus'])) {
                throw new \RuntimeException('Produk tidak lengkap.');
            }
            foreach ($p['skus'] as $r) {
                $variants[] = ['id' => (string) ($r['id'] ?? ''), 'name' => implode(' / ', array_column($r['sales_attributes'] ?? [], 'value_name')), 'seller_sku' => (string) ($r['seller_sku'] ?? ''), 'stock' => $this->tiktokStock($r, $ctx['config']['warehouse_id'] ?? '')];
            }
            $active = in_array($p['status'] ?? null, ['ACTIVATE', 'ACTIVE', 4], true);
            $name = (string) ($p['title'] ?? '');
        }
        if (isset($p['shop_id']) && (string) $p['shop_id'] !== $ctx['shop_id']) {
            throw new \RuntimeException('Produk berasal dari toko berbeda.');
        }
        $ids = array_column($variants, 'id');
        foreach ($ids as $id) {
            if (! preg_match('/^[0-9]+$/D', $id)) {
                throw new \RuntimeException('Identitas varian tidak lengkap.');
            }
        }
        if (count($ids) !== count(array_unique($ids))) {
            throw new \RuntimeException('Identitas varian ambigu.');
        }

        return ['product_id' => $productId, 'name' => $name, 'shop_id' => $ctx['shop_id'], 'complete' => count($variants) > 0, 'active' => $active, 'variants' => $variants];
    }

    private function integer(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^[0-9]+$/D', $value))) {
            return null;
        }
        if (false === filter_var($value, FILTER_VALIDATE_INT) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    private function shopeeStock(array $r): ?int
    {
        $v = $r['stock_info_v2']['summary_info'] ?? [];
        if (array_key_exists('total_available_stock', $v)) {
            return $this->integer($v['total_available_stock']);
        }
        if (array_key_exists('normal_stock', $r)) {
            return $this->integer($r['normal_stock']);
        }
        if (isset($r['stock_info']) && is_array($r['stock_info']) && 1 === count($r['stock_info']) && array_key_exists('normal_stock', $r['stock_info'][0])) {
            return $this->integer($r['stock_info'][0]['normal_stock']);
        }

        return null;
    }

    private function tiktokStock(array $r, string $warehouse): ?int
    {
        if ('' === $warehouse || ! is_array($r['inventory'] ?? null)) {
            return null;
        }
        $rows = array_values(array_filter($r['inventory'], fn ($v) => (string) ($v['warehouse_id'] ?? '') === $warehouse));

        return 1 === count($rows) ? $this->integer($rows[0]['quantity'] ?? null) : null;
    }

    private function links(string $sourceProduct, string $sourceVariant, string $targetKey): array
    {
        $links = [];
        if (Schema::hasTable('marketplace_listings')) {
            $rows = DB::table('marketplace_listings as s')->join('marketplace_listings as t', 's.stock_master_id', '=', 't.stock_master_id')->where('s.account_key', self::SOURCE)->where('s.remote_product_id', $sourceProduct)->where('s.remote_variant_id', $sourceVariant)->where('t.account_key', $targetKey)->get(['t.remote_product_id as product_id', 't.remote_variant_id as variant_id', 't.is_active', 's.is_active as source_active']);
            foreach ($rows as $r) {
                $links[] = ['product_id' => (string) $r->product_id, 'variant_id' => (string) $r->variant_id, 'active' => (bool) $r->is_active && (bool) $r->source_active];
            }
        }
        if ('tiktok-agnishopbjm' === $targetKey && Schema::hasTable('sku_mappings')) {
            foreach (DB::table('sku_mappings')->where('shopee_item_id', $sourceProduct)->where('shopee_model_id', $sourceVariant)->get() as $r) {
                if (null !== $r->tiktok_product_id && null !== $r->tiktok_sku_id) {
                    $links[] = ['product_id' => (string) $r->tiktok_product_id, 'variant_id' => (string) $r->tiktok_sku_id, 'active' => true];
                }
            }
        }

        return array_values(array_unique($links, SORT_REGULAR));
    }

    /** Local identities are candidates only: no cached quantity is read. */
    private function candidates(string $key, string $sku): array
    {
        $candidates = [];
        if ($sku === '') {
            return [];
        }
        if (Schema::hasTable('marketplace_listings')) {
            foreach (DB::table('marketplace_listings')->where('account_key', $key)->where('seller_sku', $sku)->get() as $row) {
                $candidates[] = ['product_id' => (string) $row->remote_product_id, 'variant_id' => (string) $row->remote_variant_id, 'active' => (bool) $row->is_active];
            }
        }
        if (str_starts_with($key, 'shopee-') && Schema::hasColumn('shopee_product_model', 'model_sku')) {
            $ctx = $this->account($key);
            $rows = DB::table('shopee_product_model as m')->join('shopee_product as p', 'p.item_id', '=', 'm.item_id')
                ->where('p.shop_id', $ctx['shop_id'])->where('m.model_sku', $sku)->get(['m.item_id', 'm.model_id']);
            foreach ($rows as $row) {
                $candidates[] = ['product_id' => (string) $row->item_id, 'variant_id' => (string) $row->model_id, 'active' => true];
            }
        }
        if ($key === 'tiktok-agnishopbjm' && Schema::hasColumn('tiktok_products', 'seller_sku')) {
            $query = DB::table('tiktok_products')->where('seller_sku', $sku);
            if (Schema::hasColumn('tiktok_products', 'account_key')) {
                $query->where('account_key', $key);
            }
            foreach ($query->get() as $row) {
                $candidates[] = ['product_id' => (string) $row->product_id, 'variant_id' => (string) $row->sku_id, 'active' => (bool) ($row->is_active ?? true)];
            }
        }
        $unique = [];
        foreach ($candidates as $candidate) {
            $identity = $candidate['product_id'].':'.$candidate['variant_id'];
            if (isset($unique[$identity])) {
                $candidate['active'] = $candidate['active'] && $unique[$identity]['active'];
            }
            $unique[$identity] = $candidate;
        }

        return array_values($unique);
    }

    private function sourceOwners(string $key, string $product, string $variant): array
    {
        $owners = [];
        if (Schema::hasTable('marketplace_listings')) {
            $rows = DB::table('marketplace_listings as t')->join('marketplace_listings as s', 's.stock_master_id', '=', 't.stock_master_id')
                ->where('t.account_key', $key)->where('t.remote_product_id', $product)->where('t.remote_variant_id', $variant)
                ->where('s.account_key', self::SOURCE)->get(['s.remote_product_id', 's.remote_variant_id']);
            foreach ($rows as $row) {
                $owners[] = [(string) $row->remote_product_id, (string) $row->remote_variant_id];
            }
        }
        if ($key === 'tiktok-agnishopbjm' && Schema::hasTable('sku_mappings')) {
            foreach (DB::table('sku_mappings')->where('tiktok_product_id', $product)->where('tiktok_sku_id', $variant)->get() as $row) {
                $owners[] = [(string) $row->shopee_item_id, (string) $row->shopee_model_id];
            }
        }

        return array_values(array_unique($owners, SORT_REGULAR));
    }

    public function target(string $sourceProductId, array $sourceVariant, string $targetAccountKey): array
    {
        $skip = ['status' => 'skipped', 'product_id' => null, 'variant_id' => null, 'reason' => 'Identitas tujuan tidak tersedia atau ambigu.'];
        if (! in_array($targetAccountKey, self::TARGETS, true)) {
            return $skip;
        }
        try {
            $this->account($targetAccountKey);
            $source = $this->product(self::SOURCE, $sourceProductId);
            $sourceRows = array_values(array_filter($source['variants'], fn ($v) => $v['id'] === (string) ($sourceVariant['id'] ?? '') && $v['seller_sku'] === (string) ($sourceVariant['seller_sku'] ?? '')));
            if (! $source['complete'] || ! $source['active'] || 1 !== count($sourceRows)) {
                return $skip;
            }
            $links = $this->links($sourceProductId, (string) $sourceVariant['id'], $targetAccountKey);
            if (count($links) > 1 || (1 === count($links) && ! $links[0]['active'])) {
                return $skip;
            }
            $candidates = [];
            if ($links) {
                $candidates = $links;
            } else {
                $sku = trim((string) ($sourceVariant['seller_sku'] ?? ''));
                if ('' === $sku) {
                    return $skip;
                }
                $sourceMatches = $this->candidates(self::SOURCE, $sku);
                if (1 !== count($sourceMatches) || ! $sourceMatches[0]['active'] || $sourceMatches[0]['product_id'] !== $sourceProductId || $sourceMatches[0]['variant_id'] !== (string) $sourceVariant['id']) {
                    return $skip;
                }
                $candidates = $this->candidates($targetAccountKey, $sku);
            }
            if (1 !== count($candidates) || ! $candidates[0]['active']) {
                return $skip;
            }
            $c = $candidates[0];
            $owners = $this->sourceOwners($targetAccountKey, $c['product_id'], $c['variant_id']);
            foreach ($owners as $owner) {
                if ($owner !== [$sourceProductId, (string) $sourceVariant['id']]) {
                    return $skip;
                }
            }
            if (Schema::hasTable('marketplace_listings') && DB::table('marketplace_listings')->where('account_key', $targetAccountKey)->where('remote_product_id', $c['product_id'])->where('remote_variant_id', $c['variant_id'])->whereRaw('is_active = false')->exists()) {
                return $skip;
            }
            $p = $this->product($targetAccountKey, $c['product_id']);
            $v = array_values(array_filter($p['variants'], fn ($v) => $v['id'] === $c['variant_id']));
            if (! $p['complete'] || ! $p['active'] || 1 !== count($v)) {
                return $skip;
            }

            if (! $links && $v[0]['seller_sku'] !== $sourceVariant['seller_sku']) {
                return $skip;
            }

            return ['status' => 'ready', 'product_id' => $c['product_id'], 'variant_id' => $c['variant_id'], 'reason' => null];
        } catch (\Throwable) {
            return $skip;
        }
    }

    public function sourceSelection(string $viewAccountKey, string $productId, ?string $variantId): array
    {
        $p = $this->product($viewAccountKey, $productId);
        if (! $p['complete'] || ! $p['active']) {
            throw new \RuntimeException('Produk tidak aktif atau tidak lengkap.');
        }
        $selected = array_values(array_filter($p['variants'], fn ($v) => null === $variantId || $v['id'] === $variantId));
        if (! $selected) {
            throw new \RuntimeException('Varian tidak ditemukan.');
        }
        if (self::SOURCE === $viewAccountKey) {
            return array_map(fn ($v) => ['source_product_id' => $productId, 'source_variant_id' => $v['id']], $selected);
        }
        $matches = [];
        foreach ($selected as $selectedVariant) {
            $owners = $this->sourceOwners($viewAccountKey, $productId, $selectedVariant['id']);
            if (! $owners) {
                foreach ($this->candidates(self::SOURCE, $selectedVariant['seller_sku']) as $candidate) {
                    if (! $candidate['active']) {
                        throw new \RuntimeException('Sumber pilihan tidak aktif.');
                    }
                    $owners[] = [$candidate['product_id'], $candidate['variant_id']];
                }
            }
            if (count($owners) !== 1) {
                throw new \RuntimeException('Sumber pilihan tidak tersedia atau ambigu.');
            }
            [$sourceId, $sourceVariantId] = $owners[0];
            $source = $this->product(self::SOURCE, $sourceId);
            $variants = array_values(array_filter($source['variants'], fn ($variant) => $variant['id'] === $sourceVariantId));
            if (! $source['active'] || ! $source['complete'] || count($variants) !== 1) {
                throw new \RuntimeException('Sumber pilihan tidak valid.');
            }
            $target = $this->target($sourceId, $variants[0], $viewAccountKey);
            if ($target['status'] !== 'ready' || $target['product_id'] !== $productId || $target['variant_id'] !== $selectedVariant['id']) {
                throw new \RuntimeException('Identitas pilihan tidak cocok.');
            }
            $matches[] = ['source_product_id' => $sourceId, 'source_variant_id' => $sourceVariantId,
                'view_product_id' => $productId, 'view_variant_id' => $selectedVariant['id']];
        }

        return array_values(array_unique($matches, SORT_REGULAR));
    }

    public function write(string $accountKey, string $productId, string $variantId, int $stock, string $idempotencyKey): array
    {
        try {
            if (! in_array($accountKey, self::TARGETS, true) || $stock < 0 || '' === trim($idempotencyKey)) {
                throw new \RuntimeException();
            }
            $p = $this->product($accountKey, $productId);
            if (! $p['complete'] || ! $p['active'] || ! in_array($variantId, array_column($p['variants'], 'id'), true)) {
                throw new \RuntimeException();
            }
            if ($accountKey === 'tiktok-agnishopbjm') {
                $ctx = $this->account($accountKey);
                $query = DB::table('tiktok_shops')->orderByDesc('updated_at');
                if (Schema::hasColumn('tiktok_shops', 'account_key')) {
                    $query->where('account_key', $accountKey);
                }
                $shop = $query->first();
                if (! $shop || (string) $shop->shop_id !== $ctx['shop_id'] || (string) ($shop->cipher ?? $shop->shop_cipher ?? '') !== $ctx['cipher']) {
                    throw new \RuntimeException('Identitas penulis TikTok tidak cocok.');
                }
            }
            $r = 'tiktok-agnishopbjm' === $accountKey ? $this->api->updateTiktokStockForAccount($accountKey, $productId, $variantId, $stock, null, $idempotencyKey) : $this->api->updateShopeeInventoryForAccount($accountKey, $productId, $variantId, $stock, $idempotencyKey);
            if (($r['status'] ?? '') !== 'success') {
                throw new \RuntimeException();
            }

            return ['status' => 'success', 'message' => 'Permintaan stok terkirim; perlu verifikasi.'];
        } catch (\Throwable) {
            return ['status' => 'error', 'message' => 'Pengiriman stok gagal atau identitas tujuan tidak valid.'];
        }
    }

    public function cacheVerified(string $accountKey, string $productId, string $variantId, int $stock): void
    {
        if (! in_array($accountKey, self::TARGETS, true) || $stock < 0) {
            throw new \RuntimeException('Cache tujuan tidak valid.');
        }
        $ctx = $this->account($accountKey);
        if ('shopee-gitacollectionbjm' === $accountKey && Schema::hasTable('shopee_product') && Schema::hasTable('shopee_product_model') && DB::table('shopee_product')->where('item_id', $productId)->where('shop_id', $ctx['shop_id'])->exists()) {
            DB::table('shopee_product_model')->where('item_id', $productId)->where('model_id', $variantId)->update(['stock' => $stock, 'updated_at' => now()]);
        }
        if ('tiktok-agnishopbjm' === $accountKey && Schema::hasTable('tiktok_products')) {
            $q = DB::table('tiktok_products')->where('product_id', $productId)->where('sku_id', $variantId);
            if (Schema::hasColumn('tiktok_products', 'account_key')) {
                $q->where('account_key', $accountKey);
            }
            $q->update(['stock_qty' => $stock, 'updated_at' => now()]);
        }
    }
}
