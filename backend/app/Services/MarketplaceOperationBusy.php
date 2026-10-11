<?php

namespace App\Services;

/** A verified competing operation owns the shared marketplace mutex. */
class MarketplaceOperationBusy extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Marketplace sedang dipakai proses lain.');
    }
}
