<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Account-bound variant reads and one non-retrying, combined partial edit. */
class StockHubTiktokVariantGateway
{
    public function __construct(private StockHubTiktokProductGateway $products, private MarketplaceStockMirrorTransport $transport, private StockHubTiktokVariantPayload $payload) {}

    public function source(string $id): array { return $this->products->source($id, true); }
    public function page(?string $cursor): array { return $this->products->page($cursor); }
    public function catalogTarget(string $id): array { return $this->products->target($id, true); }
    public function targetShop(): string { return $this->transport->context(StockHubTiktokProductGateway::TARGET, false, 6)['shop_id']; }
    public function assertTargetShop(string $shopId): void { $this->products->assertTargetShop($shopId); }

    public function target(string $id): array
    {
        $shop = $this->targetShop();
        $review = $this->products->target($id, true);
        $live = $this->products->target($id);
        $this->assertTargetShop($shop);
        foreach ([$review, $live] as $product) {
            $this->require(in_array($product['status'] ?? null, ['ACTIVATE','ACTIVE',4], true)
                && ($product['audit']['status'] ?? null) === 'APPROVED', 'Produk TikTok memiliki perubahan tertunda atau belum aktif. Selesaikan pemeriksaan di Seller Center.');
        }
        $canonical = function (array $product): array {
            $rows = $this->payload->retained($product, true);
            usort($rows, fn ($a, $b) => strcmp($a['id'], $b['id']));
            return ['skus' => $rows, 'package_weight' => $product['package_weight'] ?? null, 'package_dimensions' => $product['package_dimensions'] ?? null];
        };
        $this->require($canonical($review) === $canonical($live), 'Versi aktif dan pemeriksaan produk TikTok berbeda. Selesaikan perubahan di Seller Center.');
        return $live;
    }

    public function upload(array $variant): string
    {
        return $this->products->upload(['url' => $variant['image_url'] ?? '', 'use_case' => 'ATTRIBUTE_IMAGE']);
    }

    public function prepareEdit(string $targetId, string $shopId): \Closure
    {
        return $this->transport->prepareTiktokProductVariantEdit($targetId, $shopId);
    }

    public function verify(array $source, array $expected, string $targetId): array
    {
        $shop = $this->targetShop();
        $product = $this->products->target($targetId, true);
        $this->assertTargetShop($shop);
        $this->require(in_array($product['status'] ?? null, ['ACTIVATE','ACTIVE',4,'SELLER_DRAFT','DRAFT','PENDING','AUDITING'], true)
            && in_array($product['audit']['status'] ?? null, ['APPROVED','AUDITING','PENDING'], true), 'Status produk TikTok hasil belum terverifikasi.');
        $actual = $this->payload->retained($product);
        $packages = new StockHubTiktokPackageTemplate();
        foreach (['weight','dimensions'] as $kind) {
            $field = 'package_'.$kind;
            if (array_key_exists($field, $product)) {
                $this->require(is_array($product[$field]) && $packages->matches($product[$field], $product[$field], $kind), 'Paket produk hasil belum terverifikasi.');
            }
            if (isset($expected['payload'][$field])) {
                $this->require(is_array($product[$field] ?? null) && $packages->matches($expected['payload'][$field], $product[$field], $kind), 'Paket produk hasil belum terverifikasi.');
            }
        }
        $submitted = $expected['payload']['skus'] ?? null;
        $this->require(is_array($submitted) && array_is_list($submitted) && count($actual) === count($submitted)
            && is_array($expected['variants'] ?? null) && $expected['variants'] !== []
            && is_int($expected['price'] ?? null) && $expected['price'] > 0 && is_string($expected['warehouse_id'] ?? null), 'Cakupan varian hasil belum terverifikasi.');
        $bySku = array_column($actual, null, 'seller_sku');
        $new = []; $seen = [];
        foreach ($submitted as $row) {
            $sellerSku = $row['seller_sku'] ?? null;
            $this->require(is_string($sellerSku) && ! isset($seen[$sellerSku]) && isset($bySku[$sellerSku]), 'Identitas SKU hasil belum terverifikasi.');
            $seen[$sellerSku] = true; $current = $bySku[$sellerSku];
            foreach (['weight','dimensions'] as $kind) {
                $field = 'sku_'.$kind;
                $this->require(is_array($row[$field] ?? null) && $packages->matches($row[$field], $current[$field], $kind), 'Paket SKU hasil belum terverifikasi.');
            }
            $this->require(! isset($row['id']) || (string) $row['id'] === $current['id'], 'Identitas SKU lama TikTok berubah.');
            $this->require(count($row['sales_attributes'] ?? []) === count($current['sales_attributes']), 'Cakupan atribut hasil belum terverifikasi.');
            $currentAttrs = array_column($current['sales_attributes'], null, 'id');
            foreach ($row['sales_attributes'] as $attribute) {
                $a = $currentAttrs[(string) ($attribute['id'] ?? '')] ?? null;
                $this->require($a !== null && ($attribute['name'] ?? null) === $a['name'] && ($attribute['value_name'] ?? null) === $a['value_name']
                    && (! isset($row['id'], $attribute['value_id']) || (string) $attribute['value_id'] === ($a['value_id'] ?? null)), 'Identitas atribut hasil belum terverifikasi.');
            }
            $price = $row['price']['sale_price'] ?? $row['price']['amount'] ?? null;
            $currentPrice = $current['price']['sale_price'] ?? $current['price']['amount'] ?? null;
            $normalize = fn ($value) => str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value;
            $this->require(($row['price']['currency'] ?? null) === 'IDR' && $normalize($price) === $normalize($currentPrice)
                && count($row['inventory'] ?? []) === 1
                && (string) ($row['inventory'][0]['warehouse_id'] ?? '') === $current['inventory'][0]['warehouse_id'], 'Harga atau gudang hasil belum terverifikasi.');
            if ((string) ($row['inventory'][0]['quantity'] ?? '') !== (string) $current['inventory'][0]['quantity']) { throw new StockHubTiktokVariantStockMismatch(); }
            if (! isset($row['id'])) { $new[$sellerSku] = $current; }
        }
        $this->require(count($new) === count($expected['variants']), 'Cakupan varian baru hasil belum terverifikasi.');
        $published = in_array($product['status'], ['ACTIVATE','ACTIVE',4], true) && $product['audit']['status'] === 'APPROVED';
        $result = [];
        foreach ($expected['variants'] as $variant) {
            $current = $new[$variant['seller_sku'] ?? ''] ?? null;
            $sourceRows = array_values(array_filter($source['variants'] ?? [], fn ($s) => ($s['id'] ?? null) === ($variant['id'] ?? null) && ($s['seller_sku'] ?? null) === ($variant['seller_sku'] ?? null)));
            $this->require($current !== null && count($sourceRows) === 1, 'Kepemilikan varian baru belum terverifikasi.');
            $mapped = $this->payload->mapAttributes($variant['attributes'] ?? [], array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $current['sales_attributes']));
            $this->require($this->payload->combination($mapped) === $this->payload->combination($current['sales_attributes'])
                && (string) $expected['price'] === $normalize($current['price']['sale_price'] ?? $current['price']['amount'])
                && $expected['warehouse_id'] === $current['inventory'][0]['warehouse_id']
                && ($variant['stock'] ?? null) === $current['inventory'][0]['quantity'], 'Atribut, harga, atau stok varian baru belum terverifikasi.');
            unset($new[$variant['seller_sku']]);
            $result[] = ['source_variant_id' => $variant['id'], 'seller_sku' => $variant['seller_sku'], 'name' => $variant['name'], 'id' => $current['id'], 'target_sku_id' => $current['id'], 'status' => $published ? 'updated' : 'under_review', 'reason' => null];
        }
        $this->cache($source, $expected, $product, $result, $published);
        return ['product_id' => $targetId, 'published' => $published, 'price' => $expected['price'], 'variants' => $result];
    }

    private function cache(array $source, array $expected, array $product, array $result, bool $published): void
    {
        DB::transaction(function () use ($source, $expected, $product, $result, $published): void {
            // Laravel binds PHP booleans as integers; PostgreSQL boolean columns require SQL literals.
            $active = DB::raw($published ? 'true' : 'false');
            foreach ($result as $index => $row) {
                $variant = $expected['variants'][$index];
                // The legacy cache is dedicated to the fixed primary TikTok account. Scoped tables always bind it explicitly.
                if (Schema::hasTable('tiktok_products')) {
                    $identity = ['product_id' => (string) $product['id'], 'sku_id' => $row['id']];
                    if (Schema::hasColumn('tiktok_products', 'account_key')) { $identity['account_key'] = StockHubTiktokProductGateway::TARGET; }
                    $cached = DB::table('tiktok_products')->where($identity)->get();
                    $values = ['product_name' => $product['title'] ?? $source['title'], 'sku_name' => $variant['name'], 'seller_sku' => $row['seller_sku'], 'image_url' => $variant['image_url'] ?? $source['main_images'][0], 'warehouse_id' => $expected['warehouse_id'], 'product_status' => (string) $product['status'], 'audit_status' => $product['audit']['status'], 'is_active' => $active, 'price' => $expected['price'], 'stock_qty' => $variant['stock'], 'subtotal' => $expected['price'] * $variant['stock'], 'created_at' => now(), 'updated_at' => now()];
                    if ($cached->isEmpty() || ($cached->count() === 1 && (string) $cached[0]->seller_sku === $row['seller_sku'])) {
                        if ($cached->isNotEmpty()) { unset($values['created_at']); }
                        DB::table('tiktok_products')->updateOrInsert($identity, array_intersect_key($values, array_flip(Schema::getColumnListing('tiktok_products'))));
                    }
                }
                if (! Schema::hasTable('marketplace_listings')) { continue; }
                $owners = DB::table('marketplace_listings')->where('account_key', StockHubTiktokProductGateway::SOURCE)->where('remote_product_id', $source['id'])->where('remote_variant_id', $row['source_variant_id'])->get();
                if ($owners->count() !== 1 || (string) $owners[0]->seller_sku !== $row['seller_sku'] || ! $owners[0]->is_active) { continue; }
                if (DB::table('marketplace_listings')->where('account_key', StockHubTiktokProductGateway::SOURCE)->where('seller_sku', $row['seller_sku'])->count() !== 1) { continue; }
                $hash = hash('sha256', json_encode(['tiktok', (string) $product['id'], $row['id']], JSON_THROW_ON_ERROR));
                $conflicts = DB::table('marketplace_listings')->where('account_key', StockHubTiktokProductGateway::TARGET)->where(fn ($q) => $q->where('stock_master_id', $owners[0]->stock_master_id)->orWhere('remote_identity_hash', $hash)->orWhere(fn ($q) => $q->where('remote_product_id', (string) $product['id'])->where('remote_variant_id', $row['id'])))->get();
                if ($conflicts->isNotEmpty()) {
                    if ($conflicts->count() === 1 && (string) $conflicts[0]->stock_master_id === (string) $owners[0]->stock_master_id
                        && (string) $conflicts[0]->remote_product_id === (string) $product['id'] && (string) $conflicts[0]->remote_variant_id === $row['id']
                        && (string) $conflicts[0]->remote_identity_hash === $hash && (string) $conflicts[0]->seller_sku === $row['seller_sku']) {
                        DB::table('marketplace_listings')->where('id', $conflicts[0]->id)->update(['is_active' => $active, 'warehouse_id' => $expected['warehouse_id'], 'updated_at' => now()]);
                    }
                    continue;
                }
                DB::table('marketplace_listings')->insertOrIgnore(['stock_master_id' => $owners[0]->stock_master_id, 'account_key' => StockHubTiktokProductGateway::TARGET, 'channel' => 'tiktok', 'remote_product_id' => (string) $product['id'], 'remote_variant_id' => $row['id'], 'remote_identity_hash' => $hash, 'seller_sku' => $row['seller_sku'], 'warehouse_id' => $expected['warehouse_id'], 'is_active' => $active, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) { throw new \DomainException($message); }
    }
}
