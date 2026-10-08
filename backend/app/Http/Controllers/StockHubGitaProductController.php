<?php

namespace App\Http\Controllers;

use App\Services\StockHubGitaProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class StockHubGitaProductController extends Controller
{
    public function __construct(private StockHubGitaProductService $service) {}
    public function start(Request $request)
    {
        abort_if(array_diff(array_keys($request->all()), ['source_product_id','request_key','context']), 422, 'Input produk Gita tidak valid.');
        $v = Validator::make($request->all(), [
            'source_product_id' => ['required','string','regex:/^[0-9]+$/D','max:64'], 'request_key' => ['required','uuid'],
            'context' => ['sometimes','array:category_id,weight,dimension,logistic_ids,location_id,size_chart_image_url,use_agni_shipping'],
            'context.use_agni_shipping' => ['sometimes', function ($attribute, $value, $fail) { if (!is_bool($value)) { $fail('Pilihan data Agni tidak valid.'); } }],
            'context.category_id' => ['sometimes','string','regex:/^[0-9]+$/D','max:64'],
            'context.weight' => ['sometimes','numeric','gt:0','max:1000'],
            'context.dimension' => ['sometimes','array:package_length,package_width,package_height'],
            'context.dimension.package_length' => ['required_with:context.dimension','integer','min:0','max:10000'],
            'context.dimension.package_width' => ['required_with:context.dimension','integer','min:0','max:10000'],
            'context.dimension.package_height' => ['required_with:context.dimension','integer','min:0','max:10000'],
            'context.logistic_ids' => ['sometimes','array','min:1'], 'context.logistic_ids.*' => ['required','string','regex:/^[0-9]+$/D','distinct','max:64'],
            'context.location_id' => ['sometimes','string','max:128'], 'context.size_chart_image_url' => ['sometimes','string','url:https','max:2048'],
        ]);
        abort_if($v->fails(), 422, 'Input produk Gita tidak valid. Periksa kategori, berat, dimensi, pengiriman, dan gambar.');
        $data = $v->validated();
        return $this->respond(fn () => $this->service->start($data['source_product_id'], $data['request_key'], $data['context'] ?? []));
    }
    public function source(string $productId) { return $this->respond(fn () => $this->service->source($productId)); }
    public function show(string $runId) { return $this->respond(fn () => $this->service->show($runId)); }
    public function step(string $runId) { return $this->respond(fn () => $this->service->step($runId)); }
    public function categories() { return $this->respond(fn () => $this->service->categories()); }
    public function shippingOptions() { return $this->respond(fn () => $this->service->shippingOptions()); }
    private function respond(callable $action)
    {
        try { return response()->json(['data' => $action()]); }
        catch (HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable) { abort(503, 'Proses Gita tidak tersedia. Muat ulang untuk memeriksa hasil tersimpan.'); }
    }
}
