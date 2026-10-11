<?php

namespace App\Services;

/** Fixed readback diagnostic; a stock mismatch never authorizes a repeated edit. */
final class StockHubTiktokVariantStockMismatch extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Stok TikTok berbeda dari saat pengiriman. Periksa stok lalu gunakan Periksa Status; jangan menambahkan ulang.');
    }
}
