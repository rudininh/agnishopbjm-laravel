<?php

namespace App\Services;

class StockHubAgniSkuPending extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Normalisasi SKU Agni masih berjalan atau belum pasti. Pulihkan batch dan Periksa dan Lanjutkan.');
    }
}
