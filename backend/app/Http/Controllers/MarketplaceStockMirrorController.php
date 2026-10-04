<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceStockMirrorService;
use App\Services\MarketplaceTokenRefreshService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MarketplaceStockMirrorController extends Controller
{
    public function __construct(private MarketplaceStockMirrorService $service, private MarketplaceTokenRefreshService $tokens) {}

    public function start(Request $request)
    {
        $scope = $request->input('scope');
        $targets = $request->input('target_accounts');
        $key = $request->input('request_key');
        abort_unless(is_array($scope) && is_array($targets) && is_string($key), 422, 'Pilihan stok tidak valid.');
        $this->service->validate($scope, $targets, $key);
        $this->refresh();
        return $this->respond(fn () => $this->service->start($scope, $targets, $key));
    }

    public function active()
    {
        return $this->respond(fn () => ['run' => $this->service->active()]);
    }

    public function show(string $runId)
    {
        return $this->respond(fn () => $this->service->show($runId));
    }

    public function step(string $runId)
    {
        return $this->respond(function () use ($runId): array {
            $run = $this->service->show($runId);
            if ($run['can_continue']) {
                $this->refresh();
            }
            return $this->service->step($runId);
        });
    }

    public function cancel(string $runId)
    {
        return $this->respond(fn () => $this->service->cancel($runId));
    }

    private function refresh(): void
    {
        try {
            $this->tokens->refreshDueTokens();
        } catch (\Throwable) {
            abort(422, 'Penyegaran token gagal. Periksa koneksi akun marketplace.');
        }
    }

    private function respond(callable $action)
    {
        try {
            return response()->json($action());
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            abort(503, 'Proses stok tidak tersedia. Muat ulang untuk memeriksa hasil tersimpan.');
        }
    }
}
