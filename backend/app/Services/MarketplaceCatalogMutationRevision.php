<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Durable scan invalidation, read and advanced only while owning the catalog mutex. */
class MarketplaceCatalogMutationRevision
{
    private const TABLE = 'marketplace_catalog_mutation_revision';

    public function current(): string
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new \DomainException('Riwayat perubahan katalog belum tersedia. Jalankan migrasi sebelum membuat produk.');
        }

        $revision = DB::table(self::TABLE)->where('id', 1)->value('revision');
        if ($revision === null || ! ctype_digit((string) $revision)) {
            throw new \DomainException('Riwayat perubahan katalog tidak valid. Periksa penyimpanan sebelum membuat produk.');
        }

        return (string) $revision;
    }

    public function beforeLegacyMutation(): void
    {
        if (! Schema::hasTable(self::TABLE) && ! Schema::hasTable('stock_hub_tiktok_product_runs')) {
            // Preserve legacy endpoints on installations that have not added creation yet.
            return;
        }

        $revision = $this->current();
        $updated = DB::table(self::TABLE)->where('id', 1)->where('revision', $revision)->increment('revision');
        if ($updated !== 1) {
            throw new \DomainException('Riwayat perubahan katalog tidak dapat disimpan.');
        }
    }
}
