<?php

// Opt-in from repository root: php backend/tests/Integration/stock_hub_agni_sku_workflow_postgres.php
// Every table/claim belongs to a new isolated schema, removed by transaction rollback.
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\{MarketplaceStockMirrorLease,StockHubAgniSkuGateway,StockHubTiktokVariantBatchSelection,StockHubTiktokVariantBatchService,StockHubTiktokVariantRepairService,StockHubTiktokVariantService,StockHubTiktokVariantInterlock};
use Illuminate\Support\Facades\{DB,Http,Schema};
use Illuminate\Support\Str;

function checkAgniWorkflow(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }

$original=DB::getDefaultConnection();
checkAgniWorkflow(DB::connection()->getDriverName()==='pgsql','This opt-in smoke requires PostgreSQL.');
$schema='agni_workflow_smoke_'.str_replace('-','',(string)Str::uuid());
config(['database.connections.agni_workflow_smoke'=>[...DB::connection()->getConfig(),'search_path'=>$schema]]);
DB::setDefaultConnection('agni_workflow_smoke');Schema::clearResolvedInstance('db.schema');
Http::preventStrayRequests();
$failed=false;$writes=0;$cached=0;$steps=0;$pending=true;$children=[];$models=[];
DB::beginTransaction();
try {
    DB::statement('CREATE SCHEMA "'.$schema.'"');
    DB::statement('SET LOCAL search_path TO "'.$schema.'"');
    foreach(['2026_10_10_000001_create_stock_hub_tiktok_variant_runs.php','2026_10_10_000002_create_stock_hub_tiktok_variant_batches.php','2026_10_12_000001_create_stock_hub_tiktok_variant_repair_runs.php','2026_10_13_000001_create_stock_hub_agni_sku_workflow.php'] as $file) {
        (require dirname(__DIR__,2).'/database/migrations/'.$file)->up();
    }
    foreach(['addition'=>'stock_hub_tiktok_variant_runs','repair'=>'stock_hub_tiktok_variant_repair_runs'] as $kind=>$table){
        $id=(string)Str::uuid();$source='retirement-'.$kind;$target='retirement-target-'.$kind;$own=$kind==='repair'?'stock_hub_tiktok_variant_repair_guards':'stock_hub_tiktok_variant_guards';
        DB::table($table)->insert(['id'=>$id,'request_key'=>(string)Str::uuid(),'request_hash'=>hash('sha256',$source),'source_product_id'=>$source,'target_product_id'=>$target,'status'=>'submitting','state'=>'{}','created_at'=>now(),'updated_at'=>now()]);
        DB::table($own)->insert(['key'=>'source:'.$source,'run_id'=>$id,'owner'=>(string)Str::uuid(),'expires_at'=>now()->addMinute()]);
        checkAgniWorkflow(StockHubTiktokVariantInterlock::retireUnattempted($kind,$id,$source)==='busy','Retirement must preserve live worker.');
        DB::table($own)->where('key','source:'.$source)->update(['expires_at'=>now()->subSecond()]);
        checkAgniWorkflow(StockHubTiktokVariantInterlock::retireUnattempted($kind,$id,$source)==='retired','Unattempted child retirement must lock and save JSON.');
        $saved=DB::table($table)->where('id',$id)->first();checkAgniWorkflow($saved->status==='blocked'&&$saved->attempted_at===null&&$saved->accepted_at===null&&json_decode($saved->state,true)['batch_retired_before_attempt']===true,'Retirement keeps historical unsent identity.');
    }
    $selection=Mockery::mock(StockHubTiktokVariantBatchSelection::class);$selection->shouldReceive('assertReady')->andReturnNull();
    $selection->shouldReceive('forProducts')->andReturn([
        ['source_product_id'=>'1','name'=>'Fixture queued','coverage'=>['state'=>'missing_variants','missing_count'=>1]],
        ['source_product_id'=>'2','name'=>'Fixture complete','coverage'=>['state'=>'complete','missing_count'=>0]],
    ]);$app->instance(StockHubTiktokVariantBatchSelection::class,$selection);
    $gateway=Mockery::mock(StockHubAgniSkuGateway::class);$gateway->shouldReceive('targetId')->andReturnUsing(fn($source)=>'900'.$source);
    $gateway->shouldReceive('assertRelation')->andReturn('33');
    $gateway->shouldReceive('read')->andReturnUsing(function($source)use(&$models) {
        $models[$source]??='OLD';return ['id'=>$source,'shop_id'=>'11','variants'=>[['id'=>'101','name'=>'Rose','seller_sku'=>$models[$source],'expected_sku'=>'INT-'.$source.'-ROSE']]];
    });
    $gateway->shouldReceive('prepare')->andReturnUsing(function($source)use(&$models,&$writes) { return function($model,$sku)use($source,&$models,&$writes) {
        checkAgniWorkflow(DB::table('stock_hub_agni_sku_attempts')->where('model_id',$model)->whereNotNull('attempted_at')->exists(),'Model attempt must precede request.');$writes++;$models[$source]=$sku;
    }; });
    $gateway->shouldReceive('cache')->andReturnUsing(function()use(&$cached){$cached++;});$app->instance(StockHubAgniSkuGateway::class,$gateway);
    $lease=Mockery::mock(MarketplaceStockMirrorLease::class);foreach(['acquire','renew','release'] as $method)$lease->shouldReceive($method)->andReturnNull();$app->instance(MarketplaceStockMirrorLease::class,$lease);
    foreach(['repair'=>StockHubTiktokVariantRepairService::class,'addition'=>StockHubTiktokVariantService::class] as $kind=>$class) {
        $table=$kind==='repair'?'stock_hub_tiktok_variant_repair_runs':'stock_hub_tiktok_variant_runs';$child=Mockery::mock($class);
        $child->shouldReceive('source')->andReturnNull();$child->shouldReceive('show')->andReturnUsing(function($id)use(&$children){return $children[$id];});
        $child->shouldReceive('start')->andReturnUsing(function($source,$key)use(&$children,$table) {
            $id=(string)Str::uuid();$r=['run_id'=>$id,'source_product_id'=>$source,'status'=>'preparing','message'=>'Fixture','can_continue'=>true,'can_retry'=>false,'result'=>null];
            DB::table($table)->insert(['id'=>$id,'request_key'=>$key,'request_hash'=>hash('sha256',$source),'source_product_id'=>$source,'target_product_id'=>'900'.$source,'status'=>'preparing','state'=>json_encode($r),'created_at'=>now(),'updated_at'=>now()]);return $children[$id]=$r;
        });
        $child->shouldReceive('step')->andReturnUsing(function($id)use(&$children,&$pending,&$steps,$kind,$table) {
            $steps++;$r=$children[$id];$r['status']=$kind==='repair'&&$r['source_product_id']==='1'&&$pending?'under_review':'success';$r['can_continue']=false;
            $r['result']=$kind==='repair'?['changed'=>[['name'=>'Rose']],'deleted'=>[['name'=>'Extra']]]:['variants'=>[['status'=>'updated']]];
            DB::table($table)->where('id',$id)->update(['status'=>$r['status'],'state'=>json_encode($r),'attempted_at'=>now(),'accepted_at'=>now()]);return $children[$id]=$r;
        });$app->instance($class,$child);
    }
    $service=$app->make(StockHubTiktokVariantBatchService::class);
    $run=$service->start((string)Str::uuid());checkAgniWorkflow($run['workflow']==='legacy'&&$run['summary']['products_processed']===1,'Legacy true/false flags must insert.');
    $id=$run['run_id'];checkAgniWorkflow($service->pause($id)['status']==='paused','Pause boolean.');checkAgniWorkflow($service->resume($id)['status']==='running','Resume boolean.');
    for($i=0;$i<20&&$run['can_continue'];$i++)$run=$service->step($id);
    checkAgniWorkflow($run['status']==='completed','Legacy completion.');
    $run=$service->resume($id,'agni_sku_repair_then_tiktok');
    for($i=0;$i<60&&$run['can_continue'];$i++)$run=$service->step($id);
    checkAgniWorkflow($run['status']==='completed'&&$run['can_resume_attention'],'Completed attention releases claim.');
    checkAgniWorkflow($writes===2&&$cached===2,'Both source products normalize and cache once.');
    checkAgniWorkflow($run['summary']['tiktok_variants_removed']===1,'Pending removal is not counted.');
    $before=$steps;$service->show($id);checkAgniWorkflow($steps===$before,'GET snapshot never steps a child.');
    $pending=false;$run=$service->resume($id,'agni_sku_repair_then_tiktok');
    for($i=0;$i<60&&$run['can_continue'];$i++)$run=$service->step($id);
    checkAgniWorkflow($run['status']==='completed'&&!$run['can_resume_attention']&&$run['summary']['tiktok_variants_removed']===2,'Explicit saved repair continuation.');
    checkAgniWorkflow($writes===2,'Continuation must not replay source writes.');Http::assertNothingSent();Mockery::close();
} catch(Throwable $e) {
    $failed=true;echo 'FAIL '.get_class($e).' code='.$e->getCode().PHP_EOL;
    // Print fixed check diagnostics only, never a database DSN or raw client error.
    if($e instanceof RuntimeException&&!($e instanceof Illuminate\Database\QueryException))echo $e->getMessage().PHP_EOL;
} finally { DB::rollBack(); }
checkAgniWorkflow(DB::selectOne('SELECT to_regnamespace(?) AS present',[$schema])->present===null,'Isolated schema must roll back completely.');
DB::disconnect('agni_workflow_smoke');DB::setDefaultConnection($original);Schema::clearResolvedInstance('db.schema');
if($failed)exit(1);
echo 'PASS PostgreSQL isolated-schema migration, guarded retirement, legacy upgrade, source attempts, booleans, pause/resume, attention continuation; total rollback; no HTTP.'.PHP_EOL;
