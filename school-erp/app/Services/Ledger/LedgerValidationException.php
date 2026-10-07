<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use RuntimeException;

final class LedgerValidationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Ledger posting is not valid: {$reason}");
    }
}
