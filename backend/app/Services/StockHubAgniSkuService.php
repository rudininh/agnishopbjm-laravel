<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Each attempted model is permanently readback-only, even under a fresh request key. */
class StockHubAgniSkuService
{
    private const RUNS='stock_hub_agni_sku_runs';
    private const ATTEMPTS='stock_hub_agni_sku_attempts';
    private const GUARDS='stock_hub_agni_sku_guards';
    public function __construct(private StockHubAgniSkuGateway $gateway,private MarketplaceStockMirrorLease $lease,private MarketplaceCatalogMutationRevision $revision) {}

    public function start(string $source,string $key): array
    {
        $id=DB::transaction(function()use($source,$key):string {
            $this->coordinate('source:'.$source); $guard=$this->guard('source:'.$source);
            $repeat=DB::table(self::RUNS)->where('request_key',$key)->first();
            if($repeat){abort_unless($repeat->source_product_id===$source,409,'Identitas permintaan sudah dipakai.');return $repeat->id;}
            $current=$guard->run_id?DB::table(self::RUNS)->where('id',$guard->run_id)->first():null;
            if($current&&(!$this->restartable($current)||($guard->owner&&$guard->expires_at>now()->toDateTimeString())))return $current->id;
            $target=$this->gateway->targetId($source); $this->coordinate('target:'.$target);
            StockHubTiktokVariantInterlock::assertRepairAvailable($source,$target,$current?->id);
            StockHubTiktokVariantInterlock::assertAdditionAvailable($source,$target,$current?->id);
            $this->assertLegacyFree($target);
            $targetGuard=$this->guard('target:'.$target); $other=$targetGuard->run_id?DB::table(self::RUNS)->where('id',$targetGuard->run_id)->first():null;
            $this->require(!$other||$this->restartable($other),'Normalisasi sumber lain masih memiliki produk TikTok ini.');
            $id=(string)Str::uuid();
            DB::table(self::RUNS)->insert(['id'=>$id,'request_key'=>$key,'source_product_id'=>$source,'target_product_id'=>$target,'status'=>'preparing',
                'state'=>json_encode(['message'=>'Memeriksa semua nama dan template SKU Agni.']),'created_at'=>now(),'updated_at'=>now()]);
            foreach(['source:'.$source,'target:'.$target] as $k)DB::table(self::GUARDS)->where('key',$k)->update(['run_id'=>$id,'owner'=>null,'expires_at'=>null]);
            return $id;
        }); return $this->show($id);
    }
    public function source(string $source): ?array { $id=DB::table(self::GUARDS)->where('key','source:'.$source)->value('run_id');return $id?$this->show($id):null; }

    /** A completed child is evidence for its saved names, never for newly edited names. No writes. */
    public function assertVerified(string $id,string $source):void
    {
        $row=$this->row($id);$state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
        $this->require($row->source_product_id===$source&&in_array($row->status,['success','exists'],true)&&isset($state['source']), 'SKU sumber belum terverifikasi.');
        $fresh=$this->gateway->read($source);
        $this->require($this->identity($fresh)===$this->identity($state['source'])&&$this->gateway->targetId($source)===$row->target_product_id,'Nama atau hubungan sumber berubah setelah normalisasi.');
        foreach($fresh['variants'] as $m)$this->require($m['seller_sku']===$m['expected_sku'],'SKU sumber berubah setelah normalisasi.');
        $this->gateway->assertRelation($row->target_product_id,$fresh,$state['target_shop_id']??null);
    }
    public function show(string $id): array
    {
        $row=$this->row($id); $state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
        $changes=DB::table(self::ATTEMPTS)->where('run_id',$id)->orderBy('id')->get()->map(fn($m)=>['name'=>$m->name,'old_sku'=>$m->old_sku,'seller_sku'=>$m->seller_sku,'status'=>$m->verified_at?'verified':($m->status==='rejected'?'rejected':($m->attempted_at?'submitted_unverified':'queued'))])->all();
        return ['run_id'=>$id,'source_product_id'=>$row->source_product_id,'remote_product_id'=>$row->target_product_id,'status'=>$row->status,'message'=>$state['message']??'',
            'can_continue'=>in_array($row->status,['preparing','submitting','verifying'],true),'can_retry'=>$this->restartable($row),
            'next_step_after_ms'=>max(0,min(5000,(int)($state['retry_at']??0)-now()->getTimestampMs())),'result'=>['changed'=>$changes]];
    }
    public function step(string $id): array
    {
        $row=$this->row($id); if(in_array($row->status,['success','exists','rejected','reconciled'],true)||($row->status==='blocked'&&$this->restartable($row)))return $this->show($id);
        $owner=(string)Str::uuid();
        $claimed=DB::table(self::GUARDS)->where('key','source:'.$row->source_product_id)->where('run_id',$id)->where(fn($q)=>$q->whereNull('owner')->orWhere('expires_at','<=',now()))->update(['owner'=>$owner,'expires_at'=>now()->addMinutes(5)]);
        if(!$claimed)return $this->show($id);
        $held=false; $state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
        try {
            $row=$this->row($id); $state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
            if(($state['retry_at']??0)>now()->getTimestampMs())return $this->show($id); unset($state['retry_at']);
            $this->lease->acquire(); $held=true;
            $this->assertChildrenFree($row);
            $fresh=$this->gateway->read($row->source_product_id);
            $this->require($this->gateway->targetId($row->source_product_id)===$row->target_product_id,'Hubungan produk TikTok berubah. Periksa mapping sebelum melanjutkan.');
            $state['target_shop_id']=$this->gateway->assertRelation($row->target_product_id,$fresh,$state['target_shop_id']??null);
            if(!isset($state['source'])) {
                $state['source']=$fresh;
                DB::transaction(function()use($row,$state,$fresh):void {
                    foreach($fresh['variants'] as $m)if($m['seller_sku']!==$m['expected_sku'])DB::table(self::ATTEMPTS)->insert(['run_id'=>$row->id,'model_id'=>$m['id'],'name'=>$m['name'],'old_sku'=>$m['seller_sku'],'seller_sku'=>$m['expected_sku'],'status'=>'queued','created_at'=>now(),'updated_at'=>now()]);
                    $this->save($row->id,'submitting',$state,'Template seluruh varian siap; memperbarui satu SKU per langkah.');
                });
                return $this->show($id);
            }
            $attempts=DB::table(self::ATTEMPTS)->where('run_id',$id)->orderBy('id')->get();
            $expected=$state['source']; $this->require($this->identity($fresh)===$this->identity($expected),'Daftar model, nama, atau toko Agni berubah. Periksa katalog; jangan kirim ulang.');
            $byId=array_column($fresh['variants'],null,'id'); $pending=$attempts->first(fn($a)=>!$a->verified_at);
            foreach($expected['variants'] as $m) {
                $a=$attempts->firstWhere('model_id',$m['id']); $sku=$byId[$m['id']]['seller_sku'];
                $allowed=$a?($a->verified_at?[$a->seller_sku]:($a->attempted_at?[$a->old_sku,$a->seller_sku]:[$a->old_sku])):[$m['expected_sku']];
                $this->require(in_array($sku,$allowed,true),'SKU Agni berubah di luar percobaan tersimpan. Periksa katalog; jangan kirim ulang.');
            }
            if($pending&&$pending->attempted_at) {
                if($byId[$pending->model_id]['seller_sku']!==$pending->seller_sku){$this->save($id,'submitted_unverified',$state,'SKU Agni belum terlihat tepat. Periksa dan Lanjutkan; perubahan tidak dikirim ulang.');return $this->show($id);}
                DB::table(self::ATTEMPTS)->where('id',$pending->id)->update(['status'=>'verified','verified_at'=>now(),'updated_at'=>now()]);
                $this->save($id,'submitting',$state,'SKU model terverifikasi; melanjutkan model berikutnya.'); return $this->show($id);
            }
            if($pending) {
                // Preparation can fail without consuming the single attempt. Signing context stays in memory.
                $send=$this->gateway->prepare($row->source_product_id,$fresh['shop_id']); $this->lease->renew();
                $this->revision->beforeLegacyMutation();
                $saved=DB::transaction(function()use($row,$pending):int {
                    $this->coordinate('source:'.$row->source_product_id);$this->coordinate('target:'.$row->target_product_id);$this->assertChildrenFree($row);
                    return DB::table(self::ATTEMPTS)->where('id',$pending->id)->whereNull('attempted_at')->update(['attempted_at'=>now(),'status'=>'attempted','updated_at'=>now()]);
                });
                $this->require($saved===1,'Percobaan model sudah tercatat. Periksa Status tanpa mengirim ulang.');
                $send($pending->model_id,$pending->seller_sku); $this->save($id,'verifying',$state,'Update SKU diterima; memeriksa hasil tepat di Shopee.'); return $this->show($id);
            }
            // All remote changes are observed. Repeat a complete fresh identity guard immediately before cache writes.
            $final=$this->gateway->read($row->source_product_id);
            $this->require($this->identity($final)===$this->identity($expected)&&$this->gateway->targetId($row->source_product_id)===$row->target_product_id,'Identitas sumber atau hubungan TikTok berubah pada pemeriksaan terakhir.');
            $this->gateway->assertRelation($row->target_product_id,$final,$state['target_shop_id']??null);
            foreach($final['variants'] as $m)$this->require($m['seller_sku']===$m['expected_sku'],'Hasil SKU Agni belum tepat. Periksa Status tanpa mengirim ulang.');
            DB::transaction(function()use($row,$state,$final,$attempts):void {
                $this->coordinate('source:'.$row->source_product_id);$this->coordinate('target:'.$row->target_product_id);$this->assertChildrenFree($row);
                $this->gateway->cache($row->source_product_id,$final['variants']);
                $this->save($row->id,$attempts->isEmpty()?'exists':'success',$state,'SKU seluruh varian Agni terverifikasi mengikuti nama terbaru.');
            });
        } catch(MarketplaceOperationBusy) { $state['retry_at']=now()->addSeconds(5)->getTimestampMs();$this->save($id,$row->status,$state,'Menunggu proses marketplace lain. Progres SKU tersimpan.'); }
        catch(StockHubAgniSkuRejected) {
            DB::table(self::ATTEMPTS)->where('run_id',$id)->whereNotNull('attempted_at')->whereNull('verified_at')->update(['status'=>'rejected','updated_at'=>now()]);
            $this->save($id,'rejected',$state,'Shopee menolak update SKU. Periksa template dan akun lalu lanjutkan dengan permintaan baru.');
        }
        catch(\Throwable) {
            $uncertain=DB::table(self::ATTEMPTS)->where('run_id',$id)->whereNotNull('attempted_at')->whereNull('verified_at')->exists();
            $this->save($id,$uncertain?'submitted_unverified':'blocked',$state,$uncertain?'Hasil update SKU Agni belum pasti. Periksa dan Lanjutkan; perubahan tidak dikirim ulang.':'Normalisasi SKU Agni belum aman. Periksa nama, template, akun, hubungan, dan katalog sebelum melanjutkan.');
        } finally { if($held)$this->lease->release();DB::table(self::GUARDS)->where('key','source:'.$row->source_product_id)->where('owner',$owner)->update(['owner'=>null,'expires_at'=>null]); }
        return $this->show($id);
    }
    private function assertChildrenFree(object $r): void { StockHubTiktokVariantInterlock::assertRepairAvailable($r->source_product_id,$r->target_product_id,$r->id);StockHubTiktokVariantInterlock::assertAdditionAvailable($r->source_product_id,$r->target_product_id,$r->id);$this->assertLegacyFree($r->target_product_id); }
    private function assertLegacyFree(string $target): void
    {
        $this->require(!DB::table('stock_hub_tiktok_variant_legacy_attempts')->where('target_product_id',$target)->whereIn('status',['attempted','accepted'])->exists(),'Percobaan TikTok lama belum selesai. Periksa status sebelum mengubah Agni.');
    }
    /** Explicit readback only: obsolete names may be replanned after every old write is observed. */
    public function recover(string $id): array
    {
        $row=$this->row($id);
        if($this->restartable($row))return $this->show($id);
        $owner=(string)Str::uuid();
        $claimed=DB::table(self::GUARDS)->where('key','source:'.$row->source_product_id)->where('run_id',$id)
            ->where(fn($q)=>$q->whereNull('owner')->orWhere('expires_at','<=',now()))->update(['owner'=>$owner,'expires_at'=>now()->addMinutes(5)]);
        if(!$claimed)return $this->show($id);
        $held=false;$state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
        try {
            $row=$this->row($id);$state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
            if($this->restartable($row))return $this->show($id);
            $this->lease->acquire();$held=true;$this->assertChildrenFree($row);
            $fresh=$this->gateway->read($row->source_product_id);
            $this->require(isset($state['source'])&&$fresh['shop_id']===$state['source']['shop_id']
                &&$this->gateway->targetId($row->source_product_id)===$row->target_product_id,'Identitas sumber atau hubungan TikTok berubah.');
            $this->gateway->assertRelation($row->target_product_id,$fresh,$state['target_shop_id']??null);
            $byId=array_column($fresh['variants'],null,'id');
            $attempted=DB::table(self::ATTEMPTS)->where('run_id',$id)->whereNotNull('attempted_at')->get();
            foreach($attempted as $a)$this->require(($byId[$a->model_id]['seller_sku']??null)===$a->seller_sku,'Hasil kiriman SKU lama belum terverifikasi.');
            DB::transaction(function()use($row,$id,$state,$fresh,$attempted):void {
                $this->coordinate('source:'.$row->source_product_id);$this->coordinate('target:'.$row->target_product_id);$this->assertChildrenFree($row);
                foreach($attempted as $a)DB::table(self::ATTEMPTS)->where('id',$a->id)->update(['status'=>'verified','verified_at'=>$a->verified_at??now(),'updated_at'=>now()]);
                $drift=$this->identity($fresh)!==$this->identity($state['source']);
                $this->save($id,$drift?'reconciled':'submitting',$state,$drift
                    ?'Kiriman SKU lama terverifikasi. Nama Agni berubah; rencana lama ditutup. Lanjutkan normalisasi mengikuti nama Agni terbaru.'
                    :'Kiriman SKU lama terverifikasi. Lanjutkan normalisasi varian yang tersisa.');
            });
        } catch(MarketplaceOperationBusy) {
            $this->save($id,$row->status,$state,'Menunggu proses marketplace lain. Periksa dan Lanjutkan SKU Agni kembali.');
        } catch(\Throwable) {
            $this->save($id,$row->status,$state,'Pemulihan SKU Agni belum terverifikasi. Periksa akun, hubungan produk, dan hasil SKU lama; perubahan tidak dikirim ulang.');
        } finally {
            if($held)$this->lease->release();
            DB::table(self::GUARDS)->where('key','source:'.$row->source_product_id)->where('owner',$owner)->update(['owner'=>null,'expires_at'=>null]);
        }
        return $this->show($id);
    }
    private function identity(array $s): array { $variants=array_map(fn($v)=>['id'=>$v['id'],'name'=>$v['name'],'expected_sku'=>$v['expected_sku']],$s['variants']);usort($variants,fn($a,$b)=>strcmp($a['id'],$b['id']));return ['id'=>$s['id'],'shop_id'=>$s['shop_id'],'variants'=>$variants]; }
    private function restartable(object $r): bool { return in_array($r->status,['success','exists','rejected','reconciled'],true)||($r->status==='blocked'&&!DB::table(self::ATTEMPTS)->where('run_id',$r->id)->whereNotNull('attempted_at')->exists()); }
    private function row(string $id): object { $r=DB::table(self::RUNS)->where('id',$id)->first();abort_unless($r,404,'Proses SKU Agni tidak ditemukan.');return $r; }
    private function coordinate(string $key): void { DB::table('stock_hub_tiktok_variant_guards')->insertOrIgnore(['key'=>$key]);DB::table('stock_hub_tiktok_variant_guards')->where('key',$key)->lockForUpdate()->first(); }
    private function guard(string $key): object { DB::table(self::GUARDS)->insertOrIgnore(['key'=>$key]);return DB::table(self::GUARDS)->where('key',$key)->lockForUpdate()->first(); }
    private function save(string $id,string $status,array $state,string $message): void { $state['message']=$message;DB::table(self::RUNS)->where('id',$id)->update(['status'=>$status,'state'=>json_encode($state,JSON_THROW_ON_ERROR),'updated_at'=>now()]); }
    private function require(bool $ok,string $message): void { if(!$ok)throw new \DomainException($message); }
}
