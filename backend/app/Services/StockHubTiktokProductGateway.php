<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/** Validates complete fresh products before any creation request is allowed. */
class StockHubTiktokProductGateway
{
    public const SOURCE = 'shopee-agnishopbjm';
    public const TARGET = 'tiktok-agnishopbjm';

    public function __construct(private MarketplaceStockMirrorTransport $transport) {}

    private function call(string $account, string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->transport->call($account, $method, $path, $query, $body, 6, false);
    }

    public function source(string $id): array
    {
        $ctx = $this->transport->context(self::SOURCE);
        $data = $this->call(self::SOURCE, 'GET', '/api/v2/product/get_item_base_info', ['item_id_list' => $id]);
        $p = $data['item_list'][0] ?? [];
        $this->require(count($data['item_list'] ?? []) === 1 && (string) ($p['item_id'] ?? '') === $id, 'Produk sumber tidak ditemukan di Shopee Agni.');
        $this->require(! isset($p['shop_id']) || (string) $p['shop_id'] === $ctx['shop_id'], 'Produk sumber bukan milik Shopee Agni.');
        $this->require(($p['item_status'] ?? '') === 'NORMAL', 'Produk sumber harus aktif.');
        $this->require(is_string($p['item_name'] ?? null) && trim($p['item_name']) !== '' && is_string($p['description'] ?? null) && trim($p['description']) !== '', 'Judul atau deskripsi sumber belum lengkap.');
        $models = $this->call(self::SOURCE, 'GET', '/api/v2/product/get_model_list', ['item_id' => $id]);
        $rows = $models['model'] ?? $models['model_list'] ?? null;
        $this->require(is_array($rows) && array_is_list($rows), 'Daftar varian sumber tidak lengkap.');
        if ($rows === [] && ($p['has_model'] ?? null) === false) {
            $rows = [[...$p, 'model_id' => '0', 'model_sku' => $p['item_sku'] ?? '', 'model_name' => '', 'tier_index' => []]];
        }
        $this->require($rows !== [], 'Varian sumber tidak tersedia.');
        $tiers = $models['tier_variation'] ?? [];
        $this->require(is_array($tiers) && count($tiers) <= 2, 'Struktur variasi sumber tidak didukung.');
        $main = $p['image']['image_url_list'] ?? [];
        $this->require(is_array($main) && count($main) > 0 && count($main) <= 9, 'Gambar utama sumber harus lengkap (maksimal 9).');
        foreach ($main as $url) { $this->imageUrl($url); }
        $variants = [];
        foreach ($rows as $r) {
            $variantId = (string) ($r['model_id'] ?? '');
            $sku = trim((string) ($r['model_sku'] ?? ''));
            $this->require(ctype_digit($variantId) && $sku !== '' && mb_strlen($sku) <= 50 && ! preg_match('/[\x00-\x1f]/', $sku), 'SKU sumber kosong/tidak valid. Perbaiki melalui alat SKU.');
            if (preg_match('/^INT-([0-9]+)(?:-|$)/i', $sku, $m)) { $this->require($m[1] === $id, 'Kepemilikan prefix INT bertentangan. Perbaiki SKU sumber.'); }
            $priceRows = $r['price_info'] ?? [];
            $this->require(is_array($priceRows) && count($priceRows) === 1 && ($priceRows[0]['currency'] ?? '') === 'IDR', 'Harga jual IDR sumber belum lengkap.');
            $price = $this->decimal($priceRows[0]['current_price'] ?? null);
            $this->require($price !== null && (float) $price > 0, 'Harga jual sumber harus lebih dari nol.');
            $stock = $r['stock_info_v2']['summary_info']['total_available_stock'] ?? $r['normal_stock'] ?? null;
            if ($stock === null && count($r['stock_info'] ?? []) === 1) { $stock = $r['stock_info'][0]['normal_stock'] ?? null; }
            $this->require((is_int($stock) || is_string($stock)) && preg_match('/^[0-9]+$/D', (string) $stock) && filter_var($stock, FILTER_VALIDATE_INT) !== false, 'Stok tersedia sumber tidak lengkap.');
            $indices = $r['tier_index'] ?? [];
            $this->require(is_array($indices) && count($indices) === count($tiers), 'Cakupan variasi sumber tidak lengkap.');
            $attrs = [];
            foreach ($tiers as $i => $tier) {
                $option = $tier['option_list'][$indices[$i]] ?? [];
                $this->require(trim((string) ($tier['name'] ?? '')) !== '' && trim((string) ($option['option'] ?? '')) !== '', 'Nama variasi sumber tidak lengkap.');
                $attribute = ['name' => $tier['name'], 'value_name' => $option['option']];
                if (isset($option['image']['image_url']) && $option['image']['image_url'] !== '') { $this->imageUrl($option['image']['image_url']); $attribute['image_url'] = $option['image']['image_url']; }
                $attrs[] = $attribute;
            }
            $variants[] = ['id' => $variantId, 'seller_sku' => $sku, 'name' => (string) ($r['model_name'] ?? ''), 'price' => $price, 'stock' => (int) $stock, 'attributes' => $attrs];
        }
        $this->require(count(array_unique(array_column($variants, 'id'))) === count($variants) && count(array_unique(array_map('strtoupper', array_column($variants, 'seller_sku')))) === count($variants), 'SKU atau identitas varian sumber berulang. Perbaiki melalui alat SKU.');
        $combinations = array_map(fn ($v) => json_encode(array_column($v['attributes'], 'value_name')), $variants);
        $this->require(count($variants) === 1 || (count($tiers) > 0 && count(array_unique($combinations)) === count($variants)), 'Kombinasi variasi sumber ambigu.');
        return ['id' => $id, 'shop_id' => $ctx['shop_id'], 'title' => $p['item_name'], 'description' => $p['description'], 'main_images' => $main, 'variants' => $variants, 'weight' => $p['weight'] ?? null, 'dimension' => $p['dimension'] ?? []];
    }

    public function context(array $source, array $input): array
    {
        $ctx = $this->transport->context(self::TARGET, false, 6);
        $context = ['category_id' => (string) ($input['category_id'] ?? ''), 'warehouse_id' => (string) ($input['warehouse_id'] ?? $ctx['config']['warehouse_id'] ?? ''), 'package_weight' => ['value' => $this->decimal($input['package_weight']['value'] ?? $source['weight']) ?? '', 'unit' => 'KILOGRAM'], 'package_dimensions' => ['unit' => 'CENTIMETER']];
        $fields = [];
        foreach (['length','width','height'] as $key) {
            $value = $this->decimal($input['package_dimensions'][$key] ?? $source['dimension']['package_'.$key] ?? null);
            $context['package_dimensions'][$key] = $value !== null && (float) $value > 0 ? $value : '';
        }
        foreach (['category_id' => ['Kategori TikTok','category'], 'warehouse_id' => ['Gudang TikTok','text'], 'package_weight.value' => ['Berat paket','number'], 'package_dimensions.length' => ['Panjang paket','number'], 'package_dimensions.width' => ['Lebar paket','number'], 'package_dimensions.height' => ['Tinggi paket','number']] as $key => [$label,$type]) {
            $v = data_get($context, $key);
            if ($v === '' || ($type === 'number' && (float) $v <= 0)) { $fields[] = ['key' => $key, 'label' => $label, 'type' => $type, ...($type === 'number' ? ['unit' => str_contains($key, 'weight') ? 'kg' : 'cm'] : [])]; }
        }
        return ['context' => $context, 'required_fields' => $fields, 'target_shop_id' => $ctx['shop_id']];
    }

    public function assertTargetShop(string $id): void
    {
        $ctx = $this->transport->context(self::TARGET, false, 6);
        $this->require($ctx['shop_id'] === $id, 'Akun toko TikTok berubah selama proses. Periksa akun dan coba kembali.');
    }

    public function categories(): array
    {
        $data = $this->call(self::TARGET, 'GET', '/product/202309/categories', ['locale' => 'id-ID']);
        $this->require(is_array($data['categories'] ?? null), 'Kategori TikTok tidak tersedia.');
        return array_values(array_map(fn ($c) => ['id' => (string) $c['id'], 'name' => (string) ($c['local_name'] ?? $c['name'] ?? ''), 'parent_id' => (string) ($c['parent_id'] ?? '0'), 'is_leaf' => (bool) ($c['is_leaf'] ?? false)], array_filter($data['categories'], fn ($c) => is_array($c['permission_statuses'] ?? null) && in_array('AVAILABLE', $c['permission_statuses'], true))));
    }

    public function validateCategory(string $id): void
    {
        $this->require(count(array_filter($this->categories(), fn ($c) => $c['id'] === $id && $c['is_leaf'])) === 1, 'Kategori tidak tersedia atau bukan kategori akhir TikTok.');
    }

    public function validateAttributes(string $id): void
    {
        $data = $this->call(self::TARGET, 'GET', '/product/202309/categories/'.$id.'/attributes');
        $this->require(is_array($data['attributes'] ?? null), 'Atribut kategori TikTok belum lengkap.');
        foreach ($data['attributes'] as $a) { $this->require(! ($a['is_required'] ?? false) && ! ($a['is_requried'] ?? false), 'Kategori membutuhkan atribut wajib yang belum didukung. Lengkapi melalui Seller Center.'); }
    }

    public function validateWarehouse(string $id): void
    {
        $data = $this->call(self::TARGET, 'GET', '/logistics/202309/warehouses');
        $rows = array_filter($data['warehouses'] ?? [], fn ($w) => (string) ($w['id'] ?? '') === $id && ($w['type'] ?? '') === 'SALES_WAREHOUSE' && ($w['effect_status'] ?? '') === 'ENABLED');
        $this->require(count($rows) === 1, 'Gudang penjualan TikTok tidak tersedia untuk akun ini.');
    }

    public function linked(string $source): bool
    {
        $exists = DB::table('marketplace_listings as s')->join('marketplace_listings as t', 's.stock_master_id', '=', 't.stock_master_id')->where('s.account_key', self::SOURCE)->where('s.remote_product_id', $source)->where('t.account_key', self::TARGET)->exists();
        if ($exists) { return true; }
        return Schema::hasTable('sku_mappings') && Schema::hasColumn('sku_mappings', 'tiktok_product_id') && DB::table('sku_mappings')->where('shopee_item_id', $source)->whereNotNull('tiktok_product_id')->where('tiktok_product_id', '<>', '')->exists();
    }

    public function page(?string $cursor): array
    {
        $data = $this->call(self::TARGET, 'POST', '/product/202502/products/search', ['page_size' => 50, ...($cursor !== null ? ['page_token' => $cursor] : [])], ['status' => 'ALL']);
        $this->require(is_array($data['products'] ?? null) && array_is_list($data['products']) && array_key_exists('next_page_token', $data) && is_string($data['next_page_token']), 'Paginasi katalog TikTok tidak lengkap.');
        $ids = array_map(fn ($p) => (string) ($p['id'] ?? ''), $data['products']);
        foreach ($ids as $id) { $this->require(ctype_digit($id), 'Identitas katalog TikTok tidak lengkap.'); }
        $this->require(count(array_unique($ids)) === count($ids), 'Identitas katalog TikTok berulang.');
        return ['ids' => $ids, 'next' => $data['next_page_token'] === '' ? null : $data['next_page_token'], 'total' => $data['total_count'] ?? null];
    }

    public function target(string $id, bool $review = false): array
    {
        $ctx = $this->transport->context(self::TARGET, false, 6);
        $data = $this->call(self::TARGET, 'GET', '/product/202309/products/'.$id, $review ? ['return_under_review_version' => 'true'] : []);
        $p = $data['product'] ?? $data;
        $this->require((string) ($p['id'] ?? '') === $id && is_array($p['skus'] ?? null) && array_is_list($p['skus']) && count($p['skus']) > 0, 'Produk TikTok tidak lengkap.');
        $this->require(! isset($p['shop_id']) || (string) $p['shop_id'] === $ctx['shop_id'], 'Produk TikTok berasal dari akun berbeda.');
        foreach ($p['skus'] as $sku) { $this->require(ctype_digit((string) ($sku['id'] ?? '')) && array_key_exists('seller_sku', $sku), 'Identitas SKU TikTok belum lengkap.'); }
        return $p;
    }

    public function duplicate(array $source, array $target): bool
    {
        $skus = array_map('strtoupper', array_column($source['variants'], 'seller_sku'));
        foreach ($target['skus'] as $sku) {
            $value = strtoupper(trim((string) $sku['seller_sku']));
            if (in_array($value, $skus, true) || preg_match('/^INT-'.preg_quote($source['id'], '/').'(?:-|$)/i', $value)) { return true; }
        }
        $normalize = fn ($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
        return $normalize((string) ($target['title'] ?? '')) === $normalize($source['title']);
    }

    public function images(array $source): array
    {
        $images = array_map(fn ($url) => ['url' => $url, 'use_case' => 'MAIN_IMAGE'], $source['main_images']);
        foreach ($source['variants'] as $v) { foreach ($v['attributes'] as $a) { if (isset($a['image_url'])) { $images[] = ['url' => $a['image_url'], 'use_case' => 'ATTRIBUTE_IMAGE']; } } }
        return array_values(array_unique($images, SORT_REGULAR));
    }

    public function upload(array $image): string
    {
        $this->imageUrl($image['url']);
        try {
            $r = Http::timeout(6)->withOptions(['allow_redirects' => false])->get($image['url']);
            $this->require($r->successful() && str_starts_with(strtolower($r->header('Content-Type')), 'image/') && strlen($r->body()) > 0 && strlen($r->body()) <= 10 * 1024 * 1024, 'Gambar sumber tidak dapat diunduh atau terlalu besar.');
            return $this->transport->uploadTiktokImage($r->body(), $image['use_case']);
        } catch (\Throwable) { throw new \DomainException('Gambar sumber gagal diunduh/diunggah. Periksa gambar lalu coba kembali.'); }
    }

    public function payload(array $source, array $context, array $images): array
    {
        $uri = function (string $url, string $use) use ($images): string {
            foreach ($images as $image) { if ($image['url'] === $url && $image['use_case'] === $use && ! empty($image['uri'])) { return $image['uri']; } }
            throw new \DomainException('Gambar TikTok belum lengkap.');
        };
        $skus = [];
        foreach ($source['variants'] as $v) {
            $attrs = [];
            foreach ($v['attributes'] as $a) { $attrs[] = ['name' => $a['name'], 'value_name' => $a['value_name'], ...(isset($a['image_url']) ? ['sku_img' => ['uri' => $uri($a['image_url'], 'ATTRIBUTE_IMAGE')]] : [])]; }
            $skus[] = ['seller_sku' => $v['seller_sku'], 'price' => ['amount' => $v['price'], 'currency' => 'IDR'], 'inventory' => [['warehouse_id' => $context['warehouse_id'], 'quantity' => $v['stock']]], 'sales_attributes' => $attrs];
        }
        return ['title' => $source['title'], 'description' => $source['description'], 'category_id' => $context['category_id'], 'package_weight' => $context['package_weight'], 'package_dimensions' => $context['package_dimensions'], 'main_images' => array_map(fn ($url) => ['uri' => $uri($url, 'MAIN_IMAGE')], $source['main_images']), 'skus' => $skus];
    }

    public function create(array $payload): array { return $this->transport->createTiktokProduct($payload); }

    public function verify(array $source, array $context, string $id): array
    {
        $p = $this->target($id, true);
        $this->require(count($p['skus']) === count($source['variants']), 'Cakupan SKU hasil belum terverifikasi.');
        $result = [];
        foreach ($source['variants'] as $v) {
            $matches = array_values(array_filter($p['skus'], fn ($s) => (string) $s['seller_sku'] === $v['seller_sku']));
            $this->require(count($matches) === 1, 'Identitas SKU hasil belum terverifikasi.');
            $s = $matches[0];
            $inventory = array_values(array_filter($s['inventory'] ?? [], fn ($i) => (string) ($i['warehouse_id'] ?? '') === $context['warehouse_id']));
            $this->require(count($inventory) === 1 && (string) ($inventory[0]['quantity'] ?? '') === (string) $v['stock'] && $this->decimal($s['price']['sale_price'] ?? $s['price']['amount'] ?? null) === $v['price'], 'Harga atau stok hasil belum terverifikasi.');
            $result[] = ['id' => (string) $s['id'], 'seller_sku' => $v['seller_sku'], 'source_variant_id' => $v['id']];
        }
        $this->require(count(array_unique(array_column($result, 'id'))) === count($result), 'Identitas SKU hasil ambigu.');
        $published = in_array($p['status'] ?? null, ['ACTIVATE','ACTIVE',4], true) && ! in_array($p['audit']['status'] ?? '', ['AUDITING','PENDING','REJECTED'], true);
        $this->cache($source, $context, $p, $result, $published);
        return ['product_id' => $id, 'skus' => $result, 'published' => $published];
    }

    private function cache(array $source, array $context, array $p, array $result, bool $published): void
    {
        DB::transaction(function () use ($source, $context, $p, $result, $published): void {
            foreach ($result as $index => $r) {
                $v = $source['variants'][$index];
                if (Schema::hasTable('tiktok_products')) {
                    $identity = ['product_id' => (string) $p['id'], 'sku_id' => $r['id']];
                    if (Schema::hasColumn('tiktok_products', 'account_key')) { $identity['account_key'] = self::TARGET; }
                    $values = ['product_name' => $source['title'], 'sku_name' => $v['name'], 'seller_sku' => $v['seller_sku'], 'image_url' => $v['attributes'][0]['image_url'] ?? $source['main_images'][0], 'warehouse_id' => $context['warehouse_id'], 'product_status' => (string) ($p['status'] ?? ''), 'audit_status' => isset($p['audit']['status']) ? (string) $p['audit']['status'] : null, 'is_active' => $published, 'price' => (int) $v['price'], 'stock_qty' => $v['stock'], 'subtotal' => (int) $v['price'] * $v['stock'], 'created_at' => now(), 'updated_at' => now()];
                    DB::table('tiktok_products')->updateOrInsert($identity, array_intersect_key($values, array_flip(Schema::getColumnListing('tiktok_products'))));
                }
                $owners = DB::table('marketplace_listings')->where('account_key', self::SOURCE)->where('remote_product_id', $source['id'])->where('remote_variant_id', $v['id'])->get();
                if ($owners->count() !== 1 || (string) $owners[0]->seller_sku !== $v['seller_sku']) { continue; }
                $hash = hash('sha256', json_encode(['tiktok', (string) $p['id'], $r['id']], JSON_THROW_ON_ERROR));
                $conflict = DB::table('marketplace_listings')->where('account_key', self::TARGET)->where(fn ($q) => $q->where('stock_master_id', $owners[0]->stock_master_id)->orWhere('remote_identity_hash', $hash))->exists();
                if (! $conflict) {
                    DB::table('marketplace_listings')->insertOrIgnore(['stock_master_id' => $owners[0]->stock_master_id, 'account_key' => self::TARGET, 'channel' => 'tiktok', 'remote_product_id' => (string) $p['id'], 'remote_variant_id' => $r['id'], 'remote_identity_hash' => $hash, 'seller_sku' => $v['seller_sku'], 'is_active' => $published, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }

    private function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) { return null; }
        $value = (string) $value;
        if (! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) || strlen($value) > 20) { return null; }
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private function imageUrl(mixed $url): void
    {
        $parts = is_string($url) ? parse_url($url) : false;
        $host = strtolower($parts['host'] ?? '');
        $this->require(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && $host !== '' && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! filter_var($host, FILTER_VALIDATE_IP) && ! in_array($host, ['localhost','localhost.localdomain'], true) && ! str_ends_with($host, '.local'), 'URL gambar sumber harus HTTPS publik.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) { throw new \DomainException($message); }
    }
}
