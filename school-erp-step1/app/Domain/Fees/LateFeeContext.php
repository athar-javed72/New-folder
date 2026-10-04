<?php

declare(strict_types=1);

namespace App\Domain\Fees;

/** Base amounts (minor units / paisa) a late-fee rule may refer to. */
final class LateFeeContext
{
    public function __construct(
        public readonly int $tuitionMinor = 0,
        public readonly int $balanceMinor = 0,
        public readonly int $voucherTotalMinor = 0,
    ) {
    }
}
