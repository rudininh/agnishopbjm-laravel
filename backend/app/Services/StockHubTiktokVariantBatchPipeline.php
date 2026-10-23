<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One child operation per claimed batch step. Saved uncertainty always precedes normalization. */
class StockHubTiktokVariantBatchPipeline
{
    public const WORKFLOW='agni_sku_repair_then_tiktok';
    private const ITEMS='stock_hub_tiktok_variant_batch_items';
    private const ACTIVE=['preparing','scanning','uploading','submitting','verifying','submitted_unverified','under_review'];
    public function __construct(private StockHubAgniSkuService $source,private StockHubTiktokVariantRepairService $repair,private StockHubTiktokVariantService $addition) {}

    public function step(object $item): void
    {
        $stage=$item->workflow_stage??'recover';
        if($stage==='recover') {
            if($this->recoverPending($item))return;
            $history=[];
            foreach(['addition','repair'] as $kind) {
                [$service,$idColumn,,,$resultColumn]=$this->contract($kind);
                $child=$item->$idColumn?$service->show($item->$idColumn):$service->source($item->source_product_id);
                if($kind==='addition'&&$child&&$child['status']==='partial'){$this->identity($item,$child);$history=['child_run_id'=>$child['run_id'],'result'=>isset($child['result'])?json_encode($child['result'],JSON_THROW_ON_ERROR):null];}
            }
            $this->save($item,[...$history,'request_key'=>(string)Str::uuid(),'repair_request_key'=>(string)Str::uuid(),
                'workflow_stage'=>'source_sku','status'=>'queued','message'=>'Memeriksa SKU sumber Agni.','processed'=>DB::raw('false')]);return;
        }
        if(str_starts_with($stage,'recover_')) {
            $kind=substr($stage,8); [$service,$idColumn,,,$resultColumn]=$this->contract($kind); $child=$service->show($item->$idColumn);$this->identity($item,$child);
            if($this->unresolved($child)) {
                $recovery=StockHubTiktokVariantInterlock::retireUnattempted($kind,$child['run_id'],(string)$item->source_product_id);
                if($recovery==='attempted')$child=$service->step($child['run_id']);
                elseif($recovery==='retired')$child=$service->show($child['run_id']);
                else {
                    $this->save($item,['status'=>'blocked','processed'=>DB::raw('true'),'retry_at'=>null,'message'=>$recovery==='busy'?'Persiapan lama masih dimiliki proses lain. Periksa dan Lanjutkan setelah proses selesai.':'Bukti pengiriman lama belum lengkap. Periksa hasil sebelum melanjutkan.']);return;
                }
            }
            $this->identity($item,$child);
            $this->save($item,[$resultColumn=>isset($child['result'])?json_encode($child['result'],JSON_THROW_ON_ERROR):null]);
            if(in_array($child['status'],['success','partial','exists','rejected'],true)||($child['status']==='blocked'&&($child['can_retry']??false))) {
                // Retain the old ID while recording a new future-stage key. A resolved pre-source edit cannot skip source/repair.
                $keyColumn=$kind==='addition'?'request_key':'repair_request_key';
                $this->save($item,[$keyColumn=>(string)Str::uuid(),'workflow_stage'=>'recover','status'=>'queued','processed'=>DB::raw('false'),'message'=>'Hasil lama telah diperiksa; memeriksa tahap sumber.']);
            } else $this->save($item,$this->childValues($child));
            return;
        }
        [$service,$idColumn,$keyColumn,$table,$resultColumn]=$this->contract($stage);
        $key=$item->$keyColumn;
        if(!$key){$key=(string)Str::uuid();$this->save($item,[$keyColumn=>$key]);}
        $saved=DB::table($table)->where('request_key',$key)->where('source_product_id',$item->source_product_id)->value('id');
        // A start response may be lost. Recover by the key saved before that call.
        $child=$saved?$service->show($saved):null;
        if($stage==='source_sku'&&($child['status']??null)==='reconciled') {
            $this->save($item,[$keyColumn=>(string)Str::uuid(),$idColumn=>null,'status'=>'queued','processed'=>DB::raw('false'),'retry_at'=>null,
                'message'=>'Kiriman SKU lama terverifikasi; menyiapkan nama Agni terbaru.']);return;
        }
        // A product operation may have started while the catalog was paused. The current
        // stage's own durable key can continue normally; all other uncertainty goes first.
        if($this->recoverPending($item,$stage==='source_sku'?null:($child['run_id']??null)))return;
        if(!$child&&$item->$idColumn) {
            $known=$service->show($item->$idColumn);
            if($this->unresolved($known))$child=$known;
        }
        if(in_array($stage,['repair','addition'],true)&&(!$child||in_array($child['status'],['preparing','scanning','uploading','submitting'],true))) {
            try {$this->source->assertVerified($item->source_sku_run_id,$item->source_product_id);}
            catch(\DomainException $e) {
                $this->save($item,['workflow_stage'=>'recover','source_sku_request_key'=>(string)Str::uuid()]);throw $e;
            }
        }
        if(!$child)$child=match($stage) {
            'repair'=>$service->start($item->source_product_id,$key,true),
            'addition'=>isset($known)&&($known['status']==='partial'||isset($known['context']['prior_partial_run_id']))
                ?$service->startRemaining($item->source_product_id,$known['status']==='partial'?$known['run_id']:$known['context']['prior_partial_run_id'],$key,['allow_inactive_source'=>true])
                :$service->start($item->source_product_id,$key,['allow_inactive_source'=>true]),
            default=>$service->start($item->source_product_id,$key),
        };
        elseif($this->unresolved($child))$child=$service->step($child['run_id']);
        $this->identity($item,$child);
        $values=[$idColumn=>$child['run_id'],$resultColumn=>isset($child['result'])?json_encode($child['result'],JSON_THROW_ON_ERROR):null,...$this->childValues($child)];
        $verified=in_array($child['status'],['success','exists'],true);
        if($verified) {
            $next=match($stage){'source_sku'=>'repair','repair'=>'addition',default=>'completed'};
            $values['workflow_stage']=$next;$values['processed']=DB::raw($next==='completed'?'true':'false');
            $values['status']=$next==='completed'?$child['status']:'queued'; $values['retry_at']=null;
        }
        $this->save($item,$values);
    }

    /** Explicit resume only. Restart safe blocks; preserve all attempted IDs/keys. */
    public function revisit(object $item): void
    {
        $stage=$item->workflow_stage??'recover';
        if($stage==='completed')return;
        if(in_array($item->status,['missing_product','unknown'],true)&&$stage==='recover')return;
        if(in_array($stage,['source_sku','repair','addition'],true)) {
            [$service,$idColumn,$keyColumn]=$this->contract($stage);
            $child=$item->$idColumn?$service->show($item->$idColumn):null;
            if($child&&(($stage==='addition'&&$child['status']==='partial')||(in_array($child['status'],['blocked','rejected','reconciled'],true)&&($child['can_retry']??false))))$this->save($item,[$keyColumn=>(string)Str::uuid()]);
        }
        $this->save($item,['processed'=>DB::raw('false'),'retry_at'=>null]);
    }
    private function contract(string $kind):array
    {
        return match($kind) {
            'source_sku'=>[$this->source,'source_sku_run_id','source_sku_request_key','stock_hub_agni_sku_runs','source_sku_result'],
            'repair'=>[$this->repair,'repair_run_id','repair_request_key','stock_hub_tiktok_variant_repair_runs','repair_result'],
            'addition'=>[$this->addition,'child_run_id','request_key','stock_hub_tiktok_variant_runs','result'],
        };
    }
    private function recoverPending(object $item,?string $currentId=null):bool
    {
        // Never replace a saved uncertain identity with a newer source owner, even of
        // the other child kind. Only after saved identities resolve inspect the owners.
        foreach(['saved','source'] as $origin)foreach(['addition','repair'] as $kind) {
            [$service,$idColumn,,,$resultColumn]=$this->contract($kind);
            $child=$origin==='saved'?($item->$idColumn?$service->show($item->$idColumn):null):$service->source($item->source_product_id);
            if(!$child)continue;
            $this->identity($item,$child);
            if(!$this->unresolved($child))continue;
            if($child['run_id']===$currentId)return false;
            $this->save($item,[$idColumn=>$child['run_id'],$resultColumn=>isset($child['result'])?json_encode($child['result'],JSON_THROW_ON_ERROR):null,
                'workflow_stage'=>'recover_'.$kind,...$this->childValues($child)]);return true;
        }
        return false;
    }
    private function unresolved(array $child):bool { return in_array($child['status'],self::ACTIVE,true)||(($child['status']??null)==='blocked'&&!($child['can_retry']??false)); }
    private function identity(object $item,array $child):void { if((string)($child['source_product_id']??'')!==(string)$item->source_product_id)throw new \DomainException('Identitas child berbeda dari sumber batch.'); }
    private function childValues(array $child):array
    {
        $running=($child['can_continue']??false)&&!in_array($child['status'],['under_review','submitted_unverified'],true);
        $delay=$child['next_step_after_ms']??0;$delay=is_int($delay)?max(0,min(5000,$delay)):0;
        return ['status'=>$child['status'],'message'=>mb_substr($child['message']??'',0,500),'processed'=>DB::raw($running?'false':'true'),'retry_at'=>$delay?now()->getTimestampMs()+$delay:null];
    }
    private function save(object $item,array $values):void { DB::table(self::ITEMS)->where('id',$item->id)->update([...$values,'updated_at'=>now()]); }
}
