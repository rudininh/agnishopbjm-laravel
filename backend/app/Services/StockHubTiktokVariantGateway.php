<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Account-bound variant reads and one non-retrying, combined partial edit. */
class StockHubTiktokVariantGateway
{
    public function __construct(private StockHubTiktokProductGateway $products, private MarketplaceStockMirrorTransport $transport, private StockHubTiktokVariantPayload $payload) {}

    public function source(string $id, bool $allowInactive = false): array
    {
        $source=$this->products->source($id,true,$allowInactive);
        if($allowInactive)foreach($source['variants'] as $variant) {
            $this->require($variant['seller_sku']===(new ShopeeSellerSkuTemplate())->build($id,$variant['name']), 'Nama atau SKU sumber berubah setelah normalisasi batch. Periksa dan Lanjutkan dari tahap sumber.');
        }
        return $source;
    }
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
        if ($canonical($review) !== $canonical($live)) { throw new StockHubTiktokVariantIdentityRepairRequired('Versi aktif dan pemeriksaan produk TikTok berbeda. Selesaikan perubahan di Seller Center atau periksa Perbaiki Mengikuti Agni.'); }
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
        return $this->verifyProduct($source,$expected,$targetId,$product);
    }

    /** Explicit recovery only: prove approved absence, without replaying or caching an edit. */
    public function reconcileMissing(array $source, array $expected, string $targetId): array
    {
        $target=$this->target($targetId); // Requires equivalent approved live and review snapshots.
        $actual=$this->payload->retained($target);
        $submitted=$expected['payload']['skus']??null;
        $variants=$expected['variants']??null;
        $this->require(is_array($submitted)&&array_is_list($submitted)&&$submitted!==[]
            &&is_array($variants)&&array_is_list($variants)&&$variants!==[], 'Kontrak kiriman sebelumnya belum lengkap.');
        $bySku=array_column($actual,null,'seller_sku');$old=[];$new=array_column($variants,null,'seller_sku');
        $this->require(count($new)===count($variants),'Identitas varian kiriman sebelumnya berulang.');
        foreach($submitted as $row){
            $sku=$row['seller_sku']??null;
            $this->require(is_string($sku)&&$sku!==''&&!isset($old[$sku]),'Identitas SKU kiriman sebelumnya belum lengkap.');
            $old[$sku]=$row;$current=$bySku[$sku]??null;
            $this->require(isset($row['id'])||isset($new[$sku]),'Asal varian kiriman sebelumnya belum lengkap.');
            if(isset($row['id']))$this->require($current!==null&&(string)$row['id']===$current['id'],'Varian lama TikTok berubah atau hilang. Perbaiki relasi sebelum menambahkan.');
            if($current!==null)$this->require($this->payload->combination($row['sales_attributes']??[])===$this->payload->combination($current['sales_attributes']), 'Nama varian TikTok berubah. Perbaiki mengikuti Agni terlebih dahulu.');
        }
        $this->require(!array_diff_key($bySku,$old),'Ada varian TikTok di luar kiriman sebelumnya. Periksa relasi produk terlebih dahulu.');
        $missing=[];
        foreach($new as $sku=>$variant){
            $this->require(isset($old[$sku])&&!isset($old[$sku]['id']),'Kontrak varian baru sebelumnya tidak valid.');
            $matches=array_values(array_filter($source['variants']??[],fn($v)=>($v['id']??null)===($variant['id']??null)&&($v['seller_sku']??null)===$sku));
            $this->require(count($matches)===1,'Identitas varian Agni berubah. Periksa SKU dan nama sumber.');
            if(isset($bySku[$sku])){
                $mapped=$this->payload->mapAttributes($matches[0]['attributes']??[],array_map(fn($a)=>['id'=>$a['id'],'name'=>$a['name']],$old[$sku]['sales_attributes']));
                $this->require($this->payload->combination($mapped)===$this->payload->combination($old[$sku]['sales_attributes']),'Atribut varian Agni berubah. Periksa sumber terlebih dahulu.');
            }else $missing[]=['source_variant_id'=>$variant['id'],'seller_sku'=>$sku,'name'=>$matches[0]['name']];
        }
        return ['target'=>$target,'missing'=>$missing];
    }

    /** Approved dual readback of the old contract, allowing independently added rows. */
    public function verifyContinuation(array $source,array $expected,string $targetId,array $accepted):array
    {
        $product=$this->target($targetId);
        $bySku=array_column($product['skus'],null,'seller_sku');$pins=[];
        $this->require(count($accepted)===count($expected['variants']??[]),'Bukti varian parsial belum lengkap.');
        foreach($accepted as $pin){
            $sku=$pin['seller_sku']??null;$target=$pin['target_sku_id']??$pin['id']??null;$model=$pin['source_variant_id']??null;
            $this->require(is_string($sku)&&!isset($pins[$sku])&&is_string($target)&&$target!==''
                &&(string)($bySku[$sku]['id']??'')===$target
                &&count(array_filter($expected['variants']??[],fn($v)=>($v['seller_sku']??null)===$sku&&($v['id']??null)===$model))===1,'Identitas varian parsial yang diterima berubah atau hilang. Jangan kirim ulang.');
            $pins[$sku]=$target;
        }
        $submitted=array_column($expected['payload']['skus']??[],'seller_sku');
        $product['skus']=array_values(array_filter($product['skus'],fn($sku)=>in_array($sku['seller_sku']??null,$submitted,true)));
        return $this->verifyProduct($source,$expected,$targetId,$product);
    }

    private function verifyProduct(array $source,array $expected,string $targetId,array $product):array
    {
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
        $this->require(is_array($submitted) && array_is_list($submitted)
            && is_array($expected['variants'] ?? null) && $expected['variants'] !== []
            && is_int($expected['price'] ?? null) && $expected['price'] > 0 && is_string($expected['warehouse_id'] ?? null), 'Cakupan varian hasil belum terverifikasi.');
        $bySku = array_column($actual, null, 'seller_sku');
        if(count($actual)!==count($submitted)||array_diff(array_column($submitted,'seller_sku'),array_keys($bySku))) {
            throw new StockHubTiktokVariantCoverageMismatch($submitted,$actual,$product['audit']['status']??null);
        }
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
