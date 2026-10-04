<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** One step owns catalog and scheduler coordination, always released by its caller. */
class MarketplaceStockMirrorLease
{
    private mixed $catalogLock = null;
    private ?string $localToken = null;
    private ?string $remoteToken = null;

    public function __construct(private MarketplaceOperationLeaseService $leases) {}

    public function acquire(): void
    {
        try {
            $this->catalogLock = Cache::lock('stock-catalog-mutation', 900);
            if (! $this->catalogLock->get()) {
                $this->catalogLock = null;
                throw new \RuntimeException();
            }
            $local = $this->leases->acquire('stock_mirror', 900);
            if (($local['acquired'] ?? false) !== true || ! is_string($local['token'] ?? null) || $local['token'] === '') {
                throw new \RuntimeException();
            }
            $this->localToken = $local['token'];
            if ($this->remoteEnabled()) {
                // The deployed endpoint supports this shared marketplace mutex tag.
                $remote = $this->remote('acquire', ['operation' => 'gitashop_mass_upload', 'seconds' => 900]);
                if (($remote['acquired'] ?? false) !== true || ! is_string($remote['token'] ?? null) || $remote['token'] === '') {
                    throw new \RuntimeException();
                }
                $this->remoteToken = $remote['token'];
            }
        } catch (\Throwable) {
            $this->release();
            throw new \RuntimeException('Koordinasi marketplace tidak tersedia.');
        }
    }

    public function renew(): void
    {
        if ($this->localToken === null || ! $this->leases->renew($this->localToken, 900)) {
            throw new \RuntimeException('Koordinasi marketplace kedaluwarsa.');
        }
        if ($this->remoteToken !== null && ($this->remote('renew', ['token' => $this->remoteToken, 'seconds' => 900])['renewed'] ?? false) !== true) {
            throw new \RuntimeException('Koordinasi STB kedaluwarsa.');
        }
    }

    public function release(): void
    {
        try {
            if ($this->remoteToken !== null) {
                $this->remote('release', ['token' => $this->remoteToken]);
            }
        } catch (\Throwable) {
            // Remote lease expires after its bounded TTL if STB is unavailable.
        } finally {
            $this->remoteToken = null;
            try {
                if ($this->localToken !== null) {
                    $this->leases->release($this->localToken);
                }
            } finally {
                $this->localToken = null;
                if ($this->catalogLock !== null) {
                    $this->catalogLock->release();
                    $this->catalogLock = null;
                }
            }
        }
    }

    private function remoteEnabled(): bool
    {
        return trim((string) config('shopee_mass_upload.stb_control_url', '')) !== '';
    }

    private function remote(string $action, array $payload): array
    {
        $base = rtrim((string) config('shopee_mass_upload.stb_control_url'), '/');
        $token = trim((string) config('shopee_mass_upload.stb_control_token', ''));
        if ($base === '' || $token === '') {
            throw new \RuntimeException('Kontrol STB tidak lengkap.');
        }
        $response = Http::timeout(8)->acceptJson()->withToken($token)->post($base.'/marketplace-operation/'.$action, $payload);
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new \RuntimeException('Kontrol STB tidak dapat diverifikasi.');
        }
        return $response->json('data');
    }
}
