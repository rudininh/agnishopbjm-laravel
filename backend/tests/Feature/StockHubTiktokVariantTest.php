<?php

namespace Tests\Feature;

use App\Services\MarketplaceStockMirrorLease;
use App\Services\MarketplaceOperationBusy;
use App\Services\StockHubTiktokProductRejected;
use App\Services\StockHubTiktokVariantGateway;
use App\Services\StockHubTiktokVariantPayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class StockHubTiktokVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_typed_identity_error_offers_repair_but_new_other_errors_do_not_offer_legacy_check(): void
    {
        $this->targetData['skus'][0]['sales_attributes'][0]['value_name']='ROSE';
        $run=$this->finish($this->start());$this->assertSame('blocked',$run['status']);$this->assertTrue($run['repair_available']);$this->assertArrayHasKey('repair_check_available',$run);$this->assertFalse($run['repair_check_available']);
    }

    public function test_duplicate_target_seller_sku_offers_explicit_repair_without_uploading_or_editing(): void
    {
        $duplicate=$this->targetData['skus'][0];$duplicate['id']='902';
        $duplicate['sales_attributes'][0]['value_name']='BLUE';
        $this->targetData['skus'][]=$duplicate;
        $run=$this->finish($this->start());
        $this->assertSame('blocked',$run['status']);$this->assertTrue($run['repair_available']);
        $this->assertSame(0,$this->uploads);$this->assertSame(0,$this->edits);
    }

    public function test_legacy_unattempted_blocked_snapshot_offers_explicit_repair_assessment_locally(): void
    {
        $id=(string)Str::uuid();
        DB::table('stock_hub_tiktok_variant_runs')->insert(['id'=>$id,'request_key'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),'source_product_id'=>'10','target_product_id'=>'900','status'=>'blocked','state'=>json_encode(['message'=>'Existing sanitized failure']),'created_at'=>now(),'updated_at'=>now()]);
        $this->getJson($this->base.'/runs/'.$id)->assertOk()->assertJsonPath('data.repair_available',false)->assertJsonPath('data.repair_check_available',true);Http::assertNothingSent();
    }

    private string $base = '/api/stock-hub/tiktok-variants';
    private int $edits = 0;
    private int $uploads = 0;
    private string $mode = '';
    private ?\Throwable $verificationFailure = null;

    public function test_failed_status_check_replaces_stale_pending_feedback_and_never_replays_edit(): void
    {
        $this->mode='review';$run=$this->finish($this->start());$this->assertSame('under_review',$run['status']);
        $this->verificationFailure=new \RuntimeException('private signed URL and token');
        $run=$this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('submitted_unverified',$run['status']);
        $this->assertSame('unavailable',$run['verification']['outcome']??null);
        $this->assertNotEmpty($run['verification']['checked_at']??null);
        $this->assertStringNotContainsString('private',json_encode($run));
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk();$this->assertSame(1,$this->edits);
        $before=DB::table('stock_hub_tiktok_variant_runs')->first();
        $this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data.verification.outcome','unavailable');
        $this->assertEquals($before,DB::table('stock_hub_tiktok_variant_runs')->first());
    }
    private bool $busy = false;
    private bool $coordinationError = false;

    public function test_account_or_coordination_failure_before_readback_updates_latest_check_evidence(): void
    {
        $this->mode='review';$run=$this->finish($this->start());
        $checked=$run['verification']['checked_at'];$this->travel(2)->seconds();$this->coordinationError=true;
        $run=$this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('unavailable',$run['verification']['outcome']);
        $this->assertNotSame($checked,$run['verification']['checked_at']);$this->assertSame(1,$this->edits);
    }

    public function test_coverage_mismatch_preserves_run_identity_and_refreshes_evidence_without_resending(): void
    {
        $this->mode='review';$run=$this->finish($this->start());$id=$run['run_id'];
        $submitted=$this->sent['skus'];
        $this->verificationFailure=new \App\Services\StockHubTiktokVariantCoverageMismatch($submitted,[$submitted[0]],'APPROVED');
        $run=$this->postJson($this->base.'/runs/'.$id.'/step')->assertOk()->json('data');
        $this->assertSame('submitted_unverified',$run['status']);$this->assertFalse($run['can_retry']);
        $this->assertSame('mismatch',$run['verification']['outcome']);$this->assertSame(2,$run['verification']['missing_count']);
        $this->assertStringContainsString('1 varian',$run['message']);$this->assertNotEmpty($run['verification']['checked_at']);
        $this->assertSame('under_review',$run['result']['variants'][0]['status']); // History is retained; UI uses latest evidence.
        $this->verificationFailure=null;$this->mode='';
        $run=$this->postJson($this->base.'/runs/'.$id.'/step')->assertOk()->json('data');
        $this->assertSame('success',$run['status']);$this->assertSame('verified',$run['verification']['outcome']);
        $this->assertSame(0,$run['verification']['missing_count']);$this->assertSame([],$run['verification']['missing_variants']);
        $this->assertSame(1,$this->edits);$this->assertSame($id,$run['run_id']);$this->assertSame(19,DB::table('stock_master')->value('stock'));
    }
    private array $sent = [];
    private array $sourceData;
    private array $targetData;
    private ?\Closure $beforePrepareEdit = null;
    private int $continuationChecks = 0;
    private array $failedImages = [];
    private bool $recoveryBlocked = false;

    private function missingRun(): array
    {
        $this->mode='review';$run=$this->finish($this->start());
        $this->verificationFailure=new \App\Services\StockHubTiktokVariantCoverageMismatch($this->sent['skus'],[$this->sent['skus'][0]],'APPROVED');
        return $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
    }

    public function test_missing_recovery_creates_one_new_pinned_run_and_preserves_old_attempt(): void
    {
        $old=$this->missingRun();$this->assertTrue($old['can_add_missing']??false);
        $before=DB::table('stock_hub_tiktok_variant_runs')->where('id',$old['run_id'])->first();$key=(string)Str::uuid();
        $url=$this->base.'/runs/'.$old['run_id'].'/missing';
        $new=$this->postJson($url,['request_key'=>$key])->assertOk()->json('data');
        $this->assertNotSame($old['run_id'],$new['run_id']);$this->assertSame('preparing',$new['status']);$this->assertSame('900',$new['remote_product_id']);
        $this->postJson($url,['request_key'=>$key])->assertOk()->assertJsonPath('data.run_id',$new['run_id']);
        $this->assertSame(2,DB::table('stock_hub_tiktok_variant_runs')->count());$this->assertSame(1,$this->edits);
        $after=DB::table('stock_hub_tiktok_variant_runs')->where('id',$old['run_id'])->first();
        $this->assertSame($before->attempted_at,$after->attempted_at);$this->assertSame($before->accepted_at,$after->accepted_at);
        $this->assertSame($old['result'],json_decode($after->state,true)['result']);
        $this->getJson($this->base.'/source/10')->assertOk()->assertJsonPath('data.run_id',$new['run_id']);
        $this->postJson($this->base.'/runs/'.$old['run_id'].'/step')->assertOk();$this->assertSame(1,$this->edits);
        $this->verificationFailure=null;$this->mode='';$new=$this->finish($new);
        $this->assertSame('success',$new['status']);$this->assertSame(2,$this->edits);$this->assertSame(19,DB::table('stock_master')->value('stock'));
        \App\Services\StockHubTiktokVariantInterlock::assertAdditionAvailable('10','900');
    }

    public function test_missing_recovery_blocks_uncertain_transport_and_busy_claims_and_fresh_conflict(): void
    {
        $old=$this->missingRun();$url=$this->base.'/runs/'.$old['run_id'].'/missing';
        DB::table('stock_hub_tiktok_variant_runs')->where('id',$old['run_id'])->update(['accepted_at'=>null]);
        $this->postJson($url,['request_key'=>(string)Str::uuid()])->assertStatus(409);
        DB::table('stock_hub_tiktok_variant_runs')->where('id',$old['run_id'])->update(['accepted_at'=>now()]);
        DB::table('stock_hub_tiktok_variant_guards')->where('key','source:10')->update(['owner'=>(string)Str::uuid(),'expires_at'=>now()->addMinute()]);
        $this->postJson($url,['request_key'=>(string)Str::uuid()])->assertStatus(409);
        DB::table('stock_hub_tiktok_variant_guards')->where('key','source:10')->update(['owner'=>null,'expires_at'=>null]);
        $this->recoveryBlocked=true;
        $this->postJson($url,['request_key'=>(string)Str::uuid()])->assertOk()->assertJsonPath('data.status','submitted_unverified');
        $this->assertSame(1,DB::table('stock_hub_tiktok_variant_runs')->count());$this->assertSame(1,$this->edits);
        $this->assertNull(DB::table('stock_hub_tiktok_variant_guards')->where('key','source:10')->value('owner'));
    }

    public function test_missing_recovery_does_not_expand_to_new_source_rows_or_replace_retained_ids(): void
    {
        $old=$this->missingRun();$new=$this->postJson($this->base.'/runs/'.$old['run_id'].'/missing',['request_key'=>(string)Str::uuid()])->assertOk()->json('data');
        $this->targetData['skus'][0]['id']='999';
        $new=$this->finish($new);$this->assertSame('blocked',$new['status']);$this->assertSame(1,$this->edits);
        $this->targetData['skus'][0]['id']='901';
        $this->sourceData['variants'][]=[...$this->sourceData['variants'][1],'id'=>'104','seller_sku'=>'INT-10-YELLOW','name'=>'YELLOW','attributes'=>[['name'=>'Warna','value_name'=>'YELLOW']]];
        $new=$this->finish($this->start());$this->assertSame('blocked',$new['status']);$this->assertSame(1,$this->edits);
    }

    public function test_missing_recovery_rechecks_retained_ids_at_final_submit(): void
    {
        $old=$this->missingRun();$new=$this->postJson($this->base.'/runs/'.$old['run_id'].'/missing',['request_key'=>(string)Str::uuid()])->assertOk()->json('data');
        while($new['status']!=='submitting')$new=$this->postJson($this->base.'/runs/'.$new['run_id'].'/step')->assertOk()->json('data');
        $this->targetData['skus'][0]['id']='999';$new=$this->finish($new);
        $this->assertSame('blocked',$new['status']);$this->assertSame(1,$this->edits);
    }

    public function test_missing_recovery_blocks_unexpected_target_rows_after_handoff(): void
    {
        $old=$this->missingRun();$new=$this->postJson($this->base.'/runs/'.$old['run_id'].'/missing',['request_key'=>(string)Str::uuid()])->assertOk()->json('data');
        $extra=$this->targetData['skus'][0];$extra['id']='998';$extra['seller_sku']='OTHER';$extra['sales_attributes'][0]['value_name']='OTHER';$this->targetData['skus'][]=$extra;
        $new=$this->finish($new);$this->assertSame('blocked',$new['status']);$this->assertSame(1,$this->edits);
    }

    public function test_verified_agni_repair_after_blocked_recovery_allows_fresh_addition_plan(): void
    {
        $old=$this->missingRun();$new=$this->postJson($this->base.'/runs/'.$old['run_id'].'/missing',['request_key'=>(string)Str::uuid()])->assertOk()->json('data');
        $this->sourceData['variants'][0]['name']='Rose';$this->sourceData['variants'][0]['attributes'][0]['value_name']='Rose';
        $new=$this->finish($new);$this->assertSame('blocked',$new['status']);
        $this->targetData['skus'][0]['sales_attributes'][0]['value_name']='Rose';$this->travel(2)->seconds();
        $repairId=(string)Str::uuid();DB::table('stock_hub_tiktok_variant_repair_runs')->insert(['id'=>$repairId,'request_key'=>(string)Str::uuid(),'request_hash'=>str_repeat('a',64),
            'source_product_id'=>'10','target_product_id'=>'900','status'=>'success','state'=>'{}','attempted_at'=>now(),'accepted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('stock_hub_tiktok_variant_repair_guards')->insert(['key'=>'source:10','run_id'=>$repairId]);
        $this->verificationFailure=null;$this->mode='';$this->failedImages=['102','103'];
        $next=$this->finish($this->start());$this->assertSame('blocked',$next['status']);$this->failedImages=[];
        $next=$this->start();$saved=json_decode(DB::table('stock_hub_tiktok_variant_runs')->where('id',$next['run_id'])->value('state'),true);
        $this->assertSame('900',$saved['fixed_target_product_id']??null);$this->assertSame($repairId,$saved['after_verified_repair']??null);
        $next=$this->finish($next);
        $this->assertSame('success',$next['status']);$this->assertSame(2,$this->edits);
        $this->assertSame('Rose',$this->sent['skus'][0]['sales_attributes'][0]['value_name']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['shopee_mass_upload.stb_control_url' => '']);
        DB::table('shopee_tokens')->insert(['account_key' => 'shopee-agnishopbjm', 'shop_id' => 11, 'is_active' => true]);
        Schema::create('shopee_product', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('shop_id'); $t->boolean('is_active')->default(true); });
        Schema::create('shopee_product_model', function (Blueprint $t) { $t->id(); $t->string('item_id'); $t->string('model_id'); $t->string('model_sku'); $t->integer('stock')->default(9); });
        Schema::create('tiktok_products', function (Blueprint $t) { $t->id(); $t->string('product_id'); $t->string('sku_id'); $t->string('seller_sku'); $t->boolean('is_active')->default(true); });
        Schema::create('stock_master', function (Blueprint $t) { $t->id(); $t->integer('stock'); });
        DB::table('stock_master')->insert(['stock' => 19]);
        DB::table('shopee_product')->insert(['item_id' => '10', 'shop_id' => '11']);
        $this->sourceData = ['id' => '10', 'shop_id' => '11', 'title' => 'Agni product', 'main_images' => ['https://img.test/main.jpg'], 'variants' => []];
        foreach (['RED' => 7, 'BLUE' => 0, 'GREEN' => 4] as $name => $stock) {
            $id = (string) (101 + count($this->sourceData['variants']));
            $this->sourceData['variants'][] = ['id' => $id, 'name' => $name, 'seller_sku' => 'INT-10-'.$name, 'stock' => $stock,
                'price' => '25000', 'attributes' => [['name' => 'Warna', 'value_name' => $name, 'image_url' => 'https://img.test/'.$name.'.jpg']]];
            DB::table('shopee_product_model')->insert(['item_id' => '10', 'model_id' => $id, 'model_sku' => 'INT-10-'.$name, 'stock' => $stock]);
        }
        DB::table('tiktok_products')->insert(['product_id' => '900', 'sku_id' => '901', 'seller_sku' => 'INT-10-RED']);
        $this->targetData = ['id' => '900', 'title' => 'Agni product', 'status' => 'ACTIVATE', 'audit' => ['status' => 'APPROVED'],
            'package_weight' => ['value' => '0.2', 'unit' => 'KILOGRAM'],
            'package_dimensions' => ['length' => '20', 'width' => '15', 'height' => '3', 'unit' => 'CENTIMETER'],
            'skus' => [['id' => '901', 'seller_sku' => 'INT-10-RED', 'price' => ['sale_price' => '25000', 'currency' => 'IDR'],
                'inventory' => [['warehouse_id' => 'wh', 'quantity' => 7]], 'sales_attributes' => [['id' => '100', 'name' => 'Warna', 'value_name' => 'RED']]]]];
        if (class_exists(StockHubTiktokVariantGateway::class)) {
            $gateway = Mockery::mock(StockHubTiktokVariantGateway::class);
            $gateway->shouldReceive('source')->andReturnUsing(fn () => $this->sourceData);
            $gateway->shouldReceive('targetShop')->andReturn('33');
            $gateway->shouldReceive('assertTargetShop')->andReturnNull();
            $gateway->shouldReceive('target')->andReturnUsing(fn () => $this->targetData);
            $gateway->shouldReceive('reconcileMissing')->andReturnUsing(function($source,$expected){
                if($this->recoveryBlocked)throw new \DomainException('Produk masih dalam peninjauan.');
                $skus=array_column($this->targetData['skus'],'seller_sku');
                return ['target'=>$this->targetData,'missing'=>array_values(array_filter($expected['variants'],fn($v)=>!in_array($v['seller_sku'],$skus,true)))];
            });
            $gateway->shouldReceive('page')->andReturn(['ids' => ['900', '910'], 'next' => null, 'total' => 2]);
            $gateway->shouldReceive('catalogTarget')->andReturnUsing(fn ($id) => $id === '900' ? $this->targetData : ['id' => '910', 'skus' => [['id' => '911', 'seller_sku' => $this->mode === 'conflict' ? 'INT-10-BLUE' : 'OTHER']]]);
            $gateway->shouldReceive('upload')->andReturnUsing(function ($variant) { $this->uploads++; if (($this->mode === 'image' && $variant['id'] === '103') || in_array($variant['id'],$this->failedImages,true)) { throw new \DomainException('Gambar varian gagal.'); } return 'tt-uri-'.$variant['id']; });
            $gateway->shouldReceive('prepareEdit')->andReturnUsing(function () {if($this->beforePrepareEdit)($this->beforePrepareEdit)();return function ($payload) {
                $this->edits++; $this->sent = $payload;
                $this->assertNotNull(DB::table('stock_hub_tiktok_variant_runs')->where('status', 'submitting')->value('attempted_at'));
                if ($this->mode === 'unknown') { throw new \RuntimeException('signed secret'); }
                if ($this->mode === 'rejected') { throw new StockHubTiktokProductRejected('TikTok menolak varian (kode 123).'); }
                return [];
            };});
            $gateway->shouldReceive('verify')->andReturnUsing(function ($source, $expected, $id) {
                if($this->verificationFailure)throw $this->verificationFailure;
                if ($this->mode === 'unknown') { throw new \RuntimeException('signed secret'); }
                if ($this->mode === 'stock_drift') { throw new \App\Services\StockHubTiktokVariantStockMismatch(); }
                return ['product_id' => $id, 'published' => $this->mode !== 'review', 'price' => $expected['price'],
                    'variants' => array_map(fn ($v) => ['source_variant_id' => $v['id'], 'seller_sku' => $v['seller_sku'], 'name' => $v['name'], 'target_sku_id'=>'9'.$v['id'], 'status' => $this->mode === 'review' ? 'under_review' : 'updated', 'reason' => null], $expected['variants'])];
            });
            $gateway->shouldReceive('verifyContinuation')->andReturnUsing(function($source,$expected,$id,$accepted){
                $this->continuationChecks++;
                foreach($accepted as $prior){$matches=array_values(array_filter($this->targetData['skus'],fn($sku)=>$sku['seller_sku']===$prior['seller_sku']&&$sku['id']===$prior['target_sku_id']));if(count($matches)!==1)throw new \DomainException('Prior accepted identity changed.');}
                return ['published'=>true];
            });
            $this->app->instance(StockHubTiktokVariantGateway::class, $gateway);
        }
        $lease = Mockery::mock(MarketplaceStockMirrorLease::class);
        $lease->shouldReceive('acquire')->andReturnUsing(function () { if($this->coordinationError)throw new \RuntimeException('private coordination failure');if ($this->busy) { throw new MarketplaceOperationBusy(); } });
        $lease->shouldReceive('renew')->andReturnNull(); $lease->shouldReceive('release')->andReturnNull();
        $this->app->instance(MarketplaceStockMirrorLease::class, $lease);
    }

    private function start(array $extra = []): array
    {
        return $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => (string) Str::uuid(), ...$extra])->assertOk()->json('data');
    }

    private function finish(array $run): array
    {
        for ($i = 0; $i < 25 && $run['can_continue']; $i++) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        return $run;
    }

    public function test_retirement_refuses_live_worker_and_attempted_children_and_stale_sender_cannot_send():void
    {
        $run=$this->start();while($run['status']!=='submitting')$run=$this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        DB::table('stock_hub_tiktok_variant_guards')->where('key','source:10')->update(['owner'=>'live-worker','expires_at'=>now()->addMinute()]);
        $this->assertSame('busy',\App\Services\StockHubTiktokVariantInterlock::retireUnattempted('addition',$run['run_id'],'10'));
        $this->assertSame('submitting',DB::table('stock_hub_tiktok_variant_runs')->where('id',$run['run_id'])->value('status'));
        DB::table('stock_hub_tiktok_variant_guards')->where('key','source:10')->update(['owner'=>null,'expires_at'=>null]);
        $this->beforePrepareEdit=function()use($run){DB::table('stock_hub_tiktok_variant_guards')->where('key','source:10')->update(['expires_at'=>now()->subSecond()]);$this->assertSame('retired',\App\Services\StockHubTiktokVariantInterlock::retireUnattempted('addition',$run['run_id'],'10'));};
        $run=$this->finish($run);$this->assertSame('blocked',$run['status']);$this->assertSame(0,$this->edits);$this->assertNull(DB::table('stock_hub_tiktok_variant_runs')->where('id',$run['run_id'])->value('attempted_at'));
        $this->beforePrepareEdit=null;$this->mode='unknown';$attempt=$this->finish($this->start());
        $this->assertSame('attempted',\App\Services\StockHubTiktokVariantInterlock::retireUnattempted('addition',$attempt['run_id'],'10'));$this->assertSame(1,$this->edits);
    }

    private function partialWithAcceptedBlue():array
    {
        $this->mode='image';$prior=$this->finish($this->start());$this->assertSame('partial',$prior['status']);
        $blue=$this->sent['skus'][1];$blue['id']='9102';$this->targetData['skus'][]=$blue;$this->mode='';return $prior;
    }
    public function test_upgrade_of_real_legacy_submitting_child_waits_for_source_and_repair_verification():void
    {
        $this->sourceData['variants'][0]['seller_sku']='INT-10-STALE';$this->targetData['skus'][0]['seller_sku']='INT-10-STALE';
        DB::table('shopee_product_model')->where('model_id','101')->update(['model_sku'=>'INT-10-STALE']);DB::table('tiktok_products')->update(['seller_sku'=>'INT-10-STALE']);
        $old=$this->start();for($i=0;$i<25&&$old['status']!=='submitting';$i++)$old=$this->postJson($this->base.'/runs/'.$old['run_id'].'/step')->assertOk()->json('data');$this->assertSame('submitting',$old['status']);
        $selection=Mockery::mock(\App\Services\StockHubTiktokVariantBatchSelection::class);$selection->shouldReceive('assertReady')->andReturnNull();$selection->shouldReceive('forProducts')->andReturn([['source_product_id'=>'10','name'=>'Fixture','coverage'=>['state'=>'missing_variants','missing_count'=>2]]]);$this->app->instance(\App\Services\StockHubTiktokVariantBatchSelection::class,$selection);
        $verified=['source_sku'=>false,'repair'=>false];
        foreach(['source_sku'=>\App\Services\StockHubAgniSkuService::class,'repair'=>\App\Services\StockHubTiktokVariantRepairService::class] as $kind=>$class){
            $mock=Mockery::mock($class);$mock->shouldReceive('source')->andReturnNull();
            $mock->shouldReceive('show')->andReturnUsing(function($id)use($kind){
                $table=$kind==='source_sku'?'stock_hub_agni_sku_runs':'stock_hub_tiktok_variant_repair_runs';$row=DB::table($table)->where('id',$id)->first();
                return ['run_id'=>$id,'source_product_id'=>$row->source_product_id,'status'=>$row->status,'can_continue'=>false,'can_retry'=>true,'result'=>null];
            });
            if($kind==='source_sku')$mock->shouldReceive('assertVerified')->andReturnUsing(function()use(&$verified){$this->assertTrue($verified['source_sku']);});
            $mock->shouldReceive('start')->andReturnUsing(function($source,$key)use($kind,&$verified){
                $this->assertSame(0,$this->edits);if($kind==='repair')$this->assertTrue($verified['source_sku']);
                if($kind==='source_sku'){$this->sourceData['variants'][0]['seller_sku']='INT-10-RED';DB::table('shopee_product_model')->where('model_id','101')->update(['model_sku'=>'INT-10-RED']);}
                else{$this->targetData['skus'][0]['seller_sku']='INT-10-RED';DB::table('tiktok_products')->update(['seller_sku'=>'INT-10-RED']);}
                $verified[$kind]=true;$id=(string)Str::uuid();$table=$kind==='source_sku'?'stock_hub_agni_sku_runs':'stock_hub_tiktok_variant_repair_runs';
                $row=['id'=>$id,'request_key'=>$key,'source_product_id'=>$source,'target_product_id'=>'900','status'=>'exists','state'=>'{}','created_at'=>now(),'updated_at'=>now()];if($kind==='repair')$row['request_hash']=hash('sha256',$source);DB::table($table)->insert($row);
                return ['run_id'=>$id,'source_product_id'=>$source,'status'=>'exists','can_continue'=>false,'can_retry'=>true,'result'=>null];
            });$this->app->instance($class,$mock);
        }
        $batch=app(\App\Services\StockHubTiktokVariantBatchService::class);$run=$batch->start((string)Str::uuid());$run=$batch->step($run['run_id']);$this->assertSame($old['run_id'],$run['items'][0]['child_run_id']);
        $batch->pause($run['run_id']);$run=$batch->resume($run['run_id'],'agni_sku_repair_then_tiktok');
        $this->beforePrepareEdit=function()use(&$verified){$this->assertSame(['source_sku'=>true,'repair'=>true],$verified);};
        for($i=0;$i<50&&$run['can_continue'];$i++)$run=$batch->step($run['run_id']);
        $this->assertSame('success',$run['items'][0]['status']);$this->assertSame(1,$this->edits);$this->assertNotSame($old['run_id'],$run['items'][0]['child_run_id']);
        $retired=DB::table('stock_hub_tiktok_variant_runs')->where('id',$old['run_id'])->first();$this->assertNull($retired->attempted_at);$this->assertSame('INT-10-STALE',json_decode($retired->state,true)['source']['variants'][0]['seller_sku']);Http::assertNothingSent();
    }
    public function test_partial_continuation_only_sends_remaining_variant_and_rechecks_prior_subset():void
    {
        $prior=$this->partialWithAcceptedBlue();$service=app(\App\Services\StockHubTiktokVariantService::class);
        $next=$service->startRemaining('10',$prior['run_id'],(string)Str::uuid(),[]);$next=$this->finish($next);
        $this->assertSame('success',$next['status'],$next['message']);$this->assertSame(2,$this->edits);$this->assertGreaterThanOrEqual(3,$this->continuationChecks);
        $this->assertSame(['INT-10-GREEN'],array_column(array_filter($this->sent['skus'],fn($sku)=>!isset($sku['id'])),'seller_sku'));
        $this->assertSame('partial',$service->show($prior['run_id'])['status']);
    }
    public function test_partial_continuation_blocks_if_prior_accepted_sku_disappears_before_submit():void
    {
        $prior=$this->partialWithAcceptedBlue();$service=app(\App\Services\StockHubTiktokVariantService::class);
        $next=$service->startRemaining('10',$prior['run_id'],(string)Str::uuid(),[]);
        while($next['status']!=='submitting'&&$next['can_continue'])$next=$service->step($next['run_id']);
        $this->targetData['skus']=array_slice($this->targetData['skus'],0,1);$next=$service->step($next['run_id']);
        $this->assertSame('blocked',$next['status']);$this->assertSame(1,$this->edits);$this->assertNull(DB::table('stock_hub_tiktok_variant_runs')->where('id',$next['run_id'])->value('attempted_at'));
    }

    private function unsentPartialAfterAcceptedBlue():array
    {
        $white=$this->sourceData['variants'][2];$white['id']='104';$white['name']='WHITE';$white['seller_sku']='INT-10-WHITE';$white['stock']=2;$white['attributes'][0]['value_name']='WHITE';
        $this->sourceData['variants'][]=$white;DB::table('shopee_product_model')->insert(['item_id'=>'10','model_id'=>'104','model_sku'=>'INT-10-WHITE','stock'=>2]);
        $this->failedImages=['103','104'];$original=$this->partialWithAcceptedBlue();$this->failedImages=['103'];
        $service=app(\App\Services\StockHubTiktokVariantService::class);$middle=$service->startRemaining('10',$original['run_id'],(string)Str::uuid());
        for($i=0;$i<25&&$middle['status']!=='submitting'&&$middle['can_continue'];$i++)$middle=$service->step($middle['run_id']);$this->assertSame('submitting',$middle['status']);
        $observed=$this->targetData['skus'][0];$observed['id']='9104';$observed['seller_sku']='INT-10-WHITE';$observed['sales_attributes'][0]['value_name']='WHITE';$observed['inventory'][0]['quantity']=2;$this->targetData['skus'][]=$observed;
        $middle=$service->step($middle['run_id']);$this->assertSame('partial',$middle['status']);$this->assertSame(['failed','skipped'],array_column($middle['result']['variants'],'status'));
        $row=DB::table('stock_hub_tiktok_variant_runs')->where('id',$middle['run_id'])->first();$this->assertNull($row->attempted_at);$this->assertNull($row->accepted_at);$this->assertSame($original['run_id'],$middle['context']['prior_partial_run_id']);$this->assertSame(1,$this->edits);
        $this->failedImages=[];return [$original,$middle];
    }

    public static function acceptedAncestorLossStages():array { return [['start'],['prepare'],['submit']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedAncestorLossStages')]
    public function test_unsent_partial_retains_original_accepted_ancestor_protection(string $stage):void
    {
        [$original,$middle]=$this->unsentPartialAfterAcceptedBlue();$service=app(\App\Services\StockHubTiktokVariantService::class);
        $originalState=DB::table('stock_hub_tiktok_variant_runs')->where('id',$original['run_id'])->value('state');$middleState=DB::table('stock_hub_tiktok_variant_runs')->where('id',$middle['run_id'])->value('state');
        if($stage!=='start')$next=$service->startRemaining('10',$middle['run_id'],(string)Str::uuid());
        if($stage==='submit'){for($i=0;$i<25&&$next['status']!=='submitting'&&$next['can_continue'];$i++)$next=$service->step($next['run_id']);$this->assertSame('submitting',$next['status']);}
        if($stage==='submit'){foreach($this->targetData['skus'] as &$sku)if($sku['id']==='9102')$sku['id']='9902';unset($sku);}
        else $this->targetData['skus']=array_values(array_filter($this->targetData['skus'],fn($sku)=>$sku['id']!=='9102'));
        if($stage==='start'){
            try{$service->startRemaining('10',$middle['run_id'],(string)Str::uuid());$this->fail('Missing original accepted ID must block a two-hop continuation.');}catch(\DomainException $e){$this->assertStringContainsString('identity',$e->getMessage());}
            $this->assertSame(2,DB::table('stock_hub_tiktok_variant_runs')->count());
        }else{$next=$service->step($next['run_id']);$this->assertSame('blocked',$next['status']);$this->assertNull(DB::table('stock_hub_tiktok_variant_runs')->where('id',$next['run_id'])->value('attempted_at'));}
        $this->assertSame(1,$this->edits);$this->assertSame($originalState,DB::table('stock_hub_tiktok_variant_runs')->where('id',$original['run_id'])->value('state'));$this->assertSame($middleState,DB::table('stock_hub_tiktok_variant_runs')->where('id',$middle['run_id'])->value('state'));Http::assertNothingSent();
    }

    public function test_unsent_partial_with_intact_accepted_ancestor_can_add_remaining_variant():void
    {
        [, $middle]=$this->unsentPartialAfterAcceptedBlue();$service=app(\App\Services\StockHubTiktokVariantService::class);$next=$this->finish($service->startRemaining('10',$middle['run_id'],(string)Str::uuid()));
        $this->assertSame('success',$next['status']);$this->assertSame(2,$this->edits);$this->assertSame(['INT-10-GREEN'],array_column(array_filter($this->sent['skus'],fn($sku)=>!isset($sku['id'])),'seller_sku'));
    }

    public static function invalidPartialLineages():array { return [['null'],['empty'],['array'],['missing'],['self_cycle'],['two_cycle'],['too_deep'],['wrong_source'],['wrong_target'],['non_partial']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPartialLineages')]
    public function test_malformed_or_cyclic_partial_lineage_blocks_without_creating_work(string $kind):void
    {
        $id=(string)Str::uuid();$other=(string)Str::uuid();$reference=match($kind){'null'=>null,'empty'=>'','array'=>[$other],'self_cycle'=>$id,default=>$other};
        $state=['context'=>['prior_partial_run_id'=>$reference],'result'=>['variants'=>[['status'=>'failed','seller_sku'=>'INT-10-GREEN']]]];
        $row=['id'=>$id,'request_key'=>(string)Str::uuid(),'request_hash'=>hash('sha256','10'),'source_product_id'=>'10','target_product_id'=>'900','status'=>'partial','state'=>json_encode($state),'created_at'=>now(),'updated_at'=>now()];DB::table('stock_hub_tiktok_variant_runs')->insert($row);
        if(in_array($kind,['two_cycle','too_deep','wrong_source','wrong_target','non_partial'],true)){
            $ancestor=$row;$ancestor['id']=$other;$ancestor['request_key']=(string)Str::uuid();$ancestor['state']=json_encode(['context'=>$kind==='two_cycle'?['prior_partial_run_id'=>$id]:[],'result'=>['variants'=>[['status'=>'failed']]]]);
            if($kind==='wrong_source')$ancestor['source_product_id']='20';if($kind==='wrong_target')$ancestor['target_product_id']='901';if($kind==='non_partial')$ancestor['status']='success';
            DB::table('stock_hub_tiktok_variant_runs')->insert($ancestor);
            if($kind==='too_deep')for($i=0;$i<64;$i++){$new=(string)Str::uuid();DB::table('stock_hub_tiktok_variant_runs')->where('id',$other)->update(['state'=>json_encode(['context'=>['prior_partial_run_id'=>$new]])]);$ancestor['id']=$new;$ancestor['request_key']=(string)Str::uuid();DB::table('stock_hub_tiktok_variant_runs')->insert($ancestor);$other=$new;}
        }
        $count=DB::table('stock_hub_tiktok_variant_runs')->count();
        try{app(\App\Services\StockHubTiktokVariantService::class)->startRemaining('10',$id,(string)Str::uuid());$this->fail('Unsafe lineage must fail closed.');}catch(\DomainException $e){$this->assertNotSame('',$e->getMessage());}
        $this->assertSame($count,DB::table('stock_hub_tiktok_variant_runs')->count());$this->assertSame(0,$this->edits);$this->assertSame(0,$this->continuationChecks);Http::assertNothingSent();
    }

    public function test_one_product_batch_adds_all_missing_variants_without_source_quantity_writes(): void
    {
        $before = DB::table('shopee_product_model')->get()->toJson();
        $run = $this->finish($this->start());
        $this->assertSame('success', $run['status']);
        $this->assertSame(1, $this->edits); $this->assertSame(2, $this->uploads);
        $this->assertSame(['INT-10-RED', 'INT-10-BLUE', 'INT-10-GREEN'], array_column($this->sent['skus'], 'seller_sku'));
        $this->assertSame([7, 0, 4], array_map(fn ($s) => $s['inventory'][0]['quantity'], $this->sent['skus']));
        $this->assertSame($before, DB::table('shopee_product_model')->get()->toJson());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
        $this->assertSame(2, count($run['result']['variants']));
        Http::assertNothingSent();
    }

    public function test_shared_template_repairs_all_skus_and_freezes_values_before_upload(): void
    {
        foreach (['length','width','height'] as $key) { $this->targetData['package_dimensions'][$key] = '0'; }
        $this->sourceData['weight'] = 0.2;
        $this->sourceData['dimension'] = ['package_length' => 0, 'package_width' => 0, 'package_height' => 0];
        $before = DB::table('shopee_product_model')->get()->toJson();
        $run = $this->start();
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('scanning', $run['status']);
        config(['stock_hub_package.dimensions.length' => '99']);
        $run = $this->finish($run);
        $this->assertSame('success', $run['status']);
        $this->assertSame(1, $this->edits);
        $this->assertSame(['length' => '25', 'width' => '25', 'height' => '10', 'unit' => 'CENTIMETER'], $this->sent['package_dimensions']);
        $this->assertArrayNotHasKey('package_weight', $this->sent);
        foreach ($this->sent['skus'] as $sku) {
            $this->assertSame('25', $sku['sku_dimensions']['length']);
            $this->assertSame('0.2', $sku['sku_weight']['value']);
        }
        $this->assertTrue($run['package_template_applied']);
        $this->assertSame($before, DB::table('shopee_product_model')->get()->toJson());
        $this->assertSame(19, DB::table('stock_master')->value('stock'));
    }

    public function test_uncertain_template_edit_is_not_replayed_after_config_changes_or_retries(): void
    {
        foreach (['length','width','height'] as $key) { $this->targetData['package_dimensions'][$key] = '0'; }
        $this->mode = 'unknown';
        $run = $this->finish($this->start());
        $this->assertSame('submitted_unverified', $run['status']);
        config(['stock_hub_package.dimensions.length' => '99']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk();
        $this->assertSame(1, $this->edits);
        $this->assertSame('25', $this->sent['package_dimensions']['length']);
    }

    public function test_coordination_infrastructure_failure_has_fixed_marker_and_no_gateway_progress(): void
    {
        $lease = Mockery::mock(MarketplaceStockMirrorLease::class);
        $lease->shouldReceive('acquire')->andThrow(new \RuntimeException('private coordination diagnostic'));
        $lease->shouldReceive('release')->andReturnNull();
        $this->app->instance(MarketplaceStockMirrorLease::class, $lease);
        $gateway = Mockery::mock(StockHubTiktokVariantGateway::class);
        $gateway->shouldNotReceive('source'); $gateway->shouldNotReceive('prepareEdit');
        $this->app->instance(StockHubTiktokVariantGateway::class, $gateway);
        $run = $this->start();
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('blocked', $run['status']);
        $this->assertSame('coordination_unavailable', $run['failure_kind'] ?? null);
        $this->assertStringNotContainsString('private', $run['message']);
        $this->assertNull(DB::table('stock_hub_tiktok_variant_runs')->value('attempted_at'));
        Http::assertNothingSent();
    }

    public function test_repeated_request_and_unknown_attempt_never_replay(): void
    {
        $key = (string) Str::uuid(); $payload = ['source_product_id' => '10', 'request_key' => $key];
        $first = $this->postJson($this->base.'/runs', $payload)->assertOk()->json('data');
        $this->assertSame($first['run_id'], $this->postJson($this->base.'/runs', $payload)->assertOk()->json('data.run_id'));
        $this->mode = 'unknown'; $run = $this->finish($first);
        $this->assertSame('submitted_unverified', $run['status']); $this->assertFalse($run['can_retry']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk();
        $this->assertSame(1, $this->edits);
        $this->assertStringNotContainsString('secret', json_encode($run));
    }

    public function test_stock_drift_recovery_explains_the_blocker_without_replaying_edit(): void
    {
        $this->mode = 'stock_drift'; $run = $this->finish($this->start());
        $this->assertSame('submitted_unverified', $run['status']);
        $this->assertStringContainsString('Stok TikTok berbeda', $run['message']);
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertStringContainsString('Stok TikTok berbeda', $run['message']);
        $this->assertSame(1, $this->edits);
        $this->assertFalse($run['can_retry']);
    }

    public function test_busy_marketplace_keeps_same_run_and_makes_no_gateway_progress_during_cooldown(): void
    {
        $this->freezeTime(); $this->busy = true; $run = $this->start();
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('preparing', $run['status']); $this->assertSame(5000, $run['next_step_after_ms']);
        $this->busy = false;
        $this->assertSame($run, $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'));
        $this->travel(5)->seconds();
        $this->assertSame('success', $this->finish($run)['status']);
    }

    public function test_sku_found_in_another_product_blocks_before_upload_or_edit(): void
    {
        $this->mode = 'conflict'; $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']);
        $this->assertStringContainsString('produk TikTok lain', $run['message']);
        $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
    }

    public function test_partial_image_preparation_is_reported_per_variant_and_submitted_once(): void
    {
        $this->mode = 'image'; $run = $this->finish($this->start());
        $this->assertSame('partial', $run['status']); $this->assertSame(1, $this->edits);
        $this->assertSame(['INT-10-RED', 'INT-10-BLUE'], array_column($this->sent['skus'], 'seller_sku'));
        $this->assertEqualsCanonicalizing(['updated', 'failed'], array_column($run['result']['variants'], 'status'));
    }

    public function test_pending_review_only_reads_status_and_blocks_new_batch_until_published(): void
    {
        $this->mode = 'review'; $run = $this->finish($this->start());
        $this->assertSame('under_review', $run['status']); $this->assertFalse($run['can_retry']);
        $this->assertSame($run['run_id'], $this->start()['run_id']);
        $this->mode = '';
        $this->assertSame('success', $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data.status'));
        $this->assertSame(1, $this->edits);
    }

    public function test_source_changes_and_catalog_revision_changes_stop_unattempted_submission(): void
    {
        $run = $this->start();
        while ($run['status'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        $this->sourceData['variants'][1]['seller_sku'] = 'INT-10-CHANGED';
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->edits);
    }

    public function test_input_account_switch_and_invalid_manual_price_are_rejected(): void
    {
        foreach ([['source_account' => 'shopee-gitacollectionbjm'], ['target_account' => 'shopee-gitacollectionbjm'], ['context' => ['manual_price' => 0]], ['context' => ['manual_price' => 12.5]]] as $extra) {
            $this->postJson($this->base.'/runs', ['source_product_id' => '10', 'request_key' => (string) Str::uuid(), ...$extra])->assertStatus(422);
        }
        $this->assertSame(0, $this->edits);
    }

    public function test_legacy_entry_cannot_bypass_an_unresolved_new_batch(): void
    {
        $this->mode = 'unknown'; $run = $this->finish($this->start());
        $this->assertSame('submitted_unverified', $run['status']);
        try { \App\Services\StockHubTiktokVariantInterlock::assertLegacyAvailable('900'); $this->fail('Unresolved target must stay guarded.'); }
        catch (\DomainException) { $this->assertSame(1, $this->edits); }
    }

    public function test_legacy_uncertain_attempt_blocks_both_entry_points_until_skus_are_observed(): void
    {
        DB::table('stock_hub_tiktok_variant_legacy_attempts')->insert(['id' => (string) Str::uuid(), 'target_product_id' => '900', 'status' => 'attempted', 'seller_skus' => json_encode(['INT-10-BLUE']), 'created_at' => now(), 'updated_at' => now()]);
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
        $this->targetData['skus'][] = ['id' => '902', 'seller_sku' => 'INT-10-BLUE', 'price' => ['sale_price' => '25000', 'currency' => 'IDR'],
            'inventory' => [['warehouse_id' => 'wh', 'quantity' => 0]], 'sales_attributes' => [['id' => '100', 'name' => 'Warna', 'value_name' => 'BLUE']]];
        $run = $this->finish($this->start());
        $this->assertSame('success', $run['status']); $this->assertSame(1, $this->edits);
        $this->assertSame('resolved', DB::table('stock_hub_tiktok_variant_legacy_attempts')->value('status'));
    }

    public function test_cached_product_id_cannot_authorize_edits_to_a_fresh_unrelated_product(): void
    {
        $this->targetData['skus'][0]['seller_sku'] = 'INT-20-OTHER';
        $this->targetData['skus'][0]['sales_attributes'][0]['value_name'] = 'OTHER';
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
    }

    public function test_explicit_remote_rejection_allows_only_a_deliberate_new_run(): void
    {
        $this->mode = 'rejected'; $run = $this->finish($this->start());
        $this->assertSame('rejected', $run['status']); $this->assertTrue($run['can_retry']);
        $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk();
        $this->assertSame(1, $this->edits);
        $this->mode = ''; $next = $this->start(); $this->assertNotSame($run['run_id'], $next['run_id']);
        $this->assertSame('success', $this->finish($next)['status']); $this->assertSame(2, $this->edits);
    }

    public function test_legacy_mutation_revision_invalidates_the_completed_scan(): void
    {
        $run = $this->start();
        while ($run['status'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        DB::table('marketplace_catalog_mutation_revision')->where('id', 1)->increment('revision');
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->edits);
    }
    private function appendTarget(int $sourceIndex, string $price = '25000'): void
    {
        $v = $this->sourceData['variants'][$sourceIndex];
        $sku = $this->targetData['skus'][0];
        $sku['id'] = (string) (902 + $sourceIndex);
        $sku['seller_sku'] = $v['seller_sku'];
        $sku['price']['sale_price'] = $price;
        $sku['inventory'][0]['quantity'] = $v['stock'];
        $sku['sales_attributes'][0]['value_name'] = $v['name'];
        $this->targetData['skus'][] = $sku;
    }

    public function test_complete_target_needs_no_manual_price_even_with_tied_prices(): void
    {
        $this->appendTarget(1, '30000'); $this->appendTarget(2, '35000');
        $run = $this->finish($this->start());
        $this->assertSame('exists', $run['status']); $this->assertSame(0, $this->edits);
    }

    public function test_manual_price_correction_is_a_deliberate_idempotent_request(): void
    {
        $this->appendTarget(1, '30000');
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']);
        $this->assertSame('manual_price', $run['required_fields'][0]['key']);
        $key = (string) Str::uuid(); $payload = ['source_product_id' => '10', 'request_key' => $key, 'context' => ['manual_price' => 28000]];
        $next = $this->postJson($this->base.'/runs', $payload)->assertOk()->json('data');
        $this->assertNotSame($run['run_id'], $next['run_id']);
        $this->assertSame($next['run_id'], $this->postJson($this->base.'/runs', $payload)->assertOk()->json('data.run_id'));
        $this->postJson($this->base.'/runs', [...$payload, 'context' => ['manual_price' => 29000]])->assertStatus(409);
        $this->assertSame('success', $this->finish($next)['status']);
        $this->assertSame('28000', $this->sent['skus'][2]['price']['amount']);
    }

    public function test_fresh_target_custom_sku_owned_by_another_agni_product_blocks(): void
    {
        DB::table('shopee_product')->insert(['item_id' => '20', 'shop_id' => '11']);
        DB::table('shopee_product_model')->insert(['item_id' => '20', 'model_id' => '201', 'model_sku' => 'CUSTOM-OTHER']);
        $other = $this->targetData['skus'][0]; $other['id'] = '999'; $other['seller_sku'] = 'CUSTOM-OTHER';
        $other['sales_attributes'][0]['value_name'] = 'OTHER'; $this->targetData['skus'][] = $other;
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
    }

    public function test_external_arrival_of_prepared_variant_does_not_hide_failed_image_variant(): void
    {
        $this->mode = 'image'; $run = $this->start();
        while ($run['status'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        $this->appendTarget(1);
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('partial', $run['status']); $this->assertSame(0, $this->edits);
        $this->assertSame('failed', $run['result']['variants'][0]['status']);
        $this->assertSame('INT-10-GREEN', $run['result']['variants'][0]['seller_sku']);
    }

    public function test_historical_uncertain_bulk_attempt_cannot_replay_through_legacy_entry(): void
    {
        Schema::table('stock_master', fn (Blueprint $t) => $t->string('shopee_product_id')->nullable());
        DB::table('stock_master')->update(['shopee_product_id' => '10']);
        Schema::create('sku_variant_actions', function (Blueprint $t) { $t->id(); $t->integer('stock_master_id'); $t->string('target_channel'); $t->string('action_type'); $t->string('status'); $t->json('payload'); });
        DB::table('sku_variant_actions')->insert(['stock_master_id' => DB::table('stock_master')->value('id'), 'target_channel' => 'tiktok', 'action_type' => 'bulk_create_variant', 'status' => 'submitted_unverified',
            'payload' => json_encode(['mutation' => ['request' => ['path' => '/product/202509/products/900/partial_edit']], 'outcome' => ['seller_sku' => 'INT-10-BLUE']])]);
        $this->assertSame('blocked', $this->finish($this->start())['status']);
        try { \App\Services\StockHubTiktokVariantInterlock::beginLegacy('900', ['INT-10-BLUE'], $this->targetData); $this->fail('Historical uncertain legacy edit must remain guarded.'); }
        catch (\DomainException) { $this->assertSame(0, DB::table('stock_hub_tiktok_variant_legacy_attempts')->count()); }
    }

    public function test_legacy_ambiguous_responses_retain_guard_and_never_replay(): void
    {
        $controller = new class extends \App\Http\Controllers\OmnichannelController {
            public array $reply = []; public int $calls = 0;
            protected function submitTiktokBulkPartialEditPayload(string $path, array $payload, array $context): array { $this->calls++; return $this->reply; }
            protected function verifyBulkTiktokVariantGroup(string $productId, array $sellerSkus): array { return ['sync' => ['status' => 'error'], 'found_seller_skus' => []]; }
        };
        $method = (new \ReflectionClass($controller))->getMethod('submitPreparedBulkTiktokVariantBatch');
        foreach ([['code' => 123, '_http_status' => 200, 'data' => ['skus' => [['id' => '902']]]], ['code' => 123, '_http_status' => 302], ['code' => 123.0, '_http_status' => 400], ['code' => false, '_http_status' => 200], ['code' => 0, '_http_status' => 500]] as $response) {
            DB::table('stock_hub_tiktok_variant_legacy_attempts')->delete();
            $controller->reply = $response;
            $args = ['900', $this->targetData, [['seller_sku' => 'INT-10-BLUE', 'variant' => ['variant_name' => 'BLUE'], 'uploaded_image_uri' => 'tos-alisg-i-aphluv4xwc-sg/blue']], 25000, (object) ['shop_id' => '33', 'cipher' => 'cipher'], 'fake-token'];
            $result = $method->invokeArgs($controller, $args);
            $this->assertSame('attempted', DB::table('stock_hub_tiktok_variant_legacy_attempts')->value('status'));
            $this->assertFalse($result['mutation']['ok']);
            $calls = $controller->calls;
            try { $method->invokeArgs($controller, $args); $this->fail('Uncertain response must not replay.'); }
            catch (\DomainException) { $this->assertSame($calls, $controller->calls); }
        }
    }

    public function test_fresh_target_trimmed_sku_owned_elsewhere_still_blocks(): void
    {
        DB::table('shopee_product')->insert(['item_id' => '20', 'shop_id' => '11']);
        DB::table('shopee_product_model')->insert(['item_id' => '20', 'model_id' => '201', 'model_sku' => ' CUSTOM-OTHER ']);
        $other = $this->targetData['skus'][0]; $other['id'] = '999'; $other['seller_sku'] = 'CUSTOM-OTHER';
        $other['sales_attributes'][0]['value_name'] = 'OTHER'; $this->targetData['skus'][] = $other;
        $run = $this->finish($this->start());
        $this->assertSame('blocked', $run['status']); $this->assertSame(0, $this->uploads); $this->assertSame(0, $this->edits);
    }

    public function test_variants_arriving_during_preparation_are_reported_as_skipped_without_edit(): void
    {
        $run = $this->start();
        while ($run['status'] !== 'submitting' && $run['can_continue']) { $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data'); }
        $this->appendTarget(1); $this->appendTarget(2);
        $run = $this->postJson($this->base.'/runs/'.$run['run_id'].'/step')->assertOk()->json('data');
        $this->assertSame('exists', $run['status']); $this->assertSame(0, $this->edits);
        $this->assertSame(['skipped', 'skipped'], array_column($run['result']['variants'], 'status'));
        $this->assertSame(['INT-10-BLUE', 'INT-10-GREEN'], array_column($run['result']['variants'], 'seller_sku'));
    }

}
