<?php

declare(strict_types=1);

use App\Services\Ledger\FiscalYear;
use Carbon\Carbon;

it('gives 2025 for 2026-06-30 and 2026 for 2026-07-01 with a July start', function () {
    expect(FiscalYear::startYearFor(Carbon::create(2026, 6, 30), 7))->toBe(2025)
        ->and(FiscalYear::startYearFor(Carbon::create(2026, 7, 1), 7))->toBe(2026);
});

it('gives the calendar year with a January start', function () {
    expect(FiscalYear::startYearFor(Carbon::create(2026, 1, 1), 1))->toBe(2026)
        ->and(FiscalYear::startYearFor(Carbon::create(2026, 6, 30), 1))->toBe(2026)
        ->and(FiscalYear::startYearFor(Carbon::create(2026, 12, 31), 1))->toBe(2026);
});

it('rejects a start month of 0 or 13', function (int $month) {
    expect(fn () => FiscalYear::startYearFor(Carbon::create(2026, 7, 1), $month))
        ->toThrow(InvalidArgumentException::class, 'Invalid fiscal year start month.');
})->with([0, 13]);
