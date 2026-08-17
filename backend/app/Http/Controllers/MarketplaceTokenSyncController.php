<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceTokenSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceTokenSyncController extends Controller
{
    public function __construct(private readonly MarketplaceTokenSyncService $tokenSyncService)
    {
    }

    public function export(Request $request): JsonResponse
    {
        if (! (bool) config('stb.token_sync_enabled', false)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ekspor token STB belum diaktifkan.',
            ], 403);
        }

        if (! (bool) config('stb.sync_worker', false)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ekspor token hanya tersedia di STB sync worker.',
            ], 409);
        }

        $expected = trim((string) config('stb.token_sync_token', ''));
        $provided = trim((string) $request->bearerToken());

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'status' => 'unauthorized',
                'message' => 'Token sinkronisasi STB tidak valid.',
            ], 401);
        }

        return response()->json($this->tokenSyncService->exportForPc());
    }

    public function pull(): JsonResponse
    {
        return response()->json(['data' => $this->tokenSyncService->pullFromStb()]);
    }

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->tokenSyncService->status()]);
    }
}
