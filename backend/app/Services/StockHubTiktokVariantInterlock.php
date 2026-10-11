<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Cross-entry protection for old bulk edits and durable stock-hub additions. */
class StockHubTiktokVariantInterlock
{
    public static function assertLegacyAvailable(string $target): void
    {
        if (! Schema::hasTable('stock_hub_tiktok_variant_runs')) { return; }
        $busy = DB::table('stock_hub_tiktok_variant_runs')->where('target_product_id', $target)
            ->where(function ($q) {
                $q->whereIn('status', ['preparing', 'scanning', 'uploading', 'submitting', 'verifying', 'submitted_unverified', 'under_review'])
                    ->orWhere(fn ($q) => $q->whereNotNull('attempted_at')->whereNull('accepted_at')->where('status', '<>', 'rejected'));
            })->exists();
        if ($busy) { throw new \DomainException('Penambahan varian TikTok masih berjalan atau belum terverifikasi. Muat status di Sinkronisasi Stok; jangan kirim ulang.'); }
    }

    public static function assertNoUnresolvedLegacy(string $target, array $source, array $snapshot): void
    {
        $counts = [];
        foreach ($snapshot['skus'] ?? [] as $sku) {
            if (is_string($sku['seller_sku'] ?? null)) { $counts[$sku['seller_sku']] = ($counts[$sku['seller_sku']] ?? 0) + 1; }
        }
        if (Schema::hasTable('stock_hub_tiktok_variant_legacy_attempts')) {
            foreach (DB::table('stock_hub_tiktok_variant_legacy_attempts')->where('target_product_id', $target)->whereIn('status', ['attempted', 'accepted'])->get() as $attempt) {
                $skus = json_decode($attempt->seller_skus, true, 512, JSON_THROW_ON_ERROR);
                self::requireObserved($skus, $counts);
                DB::table('stock_hub_tiktok_variant_legacy_attempts')->where('id', $attempt->id)->update(['status' => 'resolved', 'updated_at' => now()]);
            }
        }
        // Historical bulk audit rows predate the durable marker. A saved uncertain
        // result is not permission to repeat a still-missing SKU through a new entry.
        if (! Schema::hasTable('sku_variant_actions')) { return; }
        $query = DB::table('sku_variant_actions as a')->where('a.target_channel', 'tiktok')->where('a.action_type', 'bulk_create_variant')
            ->where('a.status', 'submitted_unverified');
        if (isset($source['id'])) {
            if (! Schema::hasTable('stock_master') || ! Schema::hasColumn('stock_master', 'shopee_product_id')) { return; }
            $query->join('stock_master as s', 'a.stock_master_id', '=', 's.id')->where('s.shopee_product_id', $source['id']);
        }
        $actions = $query->get(['a.payload']);
        foreach ($actions as $action) {
            $audit = json_decode($action->payload, true, 512, JSON_THROW_ON_ERROR);
            $path = $audit['mutation']['request']['path'] ?? null;
            if (is_string($path) && $path !== '/product/202509/products/'.$target.'/partial_edit') { continue; }
            $sku = $audit['outcome']['seller_sku'] ?? null;
            self::requireObserved(is_string($sku) ? [$sku] : [], $counts);
        }
    }

    private static function requireObserved(array $skus, array $counts): void
    {
        if (! $skus || ! array_is_list($skus)) { throw new \DomainException('Hasil percobaan varian sebelumnya belum lengkap. Periksa produk di Seller Center.'); }
        foreach ($skus as $sku) {
            if (! is_string($sku) || $sku === '' || ($counts[$sku] ?? 0) !== 1) {
                throw new \DomainException('Percobaan tambah varian sebelumnya belum terverifikasi. Sinkronkan katalog dan periksa Seller Center sebelum menambahkan ulang.');
            }
        }
    }

    public static function beginLegacy(string $target, array $skus, array $snapshot): ?string
    {
        if (! Schema::hasTable('stock_hub_tiktok_variant_legacy_attempts')) { return null; }
        self::assertLegacyAvailable($target); self::assertNoUnresolvedLegacy($target, [], $snapshot);
        $id = (string) Str::uuid();
        DB::table('stock_hub_tiktok_variant_legacy_attempts')->insert(['id' => $id, 'target_product_id' => $target,
            'status' => 'attempted', 'seller_skus' => json_encode(array_values($skus), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public static function finishLegacy(?string $id, string $status): void
    {
        if ($id !== null && in_array($status, ['resolved', 'accepted', 'rejected'], true)) {
            DB::table('stock_hub_tiktok_variant_legacy_attempts')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        }
    }
}
