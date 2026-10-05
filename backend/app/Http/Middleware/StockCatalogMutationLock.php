<?php

namespace App\Http\Middleware;

use App\Services\MarketplaceCatalogMutationRevision;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StockCatalogMutationLock
{
    public function handle(Request $request, Closure $next)
    {
        $lock = Cache::lock('stock-catalog-mutation', 900);
        abort_unless($lock->get(), 423, 'Perubahan katalog lain masih berjalan. Tunggu lalu muat ulang hasil.');
        try {
            try {
                // Persist before remote attempts, including failed or ambiguous mutations.
                app(MarketplaceCatalogMutationRevision::class)->beforeLegacyMutation();
            } catch (\Throwable) {
                abort(503, 'Koordinasi perubahan katalog tidak tersedia. Periksa penyimpanan dan migrasi.');
            }

            return $next($request);
        } finally {
            $lock->release();
        }
    }
}
