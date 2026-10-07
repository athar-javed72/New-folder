<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use RuntimeException;

final class LedgerConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Source already posted with different lines.');
    }
}
