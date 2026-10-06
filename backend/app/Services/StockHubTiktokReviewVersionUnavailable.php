<?php

namespace App\Services;

/** The requested product has no under-review version; only its normal snapshot can be read. */
final class StockHubTiktokReviewVersionUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Produk TikTok tidak memiliki versi dalam peninjauan.');
    }
}
