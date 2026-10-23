<?php

namespace App\Http\Controllers;

use App\Services\StockHubTiktokVariantBatchService;
use App\Services\StockHubTiktokVariantBatchPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class StockHubTiktokVariantBatchController extends Controller
{
    public function __construct(private StockHubTiktokVariantBatchService $service) {}

    public function start(Request $request)
    {
        $v = Validator::make($request->all(), ['request_key' => ['required', 'uuid'],'workflow'=>['sometimes','string','in:'.StockHubTiktokVariantBatchPipeline::WORKFLOW]]);
        abort_if($v->fails() || array_diff(array_keys($request->all()), ['request_key','workflow']), 422, 'Permintaan katalog tidak valid.');
        return $this->respond(fn () => $this->service->start($v->validated()['request_key'],$v->validated()['workflow']??'legacy'));
    }
    public function active() { return $this->respond(fn () => $this->service->active()); }
    public function show(string $runId) { return $this->respond(fn () => $this->service->show($runId)); }
    public function step(string $runId) { return $this->respond(fn () => $this->service->step($runId)); }
    public function pause(string $runId) { return $this->respond(fn () => $this->service->pause($runId)); }
    public function resume(Request $request,string $runId) {
        $v=Validator::make($request->all(),['workflow'=>['sometimes','string','in:'.StockHubTiktokVariantBatchPipeline::WORKFLOW]]);
        abort_if($v->fails()||array_diff(array_keys($request->all()),['workflow']),422,'Permintaan katalog tidak valid.');
        return $this->respond(fn () => $this->service->resume($runId,$v->validated()['workflow']??null));
    }
    private function respond(callable $action)
    {
        try { return response()->json(['data' => $action()]); }
        catch (HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable) { abort(503, 'Batch katalog tidak tersedia. Muat status tersimpan untuk memeriksa hasil.'); }
    }
}
