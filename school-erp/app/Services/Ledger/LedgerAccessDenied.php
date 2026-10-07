<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use RuntimeException;

/** Generic ledger denial. The message never carries ids, names or amounts. */
final class LedgerAccessDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Not allowed.');
    }
}
