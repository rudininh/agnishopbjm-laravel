<?php

namespace App\Http\Controllers;

use App\Services\StockHubTiktokProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class StockHubTiktokProductController extends Controller
{
    public function __construct(private StockHubTiktokProductService $service) {}

    public function start(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_product_id' => ['required','string','regex:/^[0-9]+$/D','max:64'],
            'request_key' => ['required','uuid'], 'context' => ['sometimes','array:category_id,warehouse_id,package_weight,package_dimensions'],
            'context.category_id' => ['sometimes','nullable','string','regex:/^[0-9]+$/D','max:64'],
            'context.warehouse_id' => ['sometimes','nullable','string','max:64'],
            'context.package_weight' => ['sometimes','array:value,unit'], 'context.package_weight.value' => ['required_with:context.package_weight','numeric','gt:0','max:1000'], 'context.package_weight.unit' => ['required_with:context.package_weight','in:KILOGRAM'],
            'context.package_dimensions' => ['sometimes','array:length,width,height,unit'], 'context.package_dimensions.unit' => ['required_with:context.package_dimensions','in:CENTIMETER'],
            'context.package_dimensions.length' => ['required_with:context.package_dimensions','numeric','gt:0','max:10000'], 'context.package_dimensions.width' => ['required_with:context.package_dimensions','numeric','gt:0','max:10000'], 'context.package_dimensions.height' => ['required_with:context.package_dimensions','numeric','gt:0','max:10000'],
            'source_account' => ['prohibited'], 'target_account' => ['prohibited'], 'account_key' => ['prohibited'],
        ]);
        abort_if($validator->fails(), 422, 'Input produk tidak valid. Periksa ID, kategori, berat, dan ukuran paket.');
        $data = $validator->validated();
        return $this->respond(fn () => $this->service->start($data['source_product_id'], $data['request_key'], $data['context'] ?? []));
    }

    public function show(string $runId) { return $this->respond(fn () => $this->service->show($runId)); }
    public function source(string $productId) { return $this->respond(fn () => $this->service->source($productId)); }
    public function step(string $runId) { return $this->respond(fn () => $this->service->step($runId)); }
    public function categories() { return $this->respond(fn () => $this->service->categories()); }

    private function respond(callable $action)
    {
        try { return response()->json(['data' => $action()]); }
        catch (HttpExceptionInterface $e) { throw $e; }
        catch (\Throwable) { abort(503, 'Proses produk tidak tersedia. Muat ulang untuk memeriksa hasil tersimpan.'); }
    }
}
