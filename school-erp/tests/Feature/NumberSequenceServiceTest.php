<?php

declare(strict_types=1);

use App\Services\Ledger\NumberSequenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    config(['ledger.fiscal_year_start_month' => 7]);
});

it('reads the start month from config when none is given', function () {
    expect(App\Services\Ledger\FiscalYear::startYearFor(Carbon::create(2026, 6, 30)))->toBe(2025);

    config(['ledger.fiscal_year_start_month' => 13]);
    expect(fn () => App\Services\Ledger\FiscalYear::startYearFor(Carbon::create(2026, 6, 30)))
        ->toThrow(InvalidArgumentException::class, 'Invalid fiscal year start month.');
});

it('gives 1, 2, 3 for the same campus, key and fiscal year', function () {
    $org = F::org();
    $campus = F::campus($org);
    $svc = new NumberSequenceService;
    $date = Carbon::create(2026, 8, 10);

    expect($svc->next($org, $campus, 'receipt', $date))->toBe(1)
        ->and($svc->next($org, $campus, 'receipt', $date))->toBe(2)
        ->and($svc->next($org, $campus, 'receipt', $date))->toBe(3)
        ->and(DB::table('number_sequences')->where('organization_id', $org)->count())->toBe(1);
});

it('keeps separate counters per campus', function () {
    $org = F::org();
    $campusA = F::campus($org);
    $campusB = F::campus($org);
    $svc = new NumberSequenceService;
    $date = Carbon::create(2026, 8, 10);

    $svc->next($org, $campusA, 'receipt', $date);
    $svc->next($org, $campusA, 'receipt', $date);

    expect($svc->next($org, $campusB, 'receipt', $date))->toBe(1)
        ->and($svc->next($org, $campusA, 'receipt', $date))->toBe(3);
});

it('keeps separate counters per key', function () {
    $org = F::org();
    $campus = F::campus($org);
    $svc = new NumberSequenceService;
    $date = Carbon::create(2026, 8, 10);

    $svc->next($org, $campus, 'receipt', $date);
    $svc->next($org, $campus, 'receipt', $date);

    expect($svc->next($org, $campus, 'voucher', $date))->toBe(1)
        ->and($svc->next($org, $campus, 'receipt', $date))->toBe(3);
});

it('keeps separate counters per fiscal year', function () {
    $org = F::org();
    $campus = F::campus($org);
    $svc = new NumberSequenceService;

    // July start: 2026-06-30 is fiscal year 2025, 2026-07-01 is fiscal year 2026.
    $svc->next($org, $campus, 'receipt', Carbon::create(2026, 6, 30));
    $svc->next($org, $campus, 'receipt', Carbon::create(2026, 6, 30));

    expect($svc->next($org, $campus, 'receipt', Carbon::create(2026, 7, 1)))->toBe(1)
        ->and(DB::table('number_sequences')->where('organization_id', $org)->orderBy('fiscal_year')->pluck('fiscal_year')->map(fn ($y) => (int) $y)->all())
        ->toBe([2025, 2026]);
});

it('rejects a campus of another organization and writes nothing', function () {
    $orgA = F::org();
    $orgB = F::org();
    $campusB = F::campus($orgB);

    expect(fn () => (new NumberSequenceService)->next($orgA, $campusB, 'receipt', Carbon::create(2026, 8, 10)))
        ->toThrow(InvalidArgumentException::class, 'Invalid campus.');
    expect(DB::table('number_sequences')->count())->toBe(0);
});

it('gives the number back when the surrounding nested transaction rolls back', function () {
    $org = F::org();
    $campus = F::campus($org);
    $svc = new NumberSequenceService;
    $date = Carbon::create(2026, 8, 10);

    expect($svc->next($org, $campus, 'receipt', $date))->toBe(1);

    try {
        DB::transaction(function () use ($svc, $org, $campus, $date) {
            expect($svc->next($org, $campus, 'receipt', $date))->toBe(2);
            throw new RuntimeException('Simulated failure after taking the number.');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($svc->next($org, $campus, 'receipt', $date))->toBe(2);
});

it('rejects an invalid key and writes nothing', function (string $key) {
    $org = F::org();
    $campus = F::campus($org);

    expect(fn () => (new NumberSequenceService)->next($org, $campus, $key, Carbon::create(2026, 8, 10)))
        ->toThrow(InvalidArgumentException::class, 'Invalid sequence key.');
    expect(DB::table('number_sequences')->count())->toBe(0);
})->with([
    'empty' => [''],
    'uppercase' => ['A'],
    'space' => ['has space'],
    '41 chars' => [str_repeat('a', 41)],
    'digit' => ['x1'],
]);
