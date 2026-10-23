<?php

namespace Tests\Feature;

use App\Services\{MarketplaceStockMirrorLease, OrphanVariantGateway, StockHubTiktokProductGateway, StockHubTiktokProductRejected, StockHubTiktokVariantGateway, StockHubTiktokVariantInterlock};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class StockHubTiktokVariantRepairTest extends TestCase
{
    use RefreshDatabase;
    private string $base = '/api/stock-hub/tiktok-variant-repairs';
    private array $sourceData;
    private array $live;
    private array $review;
    private array $snapshotData;
    private array $sent = [];
    private int $edits = 0;
    private string $mode = '';
    private ?\Closure $onEditPreparation = null;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests();
        Schema::create('shopee_product', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('shop_id'); $t->boolean('is_active')->default(true); });
        Schema::create('shopee_product_model', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('model_id'); $t->string('model_sku'); });
        Schema::create('tiktok_products', function (Blueprint $t) { $t->id(); $t->string('product_id'); $t->string('sku_id'); $t->string('seller_sku'); $t->string('sku_name')->nullable(); $t->string('image_url')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        DB::table('shopee_product')->insert(['item_id'=>'10','shop_id'=>'11']);
        $variants=[];
        foreach ([['101','Rose','INT-10-RED'],['102','Blue','INT-10-BLUE'],['103','Green','INT-10-GREEN']] as [$id,$name,$sku]) {
            $variants[]=['id'=>$id,'name'=>$name,'seller_sku'=>$sku,'stock'=>3,'price'=>'15000','attributes'=>[['name'=>'Warna','value_name'=>$name,'image_url'=>'https://img.test/'.$id.'.jpg']]];
            DB::table('shopee_product_model')->insert(['item_id'=>'10','model_id'=>$id,'model_sku'=>$sku]);
        }
        $this->sourceData=['id'=>'10','shop_id'=>'11','title'=>'Product','main_images'=>['https://img.test/main.jpg'],'variants'=>$variants];
        $rows=[];
        foreach ([['901','Red','INT-10-RED'],['902','Blue','INT-10-OLD'],['903','Extra','INT-10-EXTRA']] as [$id,$name,$sku]) {
            $rows[]=['id'=>$id,'seller_sku'=>$sku,'price'=>['currency'=>'IDR','sale_price'=>'20000'],'inventory'=>[['warehouse_id'=>'wh','quantity'=>7]],'sales_attributes'=>[['id'=>'100','name'=>'Warna','value_name'=>$name,'value_id'=>'v'.$id,'sku_img'=>['uri'=>'old/'.$id]]]];
            DB::table('tiktok_products')->insert(['product_id'=>'900','sku_id'=>$id,'seller_sku'=>$sku,'sku_name'=>$name]);
        }
        $this->live=['id'=>'900','status'=>'ACTIVATE','audit'=>['status'=>'APPROVED'],'package_weight'=>['value'=>'0.2','unit'=>'KILOGRAM'],'package_dimensions'=>['length'=>'25','width'=>'25','height'=>'10','unit'=>'CENTIMETER'],'skus'=>$rows];
        $this->review=$this->live; $this->review['skus'][0]['seller_sku']='INT-10-STALE';
        $this->snapshotData=['account_key'=>'tiktok-agnishopbjm','source_account_key'=>'shopee-agnishopbjm','source_product_id'=>'10','target_product_id'=>'900','source_complete'=>true,'target_complete'=>true,'mapping_conflicts'=>false,'source_variants'=>array_map(fn($v)=>array_intersect_key($v,array_flip(['id','name','seller_sku'])),$variants),'target_variants'=>array_map(fn($v)=>['id'=>$v['id'],'seller_sku'=>$v['seller_sku'],'name'=>$v['sales_attributes'][0]['value_name']],$rows)];
        $products=Mockery::mock(StockHubTiktokProductGateway::class);
        $products->shouldReceive('target')->andReturnUsing(fn($id,$review=false)=>$review?$this->review:$this->live);
        $this->app->instance(StockHubTiktokProductGateway::class,$products);
        $orphans=Mockery::mock(OrphanVariantGateway::class); $orphans->shouldReceive('snapshot')->andReturnUsing(fn()=>$this->snapshotData); $this->app->instance(OrphanVariantGateway::class,$orphans);
        $gateway=Mockery::mock(StockHubTiktokVariantGateway::class);
        $gateway->shouldReceive('source')->andReturnUsing(fn()=>$this->sourceData);
        $gateway->shouldReceive('targetShop')->andReturn('33'); $gateway->shouldReceive('assertTargetShop')->andReturnNull();
        $gateway->shouldReceive('page')->andReturn(['ids'=>['900','910'],'next'=>null,'total'=>2]);
        $gateway->shouldReceive('catalogTarget')->andReturnUsing(fn($id)=>$id==='900'?$this->live:['id'=>'910','skus'=>[['seller_sku'=>$this->mode==='conflict'?'INT-10-BLUE':'OTHER']]]);
        $gateway->shouldReceive('upload')->andReturn('new/image');
        $gateway->shouldReceive('prepareEdit')->andReturnUsing(function() {
            if($this->onEditPreparation)($this->onEditPreparation)();
            return function($body) {
                $this->assertNotNull(DB::table('stock_hub_tiktok_variant_repair_runs')->where('status','submitting')->value('attempted_at'));
                $this->edits++; $this->sent=$body;
                if($this->mode==='timeout')throw new \RuntimeException('secret signed URL');
                if($this->mode==='rejected')throw new StockHubTiktokProductRejected('Ditolak TikTok.');
                $this->review=[...$this->live,...$body];
                if($this->mode==='review')$this->review['audit']['status']='AUDITING'; else $this->live=$this->review;
            };
        });
        $this->app->instance(StockHubTiktokVariantGateway::class,$gateway);
        $lease=Mockery::mock(MarketplaceStockMirrorLease::class); $lease->shouldReceive('acquire')->andReturnNull(); $lease->shouldReceive('renew')->andReturnNull(); $lease->shouldReceive('release')->andReturnNull(); $this->app->instance(MarketplaceStockMirrorLease::class,$lease);
    }
    private function start(?string $key=null): array { return $this->postJson($this->base.'/runs',['source_product_id'=>'10','request_key'=>$key??(string)Str::uuid()])->assertOk()->json('data'); }
    private function step(array $run): array { return $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
    private function finish(array $run): array { for($i=0;$i<30&&$run['can_continue'];$i++)$run=$this->step($run); return $run; }

    public function test_duplicate_review_seller_sku_does_not_block_agni_repair_of_unique_live_variants(): void
    {
        $this->review['skus'][1]['seller_sku']=$this->review['skus'][0]['seller_sku'];
        $run=$this->finish($this->start());
        $this->assertSame('success',$run['status']);$this->assertSame(1,$this->edits);
        $this->assertSame(['901','902'],array_column($this->sent['skus'],'id'));
        $this->assertSame(['INT-10-RED','INT-10-BLUE'],array_column($this->sent['skus'],'seller_sku'));
        $this->assertSame([7,7],array_map(fn($r)=>$r['inventory'][0]['quantity'],$this->sent['skus']));
        $this->assertSame(['20000','20000'],array_map(fn($r)=>$r['price']['sale_price'],$this->sent['skus']));
    }

    public function test_same_video_asset_with_rotating_delivery_urls_does_not_invalidate_repair_preparation(): void
    {
        $this->live['video']=['id'=>'asset-1','url'=>'https://video.test/live?v=1','cover_url'=>'https://image.test/live?v=1'];
        $this->review['video']=['id'=>'asset-1','url'=>'https://video.test/review?v=2','cover_url'=>'https://image.test/review?v=2'];
        $run=$this->step($this->start());$this->assertSame('scanning',$run['status']);
        $this->live['video']['url']='https://video.test/live?v=3';$this->review['video']['cover_url']='https://image.test/review?v=4';
        $run=$this->finish($run);
        $this->assertSame('success',$run['status']);$this->assertSame(1,$this->edits);
        $this->assertArrayNotHasKey('video',$this->sent);
    }

    public static function unsafeVideoVersions(): array { return [['changed_id'],['missing_id'],['changed_metadata'],['blank_id'],['malformed_link']]; }

    /** @dataProvider unsafeVideoVersions */
    public function test_video_changes_without_matching_stable_identity_remain_blocked(string $kind): void
    {
        $this->live['video']=['id'=>'asset-1','url'=>'https://video.test/one','cover_url'=>'https://image.test/one','format'=>'MP4'];
        $this->review['video']=['id'=>'asset-1','url'=>'https://video.test/two','cover_url'=>'https://image.test/two','format'=>'MP4'];
        if($kind==='changed_id')$this->review['video']['id']='asset-2';
        elseif($kind==='missing_id'){unset($this->live['video']['id'],$this->review['video']['id']);}
        elseif($kind==='blank_id'){$this->live['video']['id']=' ';$this->review['video']['id']=' ';}
        elseif($kind==='malformed_link')$this->review['video']['url']=['unexpected'=>'value'];
        else $this->review['video']['format']='OTHER';
        $run=$this->finish($this->start());$this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->edits);
    }

    public function test_duplicate_live_seller_skus_are_repaired_from_unique_source_attribute_matches(): void
    {
        $this->live['skus']=array_slice($this->live['skus'],0,2);
        $this->live['skus'][0]['sales_attributes'][0]['value_name']='Rose';
        foreach($this->live['skus'] as &$row)$row['seller_sku']='INT-10-RED';unset($row);
        $this->review=$this->live;
        $this->snapshotData['target_variants']=array_map(fn($r)=>['id'=>$r['id'],'seller_sku'=>$r['seller_sku'],'name'=>$r['sales_attributes'][0]['value_name']],$this->live['skus']);
        $run=$this->finish($this->start());
        $this->assertSame('success',$run['status']);$this->assertSame(1,$this->edits);
        $this->assertSame(['INT-10-RED','INT-10-BLUE'],array_column($this->sent['skus'],'seller_sku'));
        $this->assertSame(['901','902'],array_column($this->sent['skus'],'id'));
        $this->assertSame([], $run['result']['deleted']);
    }

    public function test_duplicate_live_sku_without_unique_name_or_mapping_is_still_blocked(): void
    {
        $this->live['skus'][1]['seller_sku']=$this->live['skus'][0]['seller_sku'];$this->review=$this->live;
        $this->snapshotData['target_variants'][1]['seller_sku']=$this->live['skus'][0]['seller_sku'];
        $run=$this->finish($this->start());
        $this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->edits);
    }

    public static function unsafeReviewDuplicates(): array { return [['id'],['attributes'],['quantity']]; }

    /** @dataProvider unsafeReviewDuplicates */
    public function test_duplicate_seller_sku_exception_never_relaxes_other_review_contracts(string $kind): void
    {
        $this->review['skus'][1]['seller_sku']=$this->review['skus'][0]['seller_sku'];
        if($kind==='id')$this->review['skus'][1]['id']=$this->review['skus'][0]['id'];
        elseif($kind==='attributes')$this->review['skus'][1]['sales_attributes']=$this->review['skus'][0]['sales_attributes'];
        else $this->review['skus'][1]['inventory'][0]['quantity']=9;
        $run=$this->finish($this->start());
        $this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->edits);
    }

    public static function sourcePrerequisiteCases(): array
    {
        return [['submitted_unverified',false,true],['preparing',false,true],['blocked',true,true],['blocked',false,false],['success',true,false],['rejected',true,false]];
    }

    /** @dataProvider sourcePrerequisiteCases */
    public function test_source_get_reports_pending_agni_sku_without_creating_repair(string $status,bool $attempted,bool $pending): void
    {
        $id=(string)Str::uuid();
        DB::table('stock_hub_agni_sku_runs')->insert(['id'=>$id,'request_key'=>(string)Str::uuid(),'source_product_id'=>'10','target_product_id'=>'900','status'=>$status,'state'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        if($attempted)DB::table('stock_hub_agni_sku_attempts')->insert(['run_id'=>$id,'model_id'=>'101','name'=>'Rose','old_sku'=>'OLD','seller_sku'=>'NEW','status'=>'submitted_unverified','attempted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $before=DB::table('stock_hub_agni_sku_runs')->get();
        $attempts=DB::table('stock_hub_agni_sku_attempts')->get();
        $response=$this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data',null);
        if($pending)$response->assertJsonPath('prerequisite.code','agni_sku_pending');
        else $this->assertArrayNotHasKey('prerequisite',$response->json());
        $this->assertSame(0,DB::table('stock_hub_tiktok_variant_repair_runs')->count());
        $this->assertEquals($before,DB::table('stock_hub_agni_sku_runs')->get());
        $this->assertEquals($attempts,DB::table('stock_hub_agni_sku_attempts')->get());
        $this->assertSame(0,$this->edits);Http::assertNothingSent();
    }

    public function test_saved_repair_recovery_takes_precedence_over_source_prerequisite(): void
    {
        $run=$this->start();
        DB::table('stock_hub_agni_sku_runs')->insert(['id'=>(string)Str::uuid(),'request_key'=>(string)Str::uuid(),'source_product_id'=>'10','target_product_id'=>'900','status'=>'submitted_unverified','state'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $response=$this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data.run_id',$run['run_id']);
        $this->assertArrayNotHasKey('prerequisite',$response->json());
        $this->assertSame(0,$this->edits);Http::assertNothingSent();
    }

    public function test_expired_repair_worker_cannot_send_after_unattempted_retirement():void
    {
        $run=$this->start();for($i=0;$i<30&&$run['status']!=='submitting';$i++)$run=$this->step($run);$this->assertSame('submitting',$run['status']);
        $this->onEditPreparation=function()use($run){
            DB::table('stock_hub_tiktok_variant_repair_guards')->where('key','source:10')->update(['expires_at'=>now()->subSecond()]);
            $this->assertSame('retired',StockHubTiktokVariantInterlock::retireUnattempted('repair',$run['run_id'],'10'));
        };
        $run=$this->step($run);$this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->edits);
        $row=DB::table('stock_hub_tiktok_variant_repair_runs')->where('id',$run['run_id'])->first();$this->assertNull($row->attempted_at);$this->assertTrue(json_decode($row->state,true)['batch_retired_before_attempt']);Http::assertNothingSent();
    }

    private function historicalAudit(array $payload=[],array $summary=[],string $target='900'): int
    {
        $id=(string)Str::uuid();
        DB::table('tiktok_reconciliation_runs')->insert(['id'=>$id,'revision'=>str_repeat('a',64),'status'=>'under_review','summary'=>json_encode($summary),'created_at'=>now(),'updated_at'=>now()]);
        return DB::table('tiktok_reconciliation_run_items')->insertGetId(['run_id'=>$id,'item_key'=>$target,'action_type'=>'agni_source_reconcile','status'=>'under_review','source_fingerprint'=>str_repeat('a',64),'target_product_id'=>$target,'payload'=>json_encode($payload),'result'=>json_encode(['original_evidence'=>'keep']),'created_at'=>now(),'updated_at'=>now()]);
    }

    public static function unresolvedTargetAuditCases(): array
    {
        return [
            'missing source'=>[[],[],false],
            'different source'=>[['source'=>['id'=>'20']],['source_product_id'=>'20'],false],
            'conflicting payload and summary'=>[['source'=>['id'=>'10']],['source_product_id'=>'20'],false],
            'multiple different sources'=>[['source'=>['id'=>'20']],['source_product_id'=>'20'],true],
            'multiple same-source attempts'=>[['source'=>['id'=>'10']],['source_product_id'=>'10'],true],
        ];
    }

    /** @dataProvider unresolvedTargetAuditCases */
    public function test_start_rejects_unresolved_target_audits_without_unique_matching_source(array $payload,array $summary,bool $another): void
    {
        $item=$this->historicalAudit($payload,$summary);
        if($another)$this->historicalAudit(['source'=>$this->sourceData],['source_product_id'=>'10']);
        $before=DB::table('tiktok_reconciliation_run_items')->where('id',$item)->first();
        $this->postJson($this->base.'/runs',['source_product_id'=>'10','request_key'=>(string)Str::uuid()])->assertStatus(409);
        $this->assertSame(0,DB::table('stock_hub_tiktok_variant_repair_runs')->count());
        $this->assertEquals($before,DB::table('tiktok_reconciliation_run_items')->where('id',$item)->first());
        $this->assertSame(0,$this->edits);Http::assertNothingSent();
    }

    public static function targetAuditPreparationCases(): array
    {
        $cases=[];
        foreach(['before_prepare','after_prepare','freezing_edit','after_attempt_marker'] as $stage)
            foreach([null,'20'] as $source)$cases[$stage.' '.($source??'missing')]=[$stage,$source];
        return $cases;
    }

    /** @dataProvider targetAuditPreparationCases */
    public function test_target_audit_appearing_during_preparation_prevents_edit(string $stage,?string $source): void
    {
        $run=$this->start();$item=null;
        $insert=function()use($source,&$item){$item=$this->historicalAudit($source===null?[]:['source'=>['id'=>$source]],$source===null?[]:['source_product_id'=>$source]);};
        if($stage==='before_prepare')$insert();
        else {
            $run=$this->step($run);
            if($stage==='after_prepare')$insert();
            else {
                while($run['can_continue']&&$run['status']!=='submitting')$run=$this->step($run);
                $this->assertSame('submitting',$run['status']);
                if($stage==='freezing_edit')$this->onEditPreparation=$insert;
                else {
                    $armed=true;
                    DB::listen(function($query)use(&$armed,$insert){
                        if(!$armed||!str_starts_with($query->sql,'update "stock_hub_tiktok_variant_repair_runs"')||!str_contains($query->sql,'"attempted_at"'))return;
                        $armed=false;$insert();
                    });
                }
            }
        }
        $run=$this->finish($run);
        $this->assertSame(0,$this->edits);$this->assertNotNull($item);
        $this->assertSame($stage==='after_attempt_marker'?'submitted_unverified':'blocked',$run['status']);
        $this->assertSame($stage==='after_attempt_marker',DB::table('stock_hub_tiktok_variant_repair_runs')->where('id',$run['run_id'])->value('attempted_at')!==null);
        $audit=DB::table('tiktok_reconciliation_run_items')->where('id',$item)->first();
        $this->assertSame('under_review',$audit->status);$this->assertSame(['original_evidence'=>'keep'],json_decode($audit->result,true));
        $this->assertSame($source===null?[]:['source'=>['id'=>$source]],json_decode($audit->payload,true));
        $this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));
        $this->step($run);$this->assertSame(0,$this->edits);Http::assertNothingSent();
    }

    public function test_supported_same_source_audit_appearing_before_prepare_is_adopted_without_edit(): void
    {
        $run=$this->start();
        $plan=$this->app->make(\App\Services\StockHubTiktokSourceReconciliationPlan::class)->build($this->sourceData,$this->snapshotData,$this->live,$this->review);
        $payload=['source'=>$this->sourceData,'before'=>$this->live,'plan'=>$plan];
        $item=$this->historicalAudit($payload,['source_product_id'=>'10']);
        $this->live=$this->review=[...$this->live,...$plan['payload']];
        $run=$this->step($run);$this->assertSame('under_review',$run['status']);
        $run=$this->step($run);$this->assertSame('success',$run['status']);$this->assertSame(0,$this->edits);
        $audit=DB::table('tiktok_reconciliation_run_items')->where('id',$item)->first();
        $this->assertSame('verified',$audit->status);$this->assertSame($payload,json_decode($audit->payload,true));
        $this->assertSame('keep',json_decode($audit->result,true)['original_evidence']);
    }

    public function test_unresolved_historical_audit_on_independent_target_does_not_block_repair(): void
    {
        $item=$this->historicalAudit([],[],'910');
        $before=DB::table('tiktok_reconciliation_run_items')->where('id',$item)->first();
        $this->assertSame('success',$this->finish($this->start())['status']);$this->assertSame(1,$this->edits);
        $this->assertEquals($before,DB::table('tiktok_reconciliation_run_items')->where('id',$item)->first());
    }

    /** @dataProvider conflictingAuditReadbackCases */
    public function test_conflicting_target_audit_during_recovery_prevents_cache_or_audit_resolution(bool $duringFinalRelation): void
    {
        $run=$this->start();
        $plan=$this->app->make(\App\Services\StockHubTiktokSourceReconciliationPlan::class)->build($this->sourceData,$this->snapshotData,$this->live,$this->review);
        $original=$this->historicalAudit(['source'=>$this->sourceData,'before'=>$this->live,'plan'=>$plan],['source_product_id'=>'10']);
        $run=$this->step($run);$this->assertSame('under_review',$run['status']);
        $this->live=$this->review=[...$this->live,...$plan['payload']];
        $conflict=null;
        $insert=function()use(&$conflict){$conflict=$this->historicalAudit(['source'=>['id'=>'20']],['source_product_id'=>'20']);};
        if(!$duringFinalRelation)$insert();
        else {
            $armed=true;
            DB::listen(function($query)use(&$armed,$insert){
                if(!$armed||!str_starts_with($query->sql,'select')||!str_contains($query->sql,'from "shopee_product"'))return;
                $armed=false;$insert();
            });
        }
        $run=$this->step($run);$this->assertSame('submitted_unverified',$run['status']);$this->assertFalse($run['can_retry']);
        $this->assertNotNull($conflict);$this->assertSame(0,$this->edits);
        foreach([$original,$conflict] as $item) {
            $audit=DB::table('tiktok_reconciliation_run_items')->where('id',$item)->first();
            $this->assertSame('under_review',$audit->status);$this->assertSame(['original_evidence'=>'keep'],json_decode($audit->result,true));
        }
        $this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));Http::assertNothingSent();
    }

    public static function conflictingAuditReadbackCases(): array { return ['before readback'=>[false],'during final relation read'=>[true]]; }

    public function test_start_and_get_are_local_and_repeat_keys_and_source_guard_recover_same_run(): void
    {
        $key=(string)Str::uuid();$run=$this->start($key);
        $this->assertSame('preparing',$run['status']);$this->assertSame($run['run_id'],$this->start($key)['run_id']);$this->assertSame($run['run_id'],$this->start()['run_id']);
        $this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data.run_id',$run['run_id']);
        $this->getJson($this->base.'/runs/'.$run['run_id'])->assertOk()->assertJsonPath('data.status','preparing'); Http::assertNothingSent();
    }
    public function test_repair_preserves_survivor_contracts_reports_missing_and_only_caches_after_verification(): void
    {
        $run=$this->finish($this->start());$this->assertSame('success',$run['status']);$this->assertSame(1,$this->edits);
        $this->assertSame(['901','902'],array_column($this->sent['skus'],'id'));$this->assertSame(['INT-10-RED','INT-10-BLUE'],array_column($this->sent['skus'],'seller_sku'));
        $this->assertSame([7,7],array_map(fn($r)=>$r['inventory'][0]['quantity'],$this->sent['skus']));
        $this->assertSame('20000',$this->sent['skus'][0]['price']['sale_price']);$this->assertSame('new/image',$this->sent['skus'][0]['sales_attributes'][0]['sku_img']['uri']);
        $this->assertSame(1,$run['result']['missing_count']);$this->assertSame(['Rose','Blue'],array_column($run['result']['changed'],'name'));
        $this->assertSame(['Extra'],array_column($run['result']['deleted'],'name'));$this->assertFalse((bool)DB::table('tiktok_products')->where('sku_id','903')->value('is_active'));
        $this->assertSame('Rose',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));$this->step($run);$this->assertSame(1,$this->edits);
    }
    public function test_timeout_and_pending_review_cannot_be_replayed_or_bypassed(): void
    {
        $this->mode='timeout';$run=$this->finish($this->start());$this->assertSame('submitted_unverified',$run['status']);$this->assertFalse($run['can_retry']);
        $this->assertSame($run['run_id'],$this->start()['run_id']);$this->step($run);$this->assertSame(1,$this->edits);$this->assertStringNotContainsString('secret',json_encode($run));
        $this->postJson('/api/stock-hub/tiktok-variants/runs',['source_product_id'=>'10','request_key'=>(string)Str::uuid()])->assertStatus(409);
        $this->expectException(\DomainException::class);StockHubTiktokVariantInterlock::assertLegacyAvailable('900');
    }
    public function test_pending_review_checks_dual_versions_and_defers_cache(): void
    {
        $this->mode='review';$run=$this->finish($this->start());$this->assertSame('under_review',$run['status']);$this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));
        $this->assertSame('under_review',$this->step($run)['status']);$this->review['audit']['status']='APPROVED';$this->live=$this->review;
        $this->assertSame('success',$this->step($run)['status']);$this->assertSame(1,$this->edits);
    }
    public function test_cross_product_ownership_blocks_before_any_edit(): void { $this->mode='conflict';$this->assertSame('blocked',$this->finish($this->start())['status']);$this->assertSame(0,$this->edits); }
    public function test_non_sku_version_difference_blocks_before_edit(): void { $this->review['skus'][0]['inventory'][0]['quantity']=8;$this->assertSame('blocked',$this->finish($this->start())['status']);$this->assertSame(0,$this->edits); }
    public function test_source_changed_after_upload_blocks_submission(): void
    {
        $run=$this->start();while($run['can_continue']&&$run['status']!=='submitting')$run=$this->step($run);
        $this->sourceData['variants'][0]['stock']=99;$this->assertSame('blocked',$this->step($run)['status']);$this->assertSame(0,$this->edits);
    }
    public function test_explicit_rejection_allows_new_run_without_erasing_attempt(): void
    {
        $this->mode='rejected';$run=$this->finish($this->start());$this->assertSame('rejected',$run['status']);$this->assertTrue($run['can_retry']);
        $this->assertNotSame($run['run_id'],$this->start()['run_id']);$this->assertSame(1,DB::table('stock_hub_tiktok_variant_repair_runs')->whereNotNull('attempted_at')->count());
    }
    public function test_historical_audit_get_is_local_and_step_adopts_exact_payload_without_send(): void
    {
        $id=(string)Str::uuid();$body=['save_mode'=>'LISTING','skus'=>[$this->live['skus'][0],$this->live['skus'][1]]];
        $body['skus'][0]['sales_attributes'][0]['value_name']='Rose';unset($body['skus'][0]['sales_attributes'][0]['value_id']);$body['skus'][1]['seller_sku']='INT-10-BLUE';
        // Include package contracts, as persisted operator repairs do.
        foreach($body['skus'] as &$r){$r['sku_weight']=$this->live['package_weight'];$r['sku_dimensions']=$this->live['package_dimensions'];}unset($r);
        $audit=['source'=>$this->sourceData,'before'=>$this->live,'plan'=>['payload'=>$body,'changed'=>[['id'=>'901','name'=>'Rose','old_name'=>'Red','seller_sku'=>'INT-10-RED'],['id'=>'902','name'=>'Blue','old_name'=>'Blue','seller_sku'=>'INT-10-BLUE']],'deleted'=>[['id'=>'903','name'=>'Extra','seller_sku'=>'INT-10-EXTRA']],'missing'=>1]];
        DB::table('tiktok_reconciliation_runs')->insert(['id'=>$id,'revision'=>str_repeat('a',64),'status'=>'under_review','summary'=>json_encode(['source_product_id'=>'10']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('tiktok_reconciliation_run_items')->insert(['run_id'=>$id,'item_key'=>'900','action_type'=>'agni_source_reconcile','status'=>'under_review','source_fingerprint'=>str_repeat('a',64),'target_product_id'=>'900','payload'=>json_encode($audit),'result'=>json_encode(['original_evidence'=>'keep']),'created_at'=>now(),'updated_at'=>now()]);
        $this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data.run_id',$id)->assertJsonPath('data.status','under_review');
        $this->assertSame(0,DB::table('stock_hub_tiktok_variant_repair_runs')->count());Http::assertNothingSent();
        $original=DB::table('tiktok_reconciliation_run_items')->value('payload');$this->live=$this->review=[...$this->live,...$body];
        $run=$this->step(['run_id'=>$id]);$this->assertSame('success',$run['status']);$this->assertSame(0,$this->edits);$this->assertSame($original,DB::table('tiktok_reconciliation_run_items')->value('payload'));
        $this->assertSame('verified',DB::table('tiktok_reconciliation_run_items')->value('status'));
        $this->assertSame('keep',json_decode(DB::table('tiktok_reconciliation_run_items')->value('result'),true)['original_evidence']??null);
    }

    public function test_empty_packages_are_filled_after_raw_version_comparison_and_valid_values_stay_frozen(): void
    {
        config(['stock_hub_package.weight'=>['value'=>'0.3','unit'=>'KILOGRAM']]);
        $this->live['package_weight']=$this->review['package_weight']=['value'=>'0','unit'=>'KILOGRAM'];
        $run=$this->finish($this->start());$this->assertSame('success',$run['status']);
        $this->assertSame('0.3',$this->sent['package_weight']['value']);
        $this->assertSame(['length'=>'25','width'=>'25','height'=>'10','unit'=>'CENTIMETER'],$this->sent['package_dimensions']);
    }
    public function test_timeout_can_recover_verified_success_without_second_edit(): void
    {
        $this->mode='timeout';$run=$this->finish($this->start());
        $this->live=$this->review=[...$this->live,...$this->sent];
        $run=$this->step($run);$this->assertSame('success',$run['status']);$this->assertSame(1,$this->edits);
        $this->assertNotNull(DB::table('stock_hub_tiktok_variant_repair_runs')->value('accepted_at'));
    }
    public function test_noop_ignores_volatile_urls_but_stale_review_sku_reasserts_once(): void
    {
        $this->live['skus']=array_slice($this->live['skus'],0,2);$this->live['skus'][0]['sales_attributes'][0]['value_name']='Rose';$this->live['skus'][1]['seller_sku']='INT-10-BLUE';
        $this->snapshotData['target_variants']=[['id'=>'901','seller_sku'=>'INT-10-RED','name'=>'Rose'],['id'=>'902','seller_sku'=>'INT-10-BLUE','name'=>'Blue']];
        $this->review=$this->live;$this->review['main_images']=[['urls'=>['https://cdn.test/volatile']]];
        $this->assertSame('exists',$this->finish($this->start())['status']);$this->assertSame(0,$this->edits);
        $this->review['skus'][0]['seller_sku']='INT-10-STALE';$run=$this->finish($this->start());$this->assertSame('success',$run['status']);$this->assertSame(1,$this->edits);
        $this->assertSame([],$run['result']['changed']);
    }
    public function test_empty_review_attribute_placeholders_do_not_block_identity_repair(): void
    {
        $filled=['id'=>'100701','name'=>'Bahan','values'=>[['id'=>'123','name'=>'Voal']]];
        $this->live['product_attributes']=[$filled];
        $this->review['product_attributes']=[['id'=>'100401','name'=>'Instruksi Mencuci','values'=>[]],$filled];
        $run=$this->finish($this->start());$this->assertSame('success',$run['status']);$this->assertSame(1,$this->edits);
        $this->assertArrayNotHasKey('product_attributes',$this->sent);$this->assertSame([$filled],$this->live['product_attributes']);
    }
    public function test_filled_or_malformed_product_attribute_differences_still_block_repair(): void
    {
        $filled=['id'=>'100701','name'=>'Bahan','values'=>[['id'=>'123','name'=>'Voal']]];$this->live['product_attributes']=[$filled];
        foreach([['id'=>'100401','name'=>'Instruksi Mencuci','values'=>[['id'=>'9','name'=>'Manual']]],['id'=>'100401','name'=>'Instruksi Mencuci','values'=>null]] as $attribute){
            $this->review['product_attributes']=[$filled,$attribute];$run=$this->finish($this->start());
            $this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->edits);
        }
    }
    public function test_readback_writable_drift_never_completes_or_updates_cache(): void
    {
        $this->mode='review';$run=$this->finish($this->start());$expected=$this->review;$expected['audit']['status']='APPROVED';
        foreach(['name','image','inventory','ids','package'] as $drift) {
            $this->live=$this->review=$expected;
            if($drift==='name')$this->review['skus'][0]['sales_attributes'][0]['value_name']='Wrong';
            if($drift==='image')unset($this->review['skus'][0]['sales_attributes'][0]['sku_img']);
            if($drift==='inventory')$this->review['skus'][0]['inventory'][0]['quantity']=99;
            if($drift==='ids')$this->review['skus'][0]['id']='999';
            if($drift==='package')$this->review['package_weight']['value']='0.8';
            $this->assertSame('submitted_unverified',$this->step($run)['status'],$drift);
            $this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));
        }
        $this->assertSame(1,$this->edits);
    }
    public function test_pending_repair_blocks_legacy_http_boundaries_but_allows_other_products(): void
    {
        Schema::create('tiktok_tokens', function(Blueprint $t){$t->id();$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('tiktok_shops', function(Blueprint $t){$t->id();$t->timestamps();});
        $run=$this->step($this->start());$this->assertSame('scanning',$run['status']);
        StockHubTiktokVariantInterlock::assertRepairAvailable(null,'910');
        $api=$this->app->make(\App\Services\MarketplaceApiService::class);
        try{$api->partialEditTiktokProduct('900',['skus'=>[]]);$this->fail('Must block pending repair before HTTP.');}catch(\DomainException $e){$this->assertStringContainsString('Perbaikan',$e->getMessage());}
        $controller=$this->app->make(\App\Http\Controllers\OmnichannelController::class);
        $method=(new \ReflectionClass($controller))->getMethod('submitTiktokPartialEditPayload');
        try{$method->invoke($controller,'/product/202509/products/900/partial_edit',[],[]);$this->fail('Must block pending repair.');}catch(\DomainException $e){$this->assertStringContainsString('Perbaikan',$e->getMessage());}
        Http::assertNothingSent();
    }
    public function test_pending_addition_reserves_source_before_repair_can_start(): void
    {
        DB::table('stock_hub_tiktok_variant_runs')->insert(['id'=>(string)Str::uuid(),'request_key'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'source_product_id'=>'10','status'=>'preparing','state'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        $this->postJson($this->base.'/runs',['source_product_id'=>'10','request_key'=>(string)Str::uuid()])->assertStatus(409);
        $this->assertSame(0,DB::table('stock_hub_tiktok_variant_repair_runs')->count());
    }
    public function test_start_reserves_target_locally_before_first_step(): void
    {
        $run=$this->start();$this->assertSame('900',$run['remote_product_id']);
        $this->expectException(\DomainException::class);StockHubTiktokVariantInterlock::assertLegacyAvailable('900');
    }
    public function test_invalid_historical_audit_blocks_without_replay_or_audit_loss(): void
    {
        $id=(string)Str::uuid();$audit=['source'=>$this->sourceData,'plan'=>['changed'=>'unsupported']];
        DB::table('tiktok_reconciliation_runs')->insert(['id'=>$id,'revision'=>str_repeat('a',64),'status'=>'attempted','summary'=>json_encode(['source_product_id'=>'10']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('tiktok_reconciliation_run_items')->insert(['run_id'=>$id,'item_key'=>'900','action_type'=>'agni_source_reconcile','status'=>'attempted','source_fingerprint'=>str_repeat('a',64),'target_product_id'=>'900','payload'=>json_encode($audit),'result'=>json_encode(['original_evidence'=>'keep']),'created_at'=>now(),'updated_at'=>now()]);
        $this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data.status','submitted_unverified');
        $run=$this->step(['run_id'=>$id]);$this->assertSame('blocked',$run['status']);$this->assertFalse($run['can_retry']);$this->assertSame(0,$this->edits);
        $this->assertSame($id,$this->start()['run_id']);$this->assertSame(json_encode($audit),DB::table('tiktok_reconciliation_run_items')->value('payload'));
        $this->assertSame(['original_evidence'=>'keep'],json_decode(DB::table('tiktok_reconciliation_run_items')->value('result'),true));
    }
    public function test_source_order_only_change_does_not_invalidate_plan(): void
    {
        $run=$this->start();while($run['can_continue']&&$run['status']!=='submitting')$run=$this->step($run);
        $this->sourceData['variants']=array_reverse($this->sourceData['variants']);$this->assertSame('success',$this->finish($run)['status']);$this->assertSame(1,$this->edits);
    }
    public function test_live_and_review_package_difference_cannot_be_hidden_by_defaults(): void
    {
        $this->live['package_weight']=['value'=>'0','unit'=>'KILOGRAM'];
        $this->assertSame('blocked',$this->finish($this->start())['status']);$this->assertSame(0,$this->edits);
    }
    public function test_historical_payload_missing_price_is_blocked_even_if_readback_otherwise_matches(): void
    {
        $id=(string)Str::uuid();$this->review=$this->live;
        $rows=$this->live['skus'];foreach($rows as &$r){$r['sku_weight']=$this->live['package_weight'];$r['sku_dimensions']=$this->live['package_dimensions'];unset($r['price']['sale_price']);}unset($r);
        $audit=['source'=>$this->sourceData,'before'=>$this->live,'plan'=>['payload'=>['save_mode'=>'LISTING','skus'=>$rows],'changed'=>[],'deleted'=>[],'missing'=>1]];
        DB::table('tiktok_reconciliation_runs')->insert(['id'=>$id,'revision'=>str_repeat('a',64),'status'=>'attempted','summary'=>json_encode(['source_product_id'=>'10']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('tiktok_reconciliation_run_items')->insert(['run_id'=>$id,'item_key'=>'900','action_type'=>'agni_source_reconcile','status'=>'attempted','source_fingerprint'=>str_repeat('a',64),'target_product_id'=>'900','payload'=>json_encode($audit),'created_at'=>now(),'updated_at'=>now()]);
        $run=$this->step(['run_id'=>$id]);$this->assertSame('blocked',$run['status']);$this->assertFalse($run['can_retry']);$this->assertSame(0,$this->edits);
    }
    public function test_final_readback_rechecks_fresh_source_and_relation_before_cache(): void
    {
        $this->mode='review';$run=$this->finish($this->start());$this->review['audit']['status']='APPROVED';$this->live=$this->review;
        $original=$this->sourceData;
        foreach(['seller_sku','name','id','attributes','image'] as $field) {
            $this->sourceData=$original;
            if($field==='attributes')$this->sourceData['variants'][0]['attributes'][0]['value_name']='Changed';
            elseif($field==='image')$this->sourceData['variants'][0]['attributes'][0]['image_url']='https://img.test/changed.jpg';
            else $this->sourceData['variants'][0][$field]=$field==='id'?'199':'Changed';
            $this->assertSame('submitted_unverified',$this->step($run)['status'],$field);
            $this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));
        }
        $this->sourceData=$original;$this->snapshotData['mapping_conflicts']=true;$this->snapshotData['source_product_id']='20';
        $this->assertSame('submitted_unverified',$this->step($run)['status']);
        $this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));$this->assertSame(1,$this->edits);
    }
    public function test_step_uses_state_reloaded_after_claim_instead_of_preclaim_image_state(): void
    {
        $run=$this->start();while($run['can_continue']&&$run['status']!=='uploading')$run=$this->step($run);
        $armed=true;
        DB::listen(function($query)use(&$armed,$run){
            if(!$armed||!str_starts_with($query->sql,'update "stock_hub_tiktok_variant_repair_guards"')||!str_contains($query->sql,'"expires_at"'))return;
            $armed=false;
            $state=json_decode(DB::table('stock_hub_tiktok_variant_repair_runs')->where('id',$run['run_id'])->value('state'),true);
            $state['progress']['uploaded_images']=1;$state['uris']=['901'=>'new/image'];$state['image_urls']=['901'=>'https://img.test/101.jpg'];
            DB::table('stock_hub_tiktok_variant_repair_runs')->where('id',$run['run_id'])->update(['status'=>'submitting','state'=>json_encode($state)]);
        });
        $next=$this->step($run);$this->assertFalse($armed);$this->assertSame('verifying',$next['status']);
        $this->assertSame('new/image',$this->sent['skus'][0]['sales_attributes'][0]['sku_img']['uri']);$this->assertSame(1,$this->edits);
    }
    public function test_volatile_target_image_urls_do_not_invalidate_prepared_repair(): void
    {
        $this->live['main_images']=[['uri'=>'same/image','urls'=>['https://cdn.test/image?signature=old']]];
        $this->review['main_images']=$this->live['main_images'];
        $run=$this->start();while($run['can_continue']&&$run['status']!=='submitting')$run=$this->step($run);
        $this->live['main_images'][0]['urls']=['https://cdn.test/image?signature=new'];$this->review['main_images']=$this->live['main_images'];
        $this->assertSame('success',$this->finish($run)['status']);$this->assertSame(1,$this->edits);
    }
    public function test_historical_readback_also_rechecks_current_source_relation_before_cache(): void
    {
        $id=(string)Str::uuid();
        $plan=$this->app->make(\App\Services\StockHubTiktokSourceReconciliationPlan::class)->build($this->sourceData,$this->snapshotData,$this->live,$this->review);
        $audit=['source'=>$this->sourceData,'before'=>$this->live,'plan'=>$plan];
        DB::table('tiktok_reconciliation_runs')->insert(['id'=>$id,'revision'=>str_repeat('a',64),'status'=>'under_review','summary'=>json_encode(['source_product_id'=>'10']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('tiktok_reconciliation_run_items')->insert(['run_id'=>$id,'item_key'=>'900','action_type'=>'agni_source_reconcile','status'=>'under_review','source_fingerprint'=>str_repeat('a',64),'target_product_id'=>'900','payload'=>json_encode($audit),'created_at'=>now(),'updated_at'=>now()]);
        $this->review=[...$this->live,...$plan['payload']];$this->review['audit']['status']='AUDITING';
        $run=$this->step(['run_id'=>$id]);$this->assertSame('under_review',$run['status']);
        $this->review['audit']['status']='APPROVED';$this->live=$this->review;
        $original=$this->sourceData;
        foreach(['seller_sku','name','id','attributes'] as $field) {
            $this->sourceData=$original;
            if($field==='attributes')$this->sourceData['variants'][0]['attributes'][0]['value_name']='Changed';
            else $this->sourceData['variants'][0][$field]=$field==='id'?'199':'Changed';
            $this->assertSame('submitted_unverified',$this->step($run)['status'],$field);
            $this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));
        }
        $this->sourceData=$original;$this->snapshotData['mapping_conflicts']=true;
        $this->assertSame('submitted_unverified',$this->step($run)['status']);
        $this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));
        $this->assertSame('under_review',DB::table('tiktok_reconciliation_run_items')->value('status'));$this->assertSame(0,$this->edits);
    }
    public function test_stable_target_title_or_main_image_uri_change_still_invalidates_preparation(): void
    {
        $originalLive=$this->live;$originalReview=$this->review;
        foreach(['title','uri'] as $field) {
            $this->live=$originalLive;$this->review=$originalReview;
            $this->live['title']=$this->review['title']='Stable title';
            $this->live['main_images']=$this->review['main_images']=[['uri'=>'stable/image','urls'=>['https://cdn.test/old']]];
            $run=$this->start();while($run['can_continue']&&$run['status']!=='submitting')$run=$this->step($run);
            if($field==='title')$this->live['title']=$this->review['title']='Changed title';
            else $this->live['main_images'][0]['uri']=$this->review['main_images'][0]['uri']='changed/image';
            $this->assertSame('blocked',$this->step($run)['status'],$field);$this->assertSame(0,$this->edits);
        }
    }
    public function test_source_stock_and_price_movement_during_review_allows_exact_target_recovery(): void
    {
        $this->mode='review';$run=$this->finish($this->start());
        $this->sourceData['variants'][0]['stock']=2;$this->sourceData['variants'][0]['price']='14000';
        $this->review['audit']['status']='APPROVED';$this->live=$this->review;
        $this->assertSame('success',$this->step($run)['status']);
        $this->assertSame('Rose',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));
        $this->assertSame(7,$this->live['skus'][0]['inventory'][0]['quantity']);$this->assertSame('20000',$this->live['skus'][0]['price']['sale_price']);$this->assertSame(1,$this->edits);
    }
    public function test_historical_recovery_allows_source_stock_and_price_movement_after_send(): void
    {
        $id=(string)Str::uuid();
        $plan=$this->app->make(\App\Services\StockHubTiktokSourceReconciliationPlan::class)->build($this->sourceData,$this->snapshotData,$this->live,$this->review);
        $audit=['source'=>$this->sourceData,'before'=>$this->live,'plan'=>$plan];
        DB::table('tiktok_reconciliation_runs')->insert(['id'=>$id,'revision'=>str_repeat('a',64),'status'=>'under_review','summary'=>json_encode(['source_product_id'=>'10']),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('tiktok_reconciliation_run_items')->insert(['run_id'=>$id,'item_key'=>'900','action_type'=>'agni_source_reconcile','status'=>'under_review','source_fingerprint'=>str_repeat('a',64),'target_product_id'=>'900','payload'=>json_encode($audit),'created_at'=>now(),'updated_at'=>now()]);
        $this->review=[...$this->live,...$plan['payload']];$this->review['audit']['status']='AUDITING';$run=$this->step(['run_id'=>$id]);
        $this->sourceData['variants'][0]['stock']=2;$this->sourceData['variants'][0]['price']='14000';
        $this->review['audit']['status']='APPROVED';$this->live=$this->review;
        $this->assertSame('success',$this->step($run)['status']);$this->assertSame('Rose',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));
        $this->assertSame('verified',DB::table('tiktok_reconciliation_run_items')->value('status'));$this->assertSame(0,$this->edits);
    }
    public function test_postsend_guard_keeps_planned_fallback_image_identity(): void
    {
        foreach($this->sourceData['variants'] as &$variant)unset($variant['attributes'][0]['image_url']);unset($variant);
        $this->mode='review';$run=$this->finish($this->start());$this->review['audit']['status']='APPROVED';$this->live=$this->review;
        $this->sourceData['main_images'][0]='https://img.test/replaced-main.jpg';
        $this->assertSame('submitted_unverified',$this->step($run)['status']);$this->assertSame('Red',DB::table('tiktok_products')->where('sku_id','901')->value('sku_name'));$this->assertSame(1,$this->edits);
    }
}
