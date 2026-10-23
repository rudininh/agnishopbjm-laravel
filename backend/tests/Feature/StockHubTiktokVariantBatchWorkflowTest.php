<?php

namespace Tests\Feature;

use App\Services\{StockHubAgniSkuService, StockHubTiktokVariantRepairService, StockHubTiktokVariantService, StockHubTiktokVariantBatchSelection};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class StockHubTiktokVariantBatchWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private string $base='/api/stock-hub/tiktok-variant-batches/runs';
    private array $events=[];
    private array $runs=[];
    private array $pending=[];
    private array $sources=['10','20'];
    private array $states=['missing_variants','complete'];
    private bool $loseStart=false;
    private bool $collision=false;
    private bool $sourceDrift=false;

    protected function setUp(): void
    {
        parent::setUp();Http::preventStrayRequests();
        $selection=Mockery::mock(StockHubTiktokVariantBatchSelection::class);
        $selection->shouldReceive('assertReady')->andReturnNull();
        $selection->shouldReceive('forProducts')->andReturnUsing(fn()=>array_map(fn($id,$state)=>['source_product_id'=>$id,'name'=>'Product '.$id,'coverage'=>['state'=>$state]],$this->sources,$this->states));
        $this->app->instance(StockHubTiktokVariantBatchSelection::class,$selection);
        foreach(['source_sku'=>StockHubAgniSkuService::class,'repair'=>StockHubTiktokVariantRepairService::class,'addition'=>StockHubTiktokVariantService::class] as $kind=>$class) {
            $mock=Mockery::mock($class);
            if($kind==='source_sku')$mock->shouldReceive('assertVerified')->andReturnUsing(function($id,$source){if($this->sourceDrift&&$source==='10')throw new \DomainException('source drift');});
            $mock->shouldReceive('source')->andReturnUsing(function($source)use($kind){$run=collect($this->runs[$kind]??[])->last(fn($r)=>$r['source_product_id']===$source);return $run?$this->savedChild($kind,$run['run_id']):null;});
            $mock->shouldReceive('show')->andReturnUsing(fn($id)=>$this->savedChild($kind,$id));
            if($kind==='addition')$mock->shouldReceive('startRemaining')->andReturnUsing(function($source,$prior,$key,$context)use($mock){$this->assertSame('partial',$this->savedChild('addition',$prior)['status']);$this->events[]=$source.':addition:remaining';return $mock->start($source,$key,$context);});
            $mock->shouldReceive('start')->andReturnUsing(function($source,$key)use($kind) {
                if($this->collision&&$kind==='source_sku'&&$source==='10')throw new \DomainException('private domain diagnostic');
                $column=match($kind){'source_sku'=>'source_sku_request_key','repair'=>'repair_request_key',default=>'request_key'};
                $this->assertTrue(DB::table('stock_hub_tiktok_variant_batch_items')->where('source_product_id',$source)->where($column,$key)->exists(),'child request identity must be durable before start');
                $existing=collect($this->runs[$kind]??[])->firstWhere('key',$key); if($existing)return $existing;
                $run=$this->seedChild($kind,$source,'preparing',$key);$this->events[]=$source.':'.$kind.':start';
                if($this->loseStart){$this->loseStart=false;throw new \RuntimeException('private diagnostic');}return $run;
            });
            $mock->shouldReceive('step')->andReturnUsing(function($id)use($kind) {
                $run=$this->runs[$kind][$id];$this->events[]=$run['source_product_id'].':'.$kind.':step';
                $run['status']=$this->pending[$kind.':'.$run['source_product_id']]??'success';$run['can_continue']=false;
                $run['can_retry']=in_array($run['status'],['blocked','rejected','success'],true);
                $run['result']=match($kind){'source_sku'=>['changed'=>[['name'=>'Rose','old_sku'=>'RED','seller_sku'=>'INT-'.$run['source_product_id'].'-ROSE','status'=>$run['status']==='success'?'verified':'submitted_unverified']]],'repair'=>['changed'=>[['name'=>'Rose','seller_sku'=>'NEW']],'deleted'=>[['name'=>'Extra','seller_sku'=>'OLD']]],default=>['variants'=>$run['status']==='partial'?[['status'=>'updated'],['status'=>'failed']]:[['status'=>$run['status']==='success'?'updated':'under_review']]]};
                $this->persist($kind,$run);return $this->runs[$kind][$id]=$run;
            });$this->app->instance($class,$mock);
        }
    }
    private function savedChild(string $kind,string $id):array
    {
        $run=$this->runs[$kind][$id];$row=DB::table($this->table($kind))->where('id',$id)->first();
        if(json_decode($row->state,true)['batch_retired_before_attempt']??false){$run['status']='blocked';$run['can_continue']=false;$run['can_retry']=true;}
        return $run;
    }
    private function table(string $kind):string { return match($kind){'source_sku'=>'stock_hub_agni_sku_runs','repair'=>'stock_hub_tiktok_variant_repair_runs',default=>'stock_hub_tiktok_variant_runs'}; }
    private function persist(string $kind,array $run):void {
        DB::table($this->table($kind))->where('id',$run['run_id'])->update(['status'=>$run['status'],'state'=>json_encode(['message'=>$run['message'],'result'=>$run['result']])]);
        if($kind==='source_sku')DB::table('stock_hub_agni_sku_attempts')->updateOrInsert(['run_id'=>$run['run_id'],'model_id'=>'101'],['name'=>'Rose','old_sku'=>'RED','seller_sku'=>'NEW','status'=>'verified','attempted_at'=>now(),'verified_at'=>$run['status']==='success'?now():null,'created_at'=>now(),'updated_at'=>now()]);
    }
    private function seedChild(string $kind,string $source,string $status,?string $key=null):array
    {
        $id=(string)Str::uuid();$key??=(string)Str::uuid();
        $run=['run_id'=>$id,'key'=>$key,'source_product_id'=>$source,'remote_product_id'=>'900'.$source,'status'=>$status,'can_continue'=>in_array($status,['preparing','scanning','uploading','submitting','verifying'],true),'can_retry'=>false,'message'=>'Saved','result'=>null];
        $row=['id'=>$id,'request_key'=>$key,'source_product_id'=>$source,'target_product_id'=>'900'.$source,'status'=>$status,'state'=>json_encode(['message'=>'Saved']),'created_at'=>now(),'updated_at'=>now()];
        if($kind!=='source_sku')$row['request_hash']=hash('sha256',$source);
        if($kind!=='source_sku'&&in_array($status,['submitted_unverified','under_review','verifying'],true))$row['attempted_at']=now();
        DB::table($this->table($kind))->insert($row);return $this->runs[$kind][$id]=$run;
    }
    private function start(bool $workflow=true):array { return $this->postJson($this->base,['request_key'=>(string)Str::uuid(),...($workflow?['workflow'=>'agni_sku_repair_then_tiktok']:[])])->assertOk()->json('data'); }
    private function step(array $run):array { return $this->postJson($this->base.'/'.$run['run_id'].'/step')->assertOk()->json('data'); }
    private function finish(array $run):array { for($i=0;$i<50&&$run['can_continue'];$i++)$run=$this->step($run);return $run; }
    private function resume(array $run,bool $workflow=true):array { return $this->postJson($this->base.'/'.$run['run_id'].'/resume',$workflow?['workflow'=>'agni_sku_repair_then_tiktok']:[])->assertOk()->json('data'); }

    public function test_explicit_product_recovery_replans_reconciled_source_child_when_catalog_resumes():void
    {
        $this->pending['source_sku:10']='submitted_unverified';
        $run=$this->finish($this->start());
        $old=collect($this->runs['source_sku'])->firstWhere('source_product_id','10');
        $old['status']='reconciled';$old['can_retry']=true;$old['can_continue']=false;
        $this->runs['source_sku'][$old['run_id']]=$old;$this->persist('source_sku',$old);
        unset($this->pending['source_sku:10']);
        $run=$this->finish($this->resume($run));
        $this->assertSame('completed',$run['status']);
        $this->assertNotSame($old['run_id'],$run['items'][0]['source_sku_run_id']);
        $this->assertSame(2,count(array_filter($this->events,fn($e)=>$e==='10:source_sku:start')));
        $this->assertDatabaseHas('stock_hub_agni_sku_runs',['id'=>$old['run_id'],'status'=>'reconciled']);
    }

    public function test_three_stage_ordering_including_cached_complete_and_verified_only_counts():void
    {
        $run=$this->finish($this->start());$this->assertSame('completed',$run['status']);
        $this->assertSame(['10:source_sku:start','10:source_sku:step','10:repair:start','10:repair:step','10:addition:start','10:addition:step','20:source_sku:start','20:source_sku:step','20:repair:start','20:repair:step','20:addition:start','20:addition:step'],$this->events);
        $this->assertSame(2,$run['summary']['agni_skus_verified']);$this->assertSame(2,$run['summary']['tiktok_variants_repaired']);$this->assertSame(2,$run['summary']['tiktok_variants_removed']);
        $this->assertSame(['completed','completed'],array_column($run['items'],'workflow_stage'));Http::assertNothingSent();
    }

    public function test_get_exposes_only_saved_current_stage_progress_without_stepping_or_writing():void
    {
        $run=$this->start();
        foreach(['repair','addition'] as $kind) {
            $child=$this->seedChild($kind,'10','scanning');
            DB::table($this->table($kind))->where('id',$child['run_id'])->update(['state'=>json_encode([
                'message'=>'Checking catalog','progress'=>['scanned_products'=>7,'uploaded_images'=>2,'total_images'=>5,'private'=>'hidden'],
                'scan'=>['total'=>'68','cursor'=>'must-not-leak'],
            ])]);
            $column=$kind==='repair'?'repair_run_id':'child_run_id';
            DB::table('stock_hub_tiktok_variant_batch_items')->where('batch_id',$run['run_id'])->where('source_product_id','10')->update([
                'workflow_stage'=>$kind,$column=>$child['run_id'],'processed'=>DB::raw('false'),
            ]);
            $before=DB::table($this->table($kind))->where('id',$child['run_id'])->first();$events=$this->events;
            $shown=$this->getJson($this->base.'/'.$run['run_id'])->assertOk()->json('data.items.0');
            $this->assertSame(['scanned_products'=>7,'total_products'=>68,'uploaded_images'=>2,'total_images'=>5],$shown['progress']);
            $this->assertNotEmpty($shown['updated_at']);
            $this->assertSame($events,$this->events);
            $this->assertEquals($before,DB::table($this->table($kind))->where('id',$child['run_id'])->first());
            DB::table($this->table($kind))->where('id',$child['run_id'])->update(['state'=>json_encode([
                'progress'=>['scanned_products'=>-1,'uploaded_images'=>'unsafe','total_images'=>10001],
                'scan'=>['total'=>'not-a-count'],
            ])]);
            $safe=$this->getJson($this->base.'/'.$run['run_id'])->assertOk()->json('data.items.0.progress');
            $this->assertSame(['scanned_products'=>0,'total_products'=>null,'uploaded_images'=>0,'total_images'=>0],$safe);
            DB::table($this->table($kind))->where('id',$child['run_id'])->update(['source_product_id'=>'999']);
            $isolated=$this->getJson($this->base.'/'.$run['run_id'])->assertOk()->json('data.items.0');
            $this->assertNull($isolated['progress']);
        }
        Http::assertNothingSent();
    }
    public function test_unattempted_legacy_submitting_children_are_retired_before_source_and_repair():void
    {
        foreach(['addition','repair'] as $kind)$old[$kind]=$this->seedChild($kind,'10','submitting');
        $run=$this->start();$run=$this->step($run);$this->assertSame($old['addition']['run_id'],$run['items'][0]['child_run_id']);
        while($run['items'][0]['workflow_stage']!=='recover_addition')$run=$this->step($run);
        $run=$this->step($run);
        $this->assertSame('blocked',DB::table($this->table('addition'))->where('id',$old['addition']['run_id'])->value('status'));
        $this->assertSame($old['addition']['run_id'],$run['items'][0]['child_run_id']);$this->assertSame([],$this->events);
        $run=$this->finish($run);
        $this->assertSame(['10:source_sku:start','10:source_sku:step','10:repair:start','10:repair:step','10:addition:start','10:addition:step'],array_values(array_filter($this->events,fn($e)=>str_starts_with($e,'10:'))));
        foreach($old as $kind=>$child){$row=DB::table($this->table($kind))->where('id',$child['run_id'])->first();$this->assertNull($row->attempted_at);$this->assertNull($row->accepted_at);$this->assertTrue(json_decode($row->state,true)['batch_retired_before_attempt']);}
        Http::assertNothingSent();
    }
    public function test_partial_addition_remains_attention_and_explicit_resume_preserves_history():void
    {
        $this->pending['addition:10']='partial';$run=$this->finish($this->start());$prior=$run['items'][0]['child_run_id'];
        $this->assertSame('addition',$run['items'][0]['workflow_stage']);$this->assertTrue($run['can_resume_attention']);$this->assertSame(1,$run['summary']['products_need_attention']);$this->assertSame(1,$run['summary']['variants_failed']);
        unset($this->pending['addition:10']);$resumed=$this->resume($run);$this->assertSame($prior,$resumed['items'][0]['child_run_id']);
        $run=$this->finish($resumed);$this->assertContains('10:addition:remaining',$this->events);$this->assertNotSame($prior,$run['items'][0]['child_run_id']);
        $this->assertSame('partial',DB::table($this->table('addition'))->where('id',$prior)->value('status'));$this->assertFalse($run['can_resume_attention']);
    }
    public function test_pending_repair_yields_other_products_and_explicit_completed_attention_resume_checks_same_attempt():void
    {
        $this->pending['repair:10']='under_review';$run=$this->finish($this->start());
        $this->assertTrue($run['can_resume_attention']);$this->assertSame('completed',$run['status']);$this->assertSame(1,$run['summary']['tiktok_variants_removed']);
        $this->assertSame(1,$run['summary']['products_waiting_review']);$id=$run['items'][0]['repair_run_id'];
        $before=$this->events;$this->getJson($this->base.'/'.$run['run_id'])->assertOk();$this->assertSame($before,$this->events);
        unset($this->pending['repair:10']);$run=$this->finish($this->resume($run));$this->assertSame('success',$run['items'][0]['status']);
        $this->assertSame($id,$run['items'][0]['repair_run_id']);$this->assertSame(1,count(array_filter($this->events,fn($e)=>$e==='10:repair:start')));
    }
    public function test_existing_pending_addition_is_adopted_before_source_and_resolved_addition_does_not_skip_stages():void
    {
        $old=$this->seedChild('addition','10','submitted_unverified');$this->pending['addition:10']='submitted_unverified';$run=$this->finish($this->start());
        $this->assertSame($old['run_id'],$run['items'][0]['child_run_id']);$this->assertNotContains('10:source_sku:start',$this->events);
        unset($this->pending['addition:10']);$run=$this->finish($this->resume($run));
        $this->assertContains('10:addition:step',$this->events);$this->assertContains('10:source_sku:start',$this->events);$this->assertContains('10:repair:start',$this->events);
        $this->assertSame('success',$run['items'][0]['status']);
    }
    public function test_explicit_upgrade_revisits_legacy_complete_and_preserves_old_uncertain_child():void
    {
        $this->pending['addition:10']='submitted_unverified';$run=$this->finish($this->start(false));$id=$run['items'][0]['child_run_id'];
        $this->assertSame('legacy',$run['workflow']);$this->assertSame('legacy',$this->resume($run,false)['workflow']);
        $run=$this->resume($run);$this->assertSame('agni_sku_repair_then_tiktok',$run['workflow']);$this->assertSame($id,$run['items'][0]['child_run_id']);
        $run=$this->finish($run);$this->assertSame($id,$run['items'][0]['child_run_id']);$this->assertContains('20:source_sku:start',$this->events);
    }
    public function test_lost_child_start_response_recovers_saved_key_without_a_new_run():void
    {
        $this->loseStart=true;$run=$this->step($this->start());$run=$this->step($run);$this->assertSame('paused_error',$run['status']);
        $run=$this->finish($this->resume($run));$this->assertSame('completed',$run['status']);
        $this->assertSame(1,count(array_filter($this->events,fn($e)=>$e==='10:source_sku:start')));
    }

    public static function targetKinds():array { return [['addition','child_run_id'],['repair','repair_run_id']]; }

    /** @dataProvider targetKinds */
    public function test_resolved_saved_child_does_not_hide_newer_uncertain_source_owner(string $kind,string $column):void
    {
        $run=$this->finish($this->start(false));
        $old=$kind==='addition'?$run['items'][0]['child_run_id']:$this->seedChild($kind,'10','success')['run_id'];
        DB::table('stock_hub_tiktok_variant_batch_items')->where('batch_id',$run['run_id'])->where('source_product_id','10')->update([$column=>$old]);
        $new=$this->seedChild($kind,'10','submitted_unverified');$this->pending[$kind.':10']='submitted_unverified';$this->events=[];
        $run=$this->step($this->resume($run));
        $this->assertSame('recover_'.$kind,$run['items'][0]['workflow_stage']);$this->assertSame($new['run_id'],$run['items'][0][$column]);
        $run=$this->finish($run);$this->assertNotContains('10:source_sku:start',$this->events);
        $this->assertSame('success',DB::table($this->table($kind))->where('id',$old)->value('status'));
        unset($this->pending[$kind.':10']);$run=$this->finish($this->resume($run));
        $this->assertSame('completed',$run['items'][0]['workflow_stage']);$this->assertContains('10:source_sku:start',$this->events);Http::assertNothingSent();
    }

    public static function pausedBoundaries():array
    {
        return [['source_sku','addition','child_run_id'],['source_sku','repair','repair_run_id'],['repair','addition','child_run_id'],['repair','repair','repair_run_id'],['addition','addition','child_run_id'],['addition','repair','repair_run_id']];
    }
    /** @dataProvider pausedBoundaries */
    public function test_pending_owner_started_during_pause_is_recovered_at_stage_boundary(string $stage,string $kind,string $column):void
    {
        $run=$this->start();for($i=0;$i<15&&$run['items'][0]['workflow_stage']!==$stage;$i++)$run=$this->step($run);
        $this->assertSame($stage,$run['items'][0]['workflow_stage']);
        $this->postJson($this->base.'/'.$run['run_id'].'/pause')->assertOk();
        $new=$this->seedChild($kind,'10','submitted_unverified');$this->pending[$kind.':10']='submitted_unverified';$before=$this->events;
        $run=$this->step($this->resume($run));
        $this->assertSame('recover_'.$kind,$run['items'][0]['workflow_stage']);$this->assertSame($new['run_id'],$run['items'][0][$column]);
        $this->assertSame($before,$this->events);$run=$this->finish($run);
        unset($this->pending[$kind.':10']);$run=$this->finish($this->resume($run));$this->assertSame('completed',$run['items'][0]['workflow_stage']);Http::assertNothingSent();
    }

    public function test_unresolved_saved_repair_has_priority_over_newer_source_addition_owner():void
    {
        $run=$this->start();$saved=$this->seedChild('repair','10','under_review');
        DB::table('stock_hub_tiktok_variant_batch_items')->where('batch_id',$run['run_id'])->where('source_product_id','10')->update(['repair_run_id'=>$saved['run_id']]);
        $this->seedChild('addition','10','submitted_unverified');$this->events=[];$run=$this->step($run);
        $this->assertSame('recover_repair',$run['items'][0]['workflow_stage']);$this->assertSame($saved['run_id'],$run['items'][0]['repair_run_id']);$this->assertSame([],$this->events);
    }

    public function test_newer_uncertain_addition_keeps_partial_ancestry_for_later_continuation():void
    {
        $this->pending['addition:10']='partial';$run=$this->finish($this->start(false));$prior=$run['items'][0]['child_run_id'];
        $new=$this->seedChild('addition','10','submitted_unverified');$this->runs['addition'][$new['run_id']]['context']=['prior_partial_run_id'=>$prior];
        $this->pending['addition:10']='submitted_unverified';$run=$this->finish($this->resume($run));
        $this->assertSame($new['run_id'],$run['items'][0]['child_run_id']);$this->assertSame('recover_addition',$run['items'][0]['workflow_stage']);
        $this->pending['addition:10']='rejected';$run=$this->finish($this->resume($run));
        $this->assertContains('10:addition:remaining',$this->events);$this->assertSame('partial',DB::table($this->table('addition'))->where('id',$prior)->value('status'));
        $this->assertSame($prior,$this->runs['addition'][$new['run_id']]['context']['prior_partial_run_id']);Http::assertNothingSent();
    }

    /** @dataProvider targetKinds */
    public function test_current_stage_uncertain_saved_identity_cannot_be_replaced_by_newer_owner(string $kind,string $column):void
    {
        $this->pending[$kind.':10']='under_review';$run=$this->finish($this->start());$saved=$run['items'][0][$column];
        $this->seedChild($kind,'10','submitted_unverified');$run=$this->step($this->resume($run));
        $this->assertSame($saved,$run['items'][0][$column]);$this->assertSame($kind,$run['items'][0]['workflow_stage']);Http::assertNothingSent();
    }

    public function test_fresh_catalog_after_permanent_attention_includes_new_sources_and_keeps_old_history():void
    {
        $this->states=['missing_product','complete'];$old=$this->finish($this->start());$this->assertTrue($old['can_resume_attention']);
        $oldChild=$old['items'][1]['child_run_id'];$this->sources[]='30';$this->states[]='missing_variants';
        $continued=$this->finish($this->resume($old));$this->assertSame(['10','20'],array_column($continued['items'],'source_product_id'));
        $fresh=$this->finish($this->start());$this->assertNotSame($old['run_id'],$fresh['run_id']);
        $this->assertSame(['10','20','30'],array_column($fresh['items'],'source_product_id'));$this->assertSame('completed',$fresh['items'][2]['workflow_stage']);
        $restored=$this->getJson($this->base.'/'.$old['run_id'])->assertOk()->json('data');$this->assertSame($oldChild,$restored['items'][1]['child_run_id']);Http::assertNothingSent();
    }

    public function test_product_local_start_blocker_is_attention_and_other_products_finish():void
    {
        $this->collision=true;$run=$this->finish($this->start());$this->assertSame('completed',$run['status']);
        $this->assertSame('blocked',$run['items'][0]['status']);$this->assertSame('success',$run['items'][1]['status']);
        $this->assertStringNotContainsString('private',json_encode($run));$this->assertTrue($run['can_resume_attention']);
    }

    public function test_source_drift_after_pause_blocks_new_repair_but_pending_readback_is_preserved():void
    {
        $run=$this->start();for($i=0;$i<10&&$run['items'][0]['workflow_stage']!=='repair';$i++)$run=$this->step($run);
        $this->postJson($this->base.'/'.$run['run_id'].'/pause')->assertOk();$this->sourceDrift=true;$run=$this->finish($this->resume($run));
        $this->assertSame('blocked',$run['items'][0]['status']);$this->assertNotContains('10:repair:start',$this->events);$this->assertSame('success',$run['items'][1]['status']);
        $this->sourceDrift=false;$this->pending['repair:10']='under_review';$run=$this->finish($this->resume($run));$id=$run['items'][0]['repair_run_id'];
        $this->sourceDrift=true;unset($this->pending['repair:10']);$run=$this->finish($this->resume($run));
        $this->assertSame($id,$run['items'][0]['repair_run_id']);$this->assertContains('10:repair:step',$this->events);$this->assertSame('blocked',$run['items'][0]['status']);
        $this->assertNotContains('10:addition:start',$this->events);
    }
}
