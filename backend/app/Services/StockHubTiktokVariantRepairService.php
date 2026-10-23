<?php

namespace App\Services;

use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/** Durable, explicit Agni identity correction. Attempted edits are never replayed. */
class StockHubTiktokVariantRepairService
{
    private const TABLE='stock_hub_tiktok_variant_repair_runs';
    private const GUARDS='stock_hub_tiktok_variant_repair_guards';
    private const RUNNING=['preparing','scanning','uploading','submitting','verifying'];
    public function __construct(private StockHubTiktokVariantRepairGateway $gateway,private MarketplaceStockMirrorLease $lease,
        private MarketplaceCatalogMutationRevision $revision,private StockHubTiktokPackageTemplate $packages) {}

    public function start(string $source,string $key,bool $allowInactive = false): array
    {
        $id=DB::transaction(function()use($source,$key,$allowInactive) {
            $this->coordinationGuard('source:'.$source);
            $guard=$this->guard('source:'.$source);
            $repeat=DB::table(self::TABLE)->where('request_key',$key)->first();
            if($repeat){abort_unless($repeat->source_product_id===$source,409,'Identitas permintaan sudah dipakai.');return $repeat->id;}
            $current=$guard->run_id?DB::table(self::TABLE)->where('id',$guard->run_id)->first():null;
            if($current&&(!$this->restartable($current)||($guard->owner&&$guard->expires_at>now()->toDateTimeString())))return $current->id;
            StockHubTiktokVariantInterlock::assertAdditionAvailable($source);
            $audit=$this->auditForSource($source);
            $target=$audit?$audit->target_product_id:$this->gateway->targetId($source);
            $this->coordinationGuard('target:'.$target);StockHubTiktokVariantInterlock::assertAdditionAvailable($source,$target);
            $audit=$this->auditForTarget($source,$target);
            $id=$audit?$audit->run_id:(string)Str::uuid();
            $existing=DB::table(self::TABLE)->where('id',$id)->first();if($existing)return $id;
            $state=['allow_inactive_source'=>$allowInactive,'message'=>'Membaca sumber Agni dan rencana perbaikan TikTok.','progress'=>['scanned_products'=>0,'uploaded_images'=>0,'total_images'=>0]];
            $status='preparing';
            if($audit) {
                $state=[...$state,...$this->auditState($audit)];$status=$this->auditStatus($audit);
            }
            $targetGuard=$this->guard('target:'.$target);$this->assertTargetFree($targetGuard,$id);
            DB::table(self::GUARDS)->where('key','target:'.$target)->update(['run_id'=>$id]);
            DB::table(self::TABLE)->insert(['id'=>$id,'request_key'=>$key,'request_hash'=>hash('sha256',$source),'source_product_id'=>$source,'target_product_id'=>$target,
                'status'=>$status,'state'=>json_encode($state,JSON_THROW_ON_ERROR),'attempted_at'=>$audit?now():null,'accepted_at'=>$audit&&in_array($audit->status,['accepted','under_review','verified'],true)?now():null,'created_at'=>now(),'updated_at'=>now()]);
            DB::table(self::GUARDS)->where('key','source:'.$source)->update(['run_id'=>$id,'owner'=>null,'expires_at'=>null]);return $id;
        });return $this->show($id);
    }
    public function source(string $source): ?array
    {
        $id=DB::table(self::GUARDS)->where('key','source:'.$source)->value('run_id');
        $audit=$this->auditForSource($source);
        if($id){$row=$this->row($id);if(!$audit||!$this->restartable($row))return $this->snapshot($row);}
        return $audit?$this->auditSnapshot($audit):($id?$this->show($id):null);
    }
    public function show(string $id): array
    {
        $row=DB::table(self::TABLE)->where('id',$id)->first();if($row)return $this->snapshot($row);
        $audit=$this->auditByRun($id);abort_unless($audit,404,'Proses perbaikan tidak ditemukan.');return $this->auditSnapshot($audit);
    }
    public function step(string $id): array
    {
        $row=DB::table(self::TABLE)->where('id',$id)->first();
        if(!$row){$audit=$this->auditByRun($id);abort_unless($audit,404,'Proses perbaikan tidak ditemukan.');$source=$this->auditSource($audit);abort_unless($source,409,'Sumber audit lama belum dapat dipastikan.');$run=$this->start($source,(string)Str::uuid());$id=$run['run_id'];$row=$this->row($id);}
        if(!in_array($this->status($row),[...self::RUNNING,'under_review','submitted_unverified'],true))return $this->snapshot($row);
        $owner=(string)Str::uuid();
        $claimed=DB::table(self::GUARDS)->where('key','source:'.$row->source_product_id)->where('run_id',$id)->where(fn($q)=>$q->whereNull('owner')->orWhere('expires_at','<=',now()))->update(['owner'=>$owner,'expires_at'=>now()->addMinutes(5)]);
        if(!$claimed)return $this->show($id);
        $state=[];$held=false;
        try {
            $row=$this->row($id);
            $state=json_decode($row->state,true,512,JSON_THROW_ON_ERROR);
            if(!in_array($this->status($row),[...self::RUNNING,'under_review','submitted_unverified'],true))return $this->snapshot($row);
            if($this->delay($state)>0)return $this->show($id);
            unset($state['coordination_retry_at']);
            try{$this->lease->acquire();$held=true;}catch(MarketplaceOperationBusy){$state['coordination_retry_at']=now()->addSeconds(5)->getTimestampMs();$this->save($id,$this->status($row),$state,'Menunggu proses marketplace lain selesai. Progres perbaikan tersimpan.');return $this->show($id);}
            if(isset($state['target_shop_id']))$this->gateway->assertShop($state['target_shop_id']);
            if($row->attempted_at)$this->readback($row,$state);
            elseif($row->status==='preparing')$this->prepare($row,$state);
            elseif($row->status==='scanning')$this->scan($row,$state);
            elseif($row->status==='uploading')$this->upload($row,$state);
            elseif($row->status==='submitting')$this->submit($row,$state);
        }catch(StockHubTiktokProductRejected $e){$fresh=$this->row($id);if($fresh->attempted_at)$state=json_decode($fresh->state,true,512,JSON_THROW_ON_ERROR);$this->save($id,'rejected',$state,$e->getMessage());}
        catch(\Throwable $e){$fresh=$this->row($id);$attempted=$fresh->attempted_at!==null;if($attempted)$state=json_decode($fresh->state,true,512,JSON_THROW_ON_ERROR);$this->save($id,($state['invalid_audit']??false)?'blocked':($attempted?'submitted_unverified':'blocked'),$state,
            $e instanceof \DomainException?$e->getMessage():($attempted?'Hasil perbaikan belum pasti. Periksa Status; jangan kirim ulang.':'Pemeriksaan perbaikan gagal. Periksa akun, token, koneksi dan katalog.'));}
        finally{if($held)$this->lease->release();DB::table(self::GUARDS)->where('key','source:'.$row->source_product_id)->where('owner',$owner)->update(['owner'=>null,'expires_at'=>null]);}
        return $this->show($id);
    }
    private function prepare(object $row,array $state): void
    {
        $target=$this->gateway->targetId($row->source_product_id);
        $this->require($row->target_product_id===$target,'Hubungan produk TikTok berubah sejak perbaikan dimulai. Periksa ulang katalog.');
        $audit=DB::transaction(function()use($row,$target){$this->coordinationGuard('target:'.$target);$audit=$this->auditForTarget($row->source_product_id,$target);$guard=$this->guard('target:'.$target);$this->assertTargetFree($guard,$row->id);StockHubTiktokVariantInterlock::assertAdditionAvailable($row->source_product_id,$target);DB::table(self::GUARDS)->where('key','target:'.$target)->update(['run_id'=>$row->id]);DB::table(self::TABLE)->where('id',$row->id)->update(['target_product_id'=>$target]);return $audit;});
        // A prior operator audit always wins over starting a second edit.
        if($audit){$state=[...$state,...$this->auditState($audit)];DB::table(self::TABLE)->where('id',$row->id)->update(['attempted_at'=>now(),'accepted_at'=>in_array($audit->status,['accepted','under_review','verified'],true)?now():null]);$this->save($row->id,$this->auditStatus($audit),$state,'Memulihkan perbaikan tersimpan; perubahan tidak dikirim ulang.');return;}
        $state['target_shop_id']=$this->gateway->shop();$state['package_template']=$this->packages->snapshot();
        $data=$this->gateway->read($row->source_product_id,$target,$state['package_template'],$state['allow_inactive_source']??false);$this->gateway->assertShop($state['target_shop_id']);
        StockHubTiktokVariantInterlock::assertNoUnresolvedLegacy($target,$data['source'],$data['before']);
        $state=[...$state,...$data];$state['target_product_id']=$target;$state['fingerprint']=$this->fingerprint($data);$state['scan_revision']=$this->revision->current();
        $state['result']=$this->result($data['plan']);
        if(!$data['plan']['changed']&&!$data['plan']['deleted']&&$this->gateway->sameWritable($data['before'],$data['review'])){$this->save($row->id,'exists',$state,'Identitas varian sudah mengikuti Agni.');return;}
        $state['scan']=['cursor'=>null,'cursors'=>[],'seen'=>[],'queue'=>[],'complete'=>false,'total'=>null];$this->save($row->id,'scanning',$state,'Memeriksa kepemilikan SKU pada seluruh katalog TikTok.');
    }
    private function scan(object $row,array $state): void
    {
        $scan=&$state['scan'];
        if($scan['queue']){
            $id=array_shift($scan['queue']);$target=$this->gateway->catalog($id);
            if($id!==$row->target_product_id)foreach($target['skus'] as $sku)$this->require(!in_array(trim($sku['seller_sku']),array_column($state['source']['variants'],'seller_sku'),true)&&!preg_match('/^INT-'.preg_quote($row->source_product_id,'/').'-/i',trim($sku['seller_sku'])),'SKU Agni dimiliki produk TikTok lain. Perbaiki relasi katalog.');
            $state['progress']['scanned_products']++;
        }elseif(!$scan['complete']){
            $page=$this->gateway->page($scan['cursor']);
            $this->require(!array_intersect($scan['seen'],$page['ids'])&&($page['next']===null||(!in_array($page['next'],$scan['cursors'],true)&&$page['next']!==$scan['cursor']&&$page['ids'])),'Paginasi katalog TikTok tidak lengkap atau berulang.');
            if($page['total']!==null){$this->require(ctype_digit((string)$page['total'])&&($scan['total']===null||(string)$scan['total']===(string)$page['total']),'Jumlah katalog berubah.');$scan['total']=$page['total'];}
            $scan['seen']=[...$scan['seen'],...$page['ids']];$scan['queue']=$page['ids'];$scan['cursor']=$page['next'];$scan['cursors'][]=$page['next'];$scan['complete']=$page['next']===null;
            $this->require(count($scan['seen'])<=10000&&(!$scan['complete']||$scan['total']===null||count($scan['seen'])===(int)$scan['total']),'Katalog TikTok belum lengkap.');
        }
        if($scan['complete']&&!$scan['queue']){
            $this->require(in_array($row->target_product_id,$scan['seen'],true),'Produk tujuan tidak ada dalam katalog terbaru.');
            // Only renamed values require a new image; SKU-only corrections retain their image contract.
            $state['images']=array_values(array_filter($state['plan']['changed'],fn($c)=>$c['name']!==$c['old_name']));$state['progress']['total_images']=count($state['images']);
            $this->save($row->id,$state['images']?'uploading':'submitting',$state,'Menyiapkan gambar perubahan dan pemeriksaan terakhir.');
        }else $this->save($row->id,'scanning',$state,'Memeriksa kepemilikan SKU katalog TikTok.');
    }
    private function upload(object $row,array $state): void
    {
        $change=$state['images'][$state['progress']['uploaded_images']];$variant=$change['source_variant'];$url=$state['source']['main_images'][0]??'';
        foreach($variant['attributes'] as $a)if(isset($a['image_url'])){$url=$a['image_url'];break;}
        $state['uris'][$change['id']]=$this->gateway->upload([...$variant,'image_url'=>$url]);$state['image_urls'][$change['id']]=$url;$state['progress']['uploaded_images']++;
        $this->save($row->id,$state['progress']['uploaded_images']===$state['progress']['total_images']?'submitting':'uploading',$state,'Gambar perubahan siap; memeriksa ulang sumber dan tujuan.');
    }
    private function submit(object $row,array $state): void
    {
        StockHubTiktokVariantInterlock::assertAdditionAvailable($row->source_product_id,$row->target_product_id);
        $this->require($this->revision->current()===$state['scan_revision'],'Katalog berubah selama persiapan. Jalankan pemeriksaan baru.');
        $this->assertNoTargetAudit($row);
        $fresh=$this->gateway->read($row->source_product_id,$row->target_product_id,$state['package_template'],$state['allow_inactive_source']??false);
        $this->require($this->fingerprint($fresh)===$state['fingerprint'],'Sumber atau tujuan berubah selama persiapan. Periksa ulang perbaikan.');
        StockHubTiktokVariantInterlock::assertNoUnresolvedLegacy($row->target_product_id,$fresh['source'],$fresh['before']);
        $plan=$fresh['plan'];
        foreach($plan['payload']['skus'] as &$r)if(isset($state['uris'][$r['id']])){
            $imageIndex=0;$change=array_values(array_filter($plan['changed'],fn($c)=>$c['id']===$r['id']))[0];
            foreach($change['source_variant']['attributes'] as $a)if(isset($a['image_url'])){foreach($r['sales_attributes'] as $i=>$attr)if(mb_strtolower(trim($attr['name']))===mb_strtolower(trim($a['name'])))$imageIndex=$i;break;}
            $r['sales_attributes'][$imageIndex]['sku_img']=['uri'=>$state['uris'][$r['id']]];
        }unset($r);
        foreach($plan['changed'] as &$c)if(isset($state['image_urls'][$c['id']]))$c['image_url']=$state['image_urls'][$c['id']];unset($c);
        // Include the frozen product package contract even if it did not require filling.
        foreach(['package_weight','package_dimensions'] as $field)$plan['payload'][$field]=$plan['payload'][$field]??$fresh['before'][$field]??null;
        foreach(['package_weight','package_dimensions'] as $field)if($plan['payload'][$field]===null)unset($plan['payload'][$field]);
        $state['plan']=$plan;$this->lease->renew();$send=$this->gateway->edit($row->target_product_id,$state['target_shop_id']);
        $this->revision->beforeLegacyMutation();
        $saved=DB::transaction(function()use($row,$state){
            $this->coordinationGuard('target:'.$row->target_product_id);
            $this->assertNoTargetAudit($row);
            return DB::table(self::TABLE)->where('id',$row->id)->where('status','submitting')->whereNull('attempted_at')->whereNull('accepted_at')->update(['state'=>json_encode($state,JSON_THROW_ON_ERROR),'attempted_at'=>now(),'updated_at'=>now()]);
        });
        $this->require($saved===1,'Percobaan perbaikan sudah tercatat. Periksa Status.');
        $this->assertNoTargetAudit($row);
        $send($plan['payload']);DB::table(self::TABLE)->where('id',$row->id)->update(['accepted_at'=>now()]);$this->save($row->id,'verifying',$state,'Perbaikan diterima; memeriksa kedua versi TikTok.');
    }
    private function readback(object $row,array $state): void
    {
        $this->assertTargetAuditOwned($row,$state);
        $plan=$state['plan']??null;
        if(!is_array($plan)||!is_array($state['source']??null)||!is_array($plan['payload']['skus']??null)||!is_array($plan['changed']??null)||!is_array($plan['deleted']??null)
            ||(isset($state['audit_item_id'])&&!$this->gateway->validAudit($state['source'],$state['before']??[],$plan,$row->source_product_id,$row->target_product_id))){
            $state['invalid_audit']=true;$this->save($row->id,'blocked',$state,'Audit perbaikan lama belum lengkap. Periksa Seller Center; perubahan tidak dikirim ulang.');return;
        }
        $state['target_shop_id']??=$this->gateway->shop();$this->gateway->assertShop($state['target_shop_id']);
        $result=$this->gateway->verify($row->target_product_id,$plan,$state['before']??[]);$this->gateway->assertShop($state['target_shop_id']);$state['result']=$this->result($plan);
        if(in_array($result['status'],['success','under_review'],true))DB::table(self::TABLE)->where('id',$row->id)->update(['accepted_at'=>$row->accepted_at??now()]);
        if($result['status']==='success'){
            // Read again immediately before local reconciliation; neither version may drift.
            $final=$this->gateway->verify($row->target_product_id,$plan,$state['before']??[]);$this->gateway->assertShop($state['target_shop_id']);
            $this->require($final['status']==='success','Hasil TikTok berubah pada pemeriksaan terakhir. Periksa Status tanpa mengirim ulang.');
            $source=$this->gateway->assertFreshRelation($row->source_product_id,$row->target_product_id,$state['source'],$plan,$state['allow_inactive_source']??false);
            $this->gateway->assertOwnership($source,[$final['live'],$final['review']]);
            DB::transaction(function()use($row,$state,$plan){$this->coordinationGuard('target:'.$row->target_product_id);$this->assertTargetAuditOwned($row,$state);$this->gateway->cache($row->target_product_id,$plan);
                if(isset($state['audit_item_id'])){$evidence=json_decode(DB::table('tiktok_reconciliation_run_items')->where('id',$state['audit_item_id'])->value('result')??'',true);$evidence=is_array($evidence)?$evidence:[];$evidence['repair_verification']=['run_id'=>$row->id,'status'=>'verified'];DB::table('tiktok_reconciliation_run_items')->where('id',$state['audit_item_id'])->update(['status'=>'verified','result'=>json_encode($evidence),'updated_at'=>now()]);DB::table('tiktok_reconciliation_runs')->where('id',$state['audit_run_id'])->update(['status'=>'completed','completed_at'=>now(),'updated_at'=>now()]);}
                StockHubTiktokVariantInterlock::finishLegacy($state['legacy_attempt_id']??null,'resolved');$this->save($row->id,'success',$state,'Identitas varian TikTok terverifikasi mengikuti Agni.');
            });
        }else $this->save($row->id,$result['status'],$state,$result['status']==='under_review'?'Perbaikan terlihat pada versi pemeriksaan. Tunggu persetujuan TikTok lalu Periksa Status.':'Kedua versi TikTok belum cocok dengan perbaikan. Periksa Status; perubahan tidak dikirim ulang.');
    }
    private function auditForSource(string $source): ?object
    {
        if(!Schema::hasTable('tiktok_reconciliation_run_items'))return null;
        $q=DB::table('tiktok_reconciliation_run_items')->where('action_type','agni_source_reconcile')->whereNotIn('status',['rejected','verified']);
        foreach($q->orderByDesc('id')->get() as $item)if($this->auditSource($item)===$source)return $item;return null;
    }
    /** Unknown sources still reserve their target; only one matching audit may be recovered. */
    private function auditForTarget(string $source,string $target): ?object
    {
        if(!Schema::hasTable('tiktok_reconciliation_run_items'))return null;
        $items=DB::table('tiktok_reconciliation_run_items')->where('action_type','agni_source_reconcile')->where('target_product_id',$target)->whereNotIn('status',['rejected','verified'])->get();
        $this->require($items->count()<=1,'Produk TikTok memiliki beberapa audit perbaikan yang belum pasti. Periksa Seller Center; jangan kirim ulang.');
        $audit=$items->first();
        $this->require(!$audit||$this->auditSource($audit)===$source,'Sumber audit perbaikan TikTok belum pasti atau berbeda. Periksa Seller Center; jangan kirim ulang.');
        return $audit;
    }
    private function assertNoTargetAudit(object $row): void
    {
        $this->require(!$this->auditForTarget($row->source_product_id,$row->target_product_id),'Perbaikan sebelumnya telah tercatat. Muat status tersimpan.');
    }
    private function assertTargetAuditOwned(object $row,array $state): void
    {
        $audit=$this->auditForTarget($row->source_product_id,$row->target_product_id);
        $this->require(!$audit||($state['audit_item_id']??null)===$audit->id,'Audit perbaikan lain belum terverifikasi. Periksa Seller Center; perubahan tidak dikirim ulang.');
    }
    private function auditByRun(string $id): ?object { return DB::table('tiktok_reconciliation_run_items')->where('run_id',$id)->where('action_type','agni_source_reconcile')->where('status','<>','rejected')->orderByDesc('id')->first(); }
    private function auditSource(object $item): ?string
    {
        $s=json_decode($item->payload??'',true);$id=$s['source']['id']??null;
        $summary=json_decode(DB::table('tiktok_reconciliation_runs')->where('id',$item->run_id)->value('summary')??'',true);$summaryId=$summary['source_product_id']??null;
        $id=is_string($id)&&ctype_digit($id)?$id:null;$summaryId=is_string($summaryId)&&ctype_digit($summaryId)?$summaryId:null;
        if($id!==null&&$summaryId!==null&&$id!==$summaryId)return null;
        return $id??$summaryId;
    }
    private function auditState(object $item): array { $audit=json_decode($item->payload??'',true);return ['source'=>$audit['source']??null,'before'=>$audit['before']??null,'plan'=>$audit['plan']??null,'audit_item_id'=>$item->id,'audit_run_id'=>$item->run_id,'legacy_attempt_id'=>$audit['attempt_id']??null,'message'=>'Perbaikan operator sebelumnya tersimpan. Periksa Status; perubahan tidak dikirim ulang.']; }
    private function auditStatus(object $item): string { return $item->status==='verified'?'success':(in_array($item->status,['accepted','under_review'],true)?'under_review':'submitted_unverified'); }
    private function auditSnapshot(object $item): array { $state=$this->auditState($item);$state['result']=is_array($state['plan'])?$this->result($state['plan']):null;return $this->snapshot((object)['id'=>$item->run_id,'source_product_id'=>$this->auditSource($item),'target_product_id'=>$item->target_product_id,'status'=>$this->auditStatus($item),'state'=>json_encode($state),'attempted_at'=>$item->created_at,'accepted_at'=>$item->created_at]); }
    private function coordinationGuard(string $key): void { DB::table('stock_hub_tiktok_variant_guards')->insertOrIgnore(['key'=>$key]);DB::table('stock_hub_tiktok_variant_guards')->where('key',$key)->lockForUpdate()->first(); }
    private function guard(string $key): object { DB::table(self::GUARDS)->insertOrIgnore(['key'=>$key]);return DB::table(self::GUARDS)->where('key',$key)->lockForUpdate()->first(); }
    private function assertTargetFree(object $guard,string $id): void { $other=$guard->run_id?DB::table(self::TABLE)->where('id',$guard->run_id)->first():null;$this->require(!$other||$other->id===$id||$this->restartable($other),'Produk TikTok memiliki perbaikan tersimpan yang belum selesai.'); }
    private function row(string $id): object { $row=DB::table(self::TABLE)->where('id',$id)->first();abort_unless($row,404,'Proses perbaikan tidak ditemukan.');return $row; }
    private function status(object $r): string { $s=json_decode($r->state,true);if(($s['invalid_audit']??false)===true)return 'blocked';return $r->attempted_at&&!$r->accepted_at&&$r->status!=='rejected'?'submitted_unverified':$r->status; }
    private function restartable(object $r): bool { return in_array($r->status,['rejected','success','exists'],true)||($r->status==='blocked'&&!$r->attempted_at); }
    private function delay(array $s): int { return max(0,min(5000,(int)($s['coordination_retry_at']??0)-now()->getTimestampMs())); }
    private function result(array $plan): array { $safe=function($rows,$keys){if(!is_array($rows)||!array_is_list($rows))return [];return array_values(array_map(fn($c)=>array_intersect_key($c,array_flip($keys)),array_filter($rows,'is_array')));};return ['changed'=>$safe($plan['changed']??[],['name','old_name','seller_sku']),'deleted'=>$safe($plan['deleted']??[],['name','seller_sku']),'missing_count'=>is_int($plan['missing']??null)&&$plan['missing']>=0?$plan['missing']:0]; }
    private function fingerprint(array $data): string { return $this->gateway->revision($data); }
    private function require(bool $ok,string $message): void { if(!$ok)throw new \DomainException($message); }
    private function save(string $id,string $status,array $state,string $message): void
    {
        DB::transaction(function()use($id,$status,$state,$message){
            $current=DB::table(self::TABLE)->where('id',$id)->lockForUpdate()->first();
            if(json_decode($current->state,true)['batch_retired_before_attempt']??false)return;
            $state['message']=$message;DB::table(self::TABLE)->where('id',$id)->update(['status'=>$status,'state'=>json_encode($state,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
        });
    }
    private function snapshot(object $r): array { $s=json_decode($r->state,true,512,JSON_THROW_ON_ERROR);$status=$this->status($r);return ['run_id'=>$r->id,'source_product_id'=>$r->source_product_id,'remote_product_id'=>$r->target_product_id,'status'=>$status,'message'=>$s['message']??'','can_continue'=>in_array($status,self::RUNNING,true),'can_retry'=>$this->restartable($r),'progress'=>$s['progress']??['scanned_products'=>0,'uploaded_images'=>0,'total_images'=>0],'result'=>$s['result']??null,'next_step_after_ms'=>$this->delay($s)]; }
}
