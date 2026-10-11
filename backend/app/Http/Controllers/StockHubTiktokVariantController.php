<?php

namespace App\Http\Controllers;

use App\Services\StockHubTiktokVariantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class StockHubTiktokVariantController extends Controller
{
    public function __construct(private StockHubTiktokVariantService $service) {}
    public function start(Request $request)
    {
        $v = Validator::make($request->all(), ['source_product_id' => ['required', 'string', 'regex:/^[0-9]+$/D', 'max:64'], 'request_key' => ['required', 'uuid'],
            'context' => ['sometimes', 'array:manual_price'], 'context.manual_price' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            'source_account' => ['prohibited'], 'target_account' => ['prohibited'], 'account_key' => ['prohibited'], 'target_product_id' => ['prohibited']]);
        abort_if($v->fails(), 422, 'Input varian tidak valid. Periksa produk sumber dan harga rupiah.');
        $data = $v->validated();
        return $this->respond(fn () => $this->service->start($data['source_product_id'], $data['request_key'], $data['context'] ?? []));
    }
    public function source(string $productId) { return $this->respond(fn () => $this->service->source($productId)); }
    public function show(string $runId) { return $this->respond(fn () => $this->service->show($runId)); }
    public function step(string $runId) { return $this->respond(fn () => $this->service->step($runId)); }
    private function respond(callable $action)
    {
        try { return response()->json(['data' => $action()]); }
        catch (HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable) { abort(503, 'Proses varian tidak tersedia. Muat status tersimpan untuk memeriksa hasil.'); }
    }
}
