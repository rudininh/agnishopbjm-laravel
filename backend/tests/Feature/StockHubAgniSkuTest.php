<?php

namespace Tests\Feature;

use App\Services\{MarketplaceStockMirrorLease, StockHubAgniSkuService, StockHubTiktokVariantRepairGateway};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class StockHubAgniSkuTest extends TestCase
{
    use RefreshDatabase;
    private array $models;
    private string $mode = '';
    private int $writes = 0;
    private string $shop = '11';
    private string $itemStatus = 'NORMAL';
    private bool $relationValid=true;
    private bool $targetPending=false;
    private string $targetShop='33';

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests();
        config(['marketplace_accounts.accounts.shopee-agnishopbjm' => ['enabled'=>true,'channel'=>'shopee','credentials'=>['partner_id'=>123,'partner_key'=>'test-secret','host'=>'https://source.test','redirect_url'=>'https://local.test']]]);
        DB::table('shopee_tokens')->insert(['account_key'=>'shopee-agnishopbjm','shop_id'=>11,'access_token'=>'test-token','is_active'=>true,'access_token_expire_at'=>now()->addHour()]);
        Schema::create('shopee_product', function(Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('shop_id'); });
        Schema::create('shopee_product_model', function(Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('model_id'); $t->string('model_name'); $t->string('name')->nullable(); $t->string('model_sku'); $t->timestamps(); });
        Schema::create('stock_master', function(Blueprint $t) { $t->id(); $t->string('shopee_product_id'); $t->string('shopee_sku'); $t->string('shopee_seller_sku'); $t->integer('stock'); $t->timestamps(); });
        DB::table('shopee_product')->insert(['item_id'=>'10','shop_id'=>'11']);
        $this->models=[['model_id'=>101,'model_name'=>'Rose','model_sku'=>'INT-10-RED'],['model_id'=>102,'model_name'=>'Blue','model_sku'=>'']];
        foreach($this->models as $m) DB::table('shopee_product_model')->insert(['item_id'=>'10','model_id'=>(string)$m['model_id'],'model_name'=>$m['model_name'],'model_sku'=>$m['model_sku']]);
        DB::table('stock_master')->insert(['shopee_product_id'=>'10','shopee_sku'=>'101','shopee_seller_sku'=>'INT-10-RED','stock'=>19]);
        $relation=Mockery::mock(StockHubTiktokVariantRepairGateway::class); $relation->shouldReceive('targetId')->andReturn('900'); $this->app->instance(StockHubTiktokVariantRepairGateway::class,$relation);
        $relation->shouldReceive('shop')->andReturnUsing(fn()=>$this->targetShop);$relation->shouldReceive('assertShop')->andReturnNull();
        $orphans=Mockery::mock(\App\Services\OrphanVariantGateway::class);
        $orphans->shouldReceive('snapshot')->andReturnUsing(fn()=>['account_key'=>'tiktok-agnishopbjm','source_account_key'=>'shopee-agnishopbjm','source_product_id'=>$this->relationValid?'10':'20','target_product_id'=>'900','source_complete'=>true,'target_complete'=>true,'mapping_conflicts'=>false,'source_variants'=>array_map(fn($m)=>['id'=>(string)$m['model_id'],'name'=>$m['model_name'],'seller_sku'=>$m['model_sku']],$this->models)]);$this->app->instance(\App\Services\OrphanVariantGateway::class,$orphans);
        $products=Mockery::mock(\App\Services\StockHubTiktokProductGateway::class);$products->shouldReceive('target')->andReturnUsing(fn()=>['id'=>'900','status'=>'ACTIVATE','audit'=>['status'=>$this->targetPending?'AUDITING':'APPROVED']]);$this->app->instance(\App\Services\StockHubTiktokProductGateway::class,$products);
        $lease=Mockery::mock(MarketplaceStockMirrorLease::class); foreach(['acquire','renew','release'] as $method)$lease->shouldReceive($method)->andReturnNull(); $this->app->instance(MarketplaceStockMirrorLease::class,$lease);
        Http::fake(function($r) {
            $path=parse_url($r->url(),PHP_URL_PATH);
            if($path==='/api/v2/product/get_item_base_info')return Http::response(['error'=>'','response'=>['item_list'=>[['item_id'=>10,'shop_id'=>$this->shop,'item_status'=>$this->itemStatus]]]]);
            if($path==='/api/v2/product/get_model_list')return Http::response(['error'=>'','response'=>['model'=>$this->models]]);
            if($path==='/api/v2/product/update_model') {
                $body=$r->data(); $model=$body['model'][0];
                $this->assertNotNull(DB::table('stock_hub_agni_sku_attempts')->where('model_id',(string)$model['model_id'])->orderByDesc('id')->value('attempted_at'));
                $this->assertSame(['item_id','model'],array_keys($body)); $this->assertSame(['model_id','model_sku'],array_keys($model));
                $this->writes++;
                if($this->mode==='rejected')return Http::response(['error'=>'error_param','message'=>'private diagnostic','response'=>[]]);
                if($this->mode!=='unobserved')foreach($this->models as &$m)if($m['model_id']==$model['model_id'])$m['model_sku']=$model['model_sku']; unset($m);
                if(in_array($this->mode,['timeout','unobserved'],true))return Http::failedConnection('signed-secret');
                if($this->mode==='unknown')return Http::response(['error'=>'','response'=>[]]);
                return Http::response(['error'=>'','response'=>['model'=>[$model]]]);
            }
            throw new \RuntimeException('unexpected HTTP');
        });
    }
    private function start(?string $key=null): array { return app(StockHubAgniSkuService::class)->start('10',$key??(string)Str::uuid()); }
    private function step(array $run): array { return app(StockHubAgniSkuService::class)->step($run['run_id']); }
    private function finish(array $run): array { for($i=0;$i<20&&$run['can_continue'];$i++)$run=$this->step($run);return $run; }

    public function test_sku_ownership_chain_moves_holder_first_without_duplicate_remote_skus(): void
    {
        $this->models=[['model_id'=>101,'model_name'=>'Rose','model_sku'=>'OLD'],['model_id'=>102,'model_name'=>'Blue','model_sku'=>'INT-10-ROSE']];
        $run=$this->step($this->start());
        $this->assertSame('submitting',$run['status']);
        $this->assertSame(['Blue','Rose'],array_column($run['result']['changed'],'name'));
        $run=$this->step($run);
        $this->assertSame('INT-10-BLUE',$this->models[1]['model_sku']);
        $this->assertSame('OLD',$this->models[0]['model_sku']);
        $run=$this->finish($run);$this->assertSame('success',$run['status']);$this->assertSame(2,$this->writes);
    }

    public function test_sku_ownership_cycle_blocks_before_any_write(): void
    {
        $this->models[0]['model_sku']='INT-10-BLUE';$this->models[1]['model_sku']='INT-10-ROSE';
        $run=$this->finish($this->start());$this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->writes);
    }

    public function test_explicit_name_drift_recovery_observes_old_attempt_before_replanning_and_retains_history(): void
    {
        $run=$this->step($this->step($this->start()));
        $this->models[0]['model_name']='Pink';
        $saved=app(StockHubAgniSkuService::class)->source('10');$this->assertSame($run['run_id'],$saved['run_id']);
        $this->assertSame('verifying',$saved['status']);$this->assertSame(1,$this->writes);
        $recovered=app(StockHubAgniSkuService::class)->recover($run['run_id']);
        $this->assertSame('reconciled',$recovered['status']);$this->assertTrue($recovered['can_retry']);
        $this->assertSame(1,$this->writes);
        $this->assertSame('verified',$recovered['result']['changed'][0]['status']);
        $this->assertSame('reconciled',$this->step($recovered)['status']);
        $next=$this->finish($this->start());$this->assertNotSame($run['run_id'],$next['run_id']);
        $this->assertSame('success',$next['status']);$this->assertSame(3,$this->writes);
        $this->assertDatabaseHas('stock_hub_agni_sku_attempts',['run_id'=>$run['run_id'],'model_id'=>'101','seller_sku'=>'INT-10-ROSE','status'=>'verified']);
        $this->assertSame(['INT-10-PINK','INT-10-BLUE'],array_column($this->models,'model_sku'));
    }

    public function test_explicit_recovery_cannot_release_unobserved_attempt_or_changed_target_account(): void
    {
        $this->mode='unobserved';$run=$this->step($this->step($this->start()));$this->models[0]['model_name']='Pink';
        $recovered=app(StockHubAgniSkuService::class)->recover($run['run_id']);
        $this->assertSame('submitted_unverified',$recovered['status']);$this->assertSame(1,$this->writes);
        $this->models[0]['model_sku']='INT-10-ROSE';$this->targetShop='44';
        $recovered=app(StockHubAgniSkuService::class)->recover($run['run_id']);
        $this->assertSame('submitted_unverified',$recovered['status']);
        $this->assertSame($run['run_id'],$this->start()['run_id']);$this->assertSame(1,$this->writes);
    }

    public function test_normalization_http_get_is_read_only_and_explicit_recovery_reconciles_saved_attempt(): void
    {
        $this->getJson('/api/stock-hub/agni-skus/source/10')->assertOk()->assertJsonPath('data',null);Http::assertNothingSent();
        $run=$this->step($this->step($this->start()));$this->models[0]['model_name']='Pink';
        $this->getJson('/api/stock-hub/agni-skus/source/10')->assertOk()->assertJsonPath('data.run_id',$run['run_id']);
        $this->postJson('/api/stock-hub/agni-skus/runs/'.$run['run_id'].'/recover',['target_product_id'=>'other'])->assertStatus(422);
        $this->postJson('/api/stock-hub/agni-skus/runs/'.$run['run_id'].'/recover')->assertOk()->assertJsonPath('data.status','reconciled');
        $this->assertSame(1,$this->writes);
    }

    public function test_recovery_reloads_snapshot_when_another_worker_finishes_preparation_before_ownership_claim(): void
    {
        $run=$this->start();$prepared=false;
        DB::listen(function($query)use($run,&$prepared):void {
            if($prepared||!str_starts_with($query->sql,'select')||!str_contains($query->sql,'stock_hub_agni_sku_runs')||!in_array($run['run_id'],$query->bindings,true))return;
            $prepared=true;
            $source=['id'=>'10','shop_id'=>'11','variants'=>[
                ['id'=>'101','name'=>'Rose','seller_sku'=>'INT-10-RED','expected_sku'=>'INT-10-ROSE'],
                ['id'=>'102','name'=>'Blue','seller_sku'=>'','expected_sku'=>'INT-10-BLUE']]];
            DB::table('stock_hub_agni_sku_runs')->where('id',$run['run_id'])->update(['status'=>'submitting','state'=>json_encode(['source'=>$source,'target_shop_id'=>'33'])]);
            foreach($source['variants'] as $m)DB::table('stock_hub_agni_sku_attempts')->insert(['run_id'=>$run['run_id'],'model_id'=>$m['id'],'name'=>$m['name'],'old_sku'=>$m['seller_sku'],'seller_sku'=>$m['expected_sku'],'status'=>'queued','created_at'=>now(),'updated_at'=>now()]);
        });
        $restored=app(StockHubAgniSkuService::class)->recover($run['run_id']);
        $this->assertTrue($prepared);$this->assertSame('submitting',$restored['status']);
        $this->assertSame('success',$this->finish($restored)['status']);$this->assertSame(2,$this->writes);
        $this->assertSame(2,DB::table('stock_hub_agni_sku_attempts')->where('run_id',$run['run_id'])->count());
    }

    public function test_normalizes_each_model_once_after_attempt_marker_then_caches_only_exact_readback(): void
    {
        $run=$this->start(); Http::assertNothingSent(); $run=$this->finish($run);
        $this->assertSame('success',$run['status']); $this->assertSame(2,$this->writes);
        $this->assertSame(['INT-10-ROSE','INT-10-BLUE'],DB::table('shopee_product_model')->orderBy('model_id')->pluck('model_sku')->all());
        $this->assertSame('INT-10-ROSE',DB::table('stock_master')->value('shopee_seller_sku')); $this->assertSame(19,DB::table('stock_master')->value('stock'));
        $this->assertSame(['verified','verified'],array_column($run['result']['changed'],'status'));
    }
    public static function invalidNames(): array { return [[''],['Blue'],[str_repeat('A',35)],['!!!']]; }
    /** @dataProvider invalidNames */
    public function test_all_names_and_generated_collisions_are_preflighted_before_any_write(string $name): void
    {
        $this->models[0]['model_name']=$name;
        if(strlen($name)>30)$this->models[1]['model_name']=str_repeat('A',34).'B';
        $run=$this->finish($this->start()); $this->assertSame('blocked',$run['status']); $this->assertSame(0,$this->writes);
    }
    public function test_wrong_shop_is_blocked_without_writes(): void
    {
        $this->shop='22'; $run=$this->finish($this->start()); $this->assertSame('blocked',$run['status']); $this->assertSame(0,$this->writes);
    }
    public function test_source_name_or_complete_model_list_drift_prevents_submission(): void
    {
        $run=$this->step($this->start()); $this->models[1]['model_name']='Changed'; $run=$this->step($run);
        $this->assertSame('blocked',$run['status']); $this->assertSame(0,$this->writes);
    }
    public function test_timeout_across_fresh_keys_only_reads_back_and_never_replays_attempt(): void
    {
        $this->mode='unobserved'; $run=$this->step($this->step($this->start()));
        $this->assertSame('submitted_unverified',$run['status']); $this->assertSame(1,$this->writes);
        $restored=$this->start(); $this->assertSame($run['run_id'],$restored['run_id']);
        $restored=$this->step($restored); $this->assertSame(1,$this->writes); $this->assertSame('submitted_unverified',$restored['status']);
        $this->models[0]['model_sku']='INT-10-ROSE'; $this->mode=''; $restored=$this->finish($this->step($restored));
        $this->assertSame('success',$restored['status']); $this->assertSame(2,$this->writes);
    }
    public function test_ambiguous_acceptance_is_verified_without_resending_and_stock_price_changes_are_allowed(): void
    {
        $this->mode='unknown'; $run=$this->step($this->step($this->start()));
        $this->models[0]['normal_stock']=0; $this->models[0]['price_info']=[['current_price'=>9999]];
        $this->mode='';
        $run=$this->finish($this->step($run)); $this->assertSame('success',$run['status']); $this->assertSame(2,$this->writes);
        $this->assertStringNotContainsString('signed-secret',json_encode($run));
    }
    public function test_competing_worker_and_status_get_do_no_work(): void
    {
        $run=$this->start(); DB::table('stock_hub_agni_sku_guards')->where('key','source:10')->update(['owner'=>(string)Str::uuid(),'expires_at'=>now()->addMinutes(5)]);
        $this->step($run); app(StockHubAgniSkuService::class)->show($run['run_id']); app(StockHubAgniSkuService::class)->source('10'); Http::assertNothingSent();
    }
    public function test_non_live_source_and_already_correct_models_verify_without_mutation(): void
    {
        $this->itemStatus='UNLIST'; foreach($this->models as &$m)$m['model_sku']='INT-10-'.strtoupper($m['model_name']);unset($m);
        $run=$this->finish($this->start()); $this->assertSame('exists',$run['status']); $this->assertSame(0,$this->writes);
    }

    public function test_pending_normalizer_blocks_primary_manual_and_bulk_writers_before_http():void
    {
        $this->start();
        $this->postJson('/api/sku-mapping/update-marketplace-variant-sku',['channel'=>'shopee','seller_sku'=>'MANUAL','item_id'=>'10','model_id'=>'101'])->assertStatus(409);
        $this->postJson('/api/sku-mapping/bulk-update-empty-shopee-variant-skus',[])->assertStatus(409);
        $this->postJson('/api/sku-mapping/update-marketplace-sku',['stock_master_id'=>1,'seller_sku'=>'MAPPED','apply_shopee'=>true,'apply_tiktok'=>false])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_transport_preparation_failure_does_not_consume_model_attempt():void
    {
        $run=$this->step($this->start()); DB::table('shopee_tokens')->update(['access_token_expire_at'=>now()->subMinute()]);
        $run=$this->step($run);$this->assertSame('blocked',$run['status']);$this->assertTrue($run['can_retry']);
        $this->assertSame(0,DB::table('stock_hub_agni_sku_attempts')->whereNotNull('attempted_at')->count());$this->assertSame(0,$this->writes);
    }

    public function test_name_or_model_list_changes_after_attempt_never_cache_or_replay():void
    {
        $run=$this->step($this->step($this->start()));$this->models[]=['model_id'=>103,'model_name'=>'Extra','model_sku'=>''];
        $run=$this->step($run);$this->assertSame('submitted_unverified',$run['status']);$this->assertSame(1,$this->writes);
        $this->assertSame('INT-10-RED',DB::table('shopee_product_model')->where('model_id','101')->value('model_sku'));
        $this->assertSame($run['run_id'],$this->start()['run_id']);
    }

    public function test_pending_normalizer_blocks_target_services_but_independent_source_is_available():void
    {
        $run=$this->start();
        foreach(['assertRepairAvailable','assertAdditionAvailable'] as $method) {
            try { \App\Services\StockHubTiktokVariantInterlock::$method('10','900');$this->fail('pending normalizer must own both identities'); }catch(\DomainException) {$this->addToAssertionCount(1);}
            \App\Services\StockHubTiktokVariantInterlock::$method('20','910');
        }
        $this->assertSame($run['run_id'],$this->finish($run)['run_id']);
    }

    public function test_unresolved_target_audit_blocks_normalization_even_when_source_is_missing():void
    {
        $id=(string)Str::uuid();DB::table('tiktok_reconciliation_runs')->insert(['id'=>$id,'revision'=>str_repeat('a',64),'status'=>'under_review','summary'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        DB::table('tiktok_reconciliation_run_items')->insert(['run_id'=>$id,'item_key'=>'900','action_type'=>'agni_source_reconcile','status'=>'under_review','source_fingerprint'=>str_repeat('a',64),'target_product_id'=>'900','payload'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $this->expectException(\DomainException::class);$this->start();
    }

    public function test_fresh_target_relation_and_pending_review_block_source_writes():void
    {
        $this->relationValid=false;$run=$this->finish($this->start());$this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->writes);
        $this->relationValid=true;$this->targetPending=true;$run=$this->finish($this->start());$this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->writes);
    }

    public function test_explicit_empty_result_rejection_can_restart_with_new_key_while_old_attempt_is_retained():void
    {
        $this->mode='rejected';$run=$this->step($this->step($this->start()));$this->assertSame('rejected',$run['status']);$this->assertTrue($run['can_retry']);
        $this->assertSame('rejected',$this->step($run)['status']);$this->assertSame(1,$this->writes);
        $this->mode='';$next=$this->finish($this->start());$this->assertNotSame($run['run_id'],$next['run_id']);$this->assertSame('success',$next['status']);
        $this->assertSame(3,$this->writes);$this->assertSame(1,DB::table('stock_hub_agni_sku_attempts')->where('run_id',$run['run_id'])->where('status','rejected')->count());
        $this->assertStringNotContainsString('private',json_encode($run));
    }

    public function test_target_shop_change_between_source_steps_prevents_attempt():void
    {
        $run=$this->step($this->start());$this->targetShop='44';$run=$this->step($run);
        $this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->writes);
    }
}
