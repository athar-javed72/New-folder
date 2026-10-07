<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Fiscal year = the calendar year in which the fiscal year STARTS (D-49).
 * Uses the date exactly as given; no timezone conversion happens here.
 */
final class FiscalYear
{
    public static function startYearFor(CarbonInterface $date, ?int $startMonth = null): int
    {
        $month = $startMonth ?? config('ledger.fiscal_year_start_month');

        if (! is_int($month) || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('Invalid fiscal year start month.');
        }

        return $date->month >= $month ? $date->year : $date->year - 1;
    }
}
