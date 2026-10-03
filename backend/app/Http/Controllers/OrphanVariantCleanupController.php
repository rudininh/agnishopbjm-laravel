<?php

namespace App\Http\Controllers;

use App\Services\OrphanVariantClassifier;
use App\Services\OrphanVariantCleanupService;
use App\Services\MarketplaceTokenRefreshService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class OrphanVariantCleanupController extends Controller
{
    public function __construct(private OrphanVariantCleanupService $service, private MarketplaceTokenRefreshService $tokens) {}

    public function preview(Request $request)
    {
        $account = $this->account($request);
        try { $this->tokens->refreshDueTokens(); }
        catch (\Throwable) { abort(422, 'Penyegaran token gagal. Periksa koneksi akun marketplace.'); }
        return response()->json($this->service->start($account));
    }

    public function show(Request $request, string $runId)
    {
        return response()->json($this->service->show($runId, $this->account($request)));
    }

    public function scan(Request $request, string $runId)
    {
        return response()->json($this->service->scan($runId, $this->account($request)));
    }

    public function submit(Request $request, string $runId)
    {
        $account = $this->account($request);
        $data = $this->validated($request, ['revision' => ['required', 'string', 'max:64'],
            'item_ids' => ['required', 'array', 'min:1'], 'item_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            'confirm_delete' => ['required', 'accepted']]);
        return response()->json($this->service->submit($runId, $account, $data['revision'], $data['item_ids']));
    }

    public function step(Request $request, string $runId)
    {
        return response()->json($this->service->step($runId, $this->account($request)));
    }

    private function account(Request $request): string
    {
        return $this->validated($request, ['account_key' => ['required', 'string', Rule::in(OrphanVariantClassifier::TARGETS)]])['account_key'];
    }

    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        return $validator->validated();
    }
}
