<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Cross-entry protection for old bulk edits and durable stock-hub additions. */
class StockHubTiktokVariantInterlock
{
    /** Retire only provably unsent work; attempted children must take the readback path. */
    public static function retireUnattempted(string $kind,string $id,string $source): string
    {
        $table=match($kind){'addition'=>'stock_hub_tiktok_variant_runs','repair'=>'stock_hub_tiktok_variant_repair_runs'};
        $ownGuards=$kind==='repair'?'stock_hub_tiktok_variant_repair_guards':'stock_hub_tiktok_variant_guards';
        $hint=DB::table($table)->where('id',$id)->first();
        // Historic repair audits have no durable child yet; the repair service adopts them readback-only.
        if(!$hint)return $kind==='repair'?'attempted':'unknown';
        return DB::transaction(function()use($table,$ownGuards,$hint,$id,$source){
            $keys=['source:'.$source];if($hint->target_product_id!==null)$keys[]='target:'.$hint->target_product_id;
            foreach(array_unique(['stock_hub_tiktok_variant_guards',$ownGuards]) as $guards)foreach($keys as $key){
                DB::table($guards)->insertOrIgnore(['key'=>$key]);
                $guard=DB::table($guards)->where('key',$key)->lockForUpdate()->first();
                if($guard->owner&&$guard->expires_at>now()->toDateTimeString())return 'busy';
            }
            $row=DB::table($table)->where('id',$id)->lockForUpdate()->first();
            if(!$row||(string)$row->source_product_id!==$source||$row->target_product_id!==$hint->target_product_id)return 'unknown';
            if($row->attempted_at!==null)return 'attempted';
            if($row->accepted_at!==null)return 'unknown';
            $state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
            if(($state['batch_retired_before_attempt']??false)===true)return 'retired';
            if(!in_array($row->status,['preparing','scanning','uploading','submitting','blocked'],true))return 'unknown';
            $state['batch_retired_before_attempt']=true;$state['stage']='blocked';$state['message']='Persiapan lama dihentikan sebelum pengiriman; batch memeriksa SKU sumber terlebih dahulu.';
            DB::table($table)->where('id',$id)->update(['status'=>'blocked','state'=>json_encode($state,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
            return 'retired';
        });
    }

    public static function assertSourceSkuAvailable(?string $source = null, ?string $target = null, ?string $except = null): void
    {
        if (!Schema::hasTable('stock_hub_agni_sku_runs')) return;
        $q=DB::table('stock_hub_agni_sku_runs')->where(function($q)use($source,$target){if($source!==null)$q->where('source_product_id',$source);if($target!==null)$q->orWhere('target_product_id',$target);});
        if($except!==null)$q->where('id','<>',$except);
        $q->where(function($q){$q->whereNotIn('status',['success','exists','rejected','blocked','reconciled'])->orWhere(function($q){$q->where('status','blocked')->whereExists(function($q){$q->selectRaw('1')->from('stock_hub_agni_sku_attempts as a')->whereColumn('a.run_id','stock_hub_agni_sku_runs.id')->whereNotNull('a.attempted_at');});});});
        if($q->exists())throw new StockHubAgniSkuPending();
    }

    public static function assertRepairAvailable(?string $source = null, ?string $target = null, ?string $normalizationId = null): void
    {
        self::assertSourceSkuAvailable($source,$target,$normalizationId);
        if (Schema::hasTable('stock_hub_tiktok_variant_repair_runs')) {
            $q = DB::table('stock_hub_tiktok_variant_repair_runs');
            $q->where(function ($q) use ($source, $target) { if ($source !== null) $q->where('source_product_id', $source); if ($target !== null) $q->orWhere('target_product_id', $target); });
            $q->where(function ($q) { $q->whereIn('status', ['preparing','scanning','uploading','submitting','verifying','under_review','submitted_unverified'])
                ->orWhere(fn($q) => $q->whereNotNull('attempted_at')->whereNotIn('status', ['success','exists','rejected'])); });
            if ($q->exists()) throw new \DomainException('Perbaikan varian TikTok masih berjalan atau belum pasti. Pulihkan Perbaiki Mengikuti Agni dan Periksa Status.');
        }
        // Historic repair audit owns the target until exact dual-readback resolves it.
        if (Schema::hasTable('tiktok_reconciliation_run_items')) {
            $q = DB::table('tiktok_reconciliation_run_items')->where('action_type','agni_source_reconcile')->whereNotIn('status',['verified','rejected']);
            if ($target !== null) $q->where('target_product_id',$target);
            foreach ($q->get() as $item) {
                $audit=json_decode($item->payload ?? '',true);
                $auditSource=$audit['source']['id'] ?? null;
                if ($target !== null || ($source !== null && (string)$auditSource === $source)) throw new \DomainException('Perbaikan operator sebelumnya belum terverifikasi. Pulihkan Perbaiki Mengikuti Agni dan Periksa Status.');
            }
        }
    }

    public static function assertAdditionAvailable(string $source, ?string $target = null, ?string $normalizationId = null): void
    {
        self::assertSourceSkuAvailable($source,$target,$normalizationId);
        if (!Schema::hasTable('stock_hub_tiktok_variant_runs')) return;
        $q=DB::table('stock_hub_tiktok_variant_runs')->where(function($q)use($source,$target){$q->where('source_product_id',$source);if($target!==null)$q->orWhere('target_product_id',$target);});
        $q->where(function($q){$q->whereIn('status',['preparing','scanning','uploading','submitting','verifying','under_review','submitted_unverified'])
            ->orWhere(fn($q)=>$q->whereNotNull('attempted_at')->whereNotIn('status',['success','partial','exists','rejected','reconciled_missing']));});
        if($q->exists())throw new \DomainException('Penambahan varian masih berjalan atau belum pasti. Selesaikan status penambahan sebelum perbaikan.');
    }

    public static function assertLegacyAvailable(string $target): void
    {
        self::assertRepairAvailable(null, $target);
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
