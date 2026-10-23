<?php

namespace App\Services;

/** Only an explicit rejection without any result evidence permits another request. */
class StockHubAgniSkuRejected extends \RuntimeException {}
