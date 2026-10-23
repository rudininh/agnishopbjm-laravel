<?php

namespace App\Http\Controllers;

use App\Services\StockHubAgniSkuService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class StockHubAgniSkuController extends Controller
{
    public function __construct(private StockHubAgniSkuService $service) {}
    public function source(string $productId) { return $this->respond(fn()=>$this->service->source($productId)); }
    public function start(Request $request)
    {
        $v=Validator::make($request->all(),['source_product_id'=>['required','string','regex:/^[0-9]+$/D','max:64'],'request_key'=>['required','uuid']]);
        abort_if($v->fails()||array_diff(array_keys($request->all()),['source_product_id','request_key']),422,'Input normalisasi SKU Agni tidak valid.');
        $data=$v->validated();return $this->respond(fn()=>$this->service->start($data['source_product_id'],$data['request_key']));
    }
    public function step(Request $request,string $runId)
    {
        abort_if($request->all()!==[],422,'Langkah SKU tidak menerima perubahan input.');
        return $this->respond(fn()=>$this->service->step($runId));
    }
    public function recover(Request $request,string $runId)
    {
        abort_if($request->all()!==[],422,'Pemulihan SKU tidak menerima perubahan input.');
        return $this->respond(fn()=>$this->service->recover($runId));
    }
    private function respond(callable $action)
    {
        try { return response()->json(['data'=>$action()]); }
        catch(HttpExceptionInterface $e) { throw $e; }
        catch(\DomainException) { abort(409,'Normalisasi SKU Agni belum aman. Periksa status tersimpan dan hubungan produk.'); }
        catch(\Throwable) { abort(503,'Normalisasi SKU Agni tidak tersedia. Muat status tersimpan.'); }
    }
}
