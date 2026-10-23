<?php

namespace App\Http\Controllers;

use App\Services\{StockHubAgniSkuPending, StockHubTiktokVariantInterlock, StockHubTiktokVariantRepairService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class StockHubTiktokVariantRepairController extends Controller
{
    public function __construct(private StockHubTiktokVariantRepairService $service) {}
    public function start(Request $request)
    {
        $v=Validator::make($request->all(),['source_product_id'=>['required','string','regex:/^[0-9]+$/D','max:64'],'request_key'=>['required','uuid']]);
        abort_if($v->fails()||array_diff(array_keys($request->all()),['source_product_id','request_key']),422,'Input perbaikan tidak valid. Sumber dan tujuan ditentukan sistem.');
        $data=$v->validated();return $this->respond(fn()=>$this->service->start($data['source_product_id'],$data['request_key']));
    }
    public function source(string $productId) { return $this->respond(fn()=>$this->service->source($productId),$productId); }
    public function show(string $runId) { return $this->respond(fn()=>$this->service->show($runId)); }
    public function step(string $runId) { return $this->respond(fn()=>$this->service->step($runId)); }
    private function respond(callable $action, ?string $source = null)
    {
        try {
            $data=$action();
            // Saved repairs take precedence: their readback must remain recoverable.
            if($data===null && $source!==null) {
                try { StockHubTiktokVariantInterlock::assertSourceSkuAvailable($source); }
                catch(StockHubAgniSkuPending) { return response()->json(['data'=>null,'prerequisite'=>['code'=>'agni_sku_pending']]); }
            }
            return response()->json(['data'=>$data]);
        } catch(HttpExceptionInterface $e) { throw $e; }
        catch(\DomainException $e) { abort(409,$e->getMessage()); }
        catch(\Throwable) { abort(503,'Proses perbaikan tidak tersedia. Muat status tersimpan.'); }
    }
}
