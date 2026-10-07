<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Cache verified destination identities without legacy Stock Master synchronization. */
class StockHubGitaProductCache
{
    public function persist(array $source, array $context, array $parent, array $models, array $result, string $status, array $tiers): void
    {
        DB::transaction(function () use ($source, $context, $parent, $models, $result, $status, $tiers): void {
            $id = (string) $parent['item_id']; $shop = (string) $parent['shop_id'];
            if (Schema::hasTable('shopee_product')) {
                $existing = DB::table('shopee_product')->where('item_id', $id)->get();
                StockHubGitaProductSource::need($existing->every(fn ($p) => (string) ($p->shop_id ?? '') === $shop), 'Cache induk dimiliki toko lain. Periksa Seller Center.');
                $prices = array_column($source['variants'], 'price');
                $this->save('shopee_product', ['item_id' => $id], ['shop_id' => $shop, 'name' => $parent['item_name'], 'item_sku' => $parent['item_sku'], 'description' => $parent['description'] ?? '', 'status' => $status, 'is_active' => $status === 'NORMAL', 'weight' => $context['weight'], 'category_id' => $context['category_id'], 'price_min' => min($prices), 'price_max' => max($prices), 'price_before_discount' => min($prices), 'currency' => 'IDR', 'stock' => array_sum(array_column($source['variants'], 'stock')), 'has_model' => $source['has_model']]);
                foreach ($result as $i => $r) {
                    $v = $source['variants'][$i];
                    if ($source['has_model'] && Schema::hasTable('shopee_product_model')) {
                        $conflict = DB::table('shopee_product_model')->where('model_id', $r['id'])->where('item_id', '!=', $id)->exists();
                        StockHubGitaProductSource::need(!$conflict, 'Cache model dimiliki produk lain.');
                        $names = [];
                        foreach ($v['tier_index'] as $t => $o) { $names[] = $source['tiers'][$t]['options'][$o]['name']; }
                        $this->save('shopee_product_model', ['item_id' => $id, 'model_id' => $r['id']], ['name' => implode(', ', $names), 'model_sku' => $v['sku'], 'original_price' => $v['price'], 'price' => $v['price'], 'stock' => $v['stock'], 'is_active' => $status === 'NORMAL']);
                    }
                }
                if (Schema::hasTable('shopee_product_image')) {
                    foreach ($parent['image']['image_id_list'] as $i => $image) {
                        $url = $parent['image']['image_url_list'][$i] ?? null;
                        if (!is_string($url)) { continue; }
                        $this->save('shopee_product_image', ['item_id' => $id, 'model_id' => null, 'image_url' => $url], []);
                    }
                    foreach ($result as $i => $r) {
                        foreach ($source['variants'][$i]['tier_index'] as $ti => $oi) {
                            $url = $tiers[$ti]['options'][$oi]['url'] ?? '';
                            if ($url !== '') { $this->save('shopee_product_image', ['item_id' => $id, 'model_id' => $r['id'], 'image_url' => $url], []); }
                        }
                    }
                }
            }
            foreach ($result as $i => $r) {
                $v = $source['variants'][$i];
                $owners = DB::table('marketplace_listings')->where('account_key', StockHubGitaProductTransport::SOURCE)->where('remote_product_id', $source['id'])->where('remote_variant_id', $v['id'])->get();
                if ($owners->count() !== 1 || $owners[0]->seller_sku !== $v['sku']) { continue; }
                $hash = hash('sha256', json_encode(['shopee', $id, $r['id']], JSON_THROW_ON_ERROR));
                $existing = DB::table('marketplace_listings')->where('account_key', StockHubGitaProductTransport::TARGET)->where(fn ($q) => $q->where('stock_master_id', $owners[0]->stock_master_id)->orWhere('remote_identity_hash', $hash))->get();
                if ($existing->isNotEmpty()) {
                    if ($existing->count() === 1 && (string) $existing[0]->stock_master_id === (string) $owners[0]->stock_master_id && $existing[0]->remote_identity_hash === $hash && $existing[0]->seller_sku === $v['sku']) { DB::table('marketplace_listings')->where('id', $existing[0]->id)->update(['is_active' => $status === 'NORMAL', 'updated_at' => now()]); }
                    continue;
                }
                DB::table('marketplace_listings')->insertOrIgnore(['stock_master_id' => $owners[0]->stock_master_id, 'account_key' => StockHubGitaProductTransport::TARGET, 'channel' => 'shopee', 'remote_product_id' => $id, 'remote_variant_id' => $r['id'], 'remote_identity_hash' => $hash, 'seller_sku' => $v['sku'], 'warehouse_id' => $context['location_id'] ?? null, 'is_active' => $status === 'NORMAL', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    private function save(string $table, array $identity, array $values): void
    {
        $values = array_intersect_key([...$values, 'updated_at' => now()], array_flip(Schema::getColumnListing($table)));
        if (DB::table($table)->where($identity)->exists()) { DB::table($table)->where($identity)->update($values); }
        else { $this->insert($table, $identity, $values); }
    }

    private function insert(string $table, array $identity, array $values): void
    {
        $values = array_intersect_key([...$values, 'created_at' => now()], array_flip(Schema::getColumnListing($table)));
        DB::table($table)->insert([...$identity, ...$values]);
    }
}
