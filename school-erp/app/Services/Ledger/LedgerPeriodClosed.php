<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use RuntimeException;

final class LedgerPeriodClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ledger period is closed.');
    }
}
