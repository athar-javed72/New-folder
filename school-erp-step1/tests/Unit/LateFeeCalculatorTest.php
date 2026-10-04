<?php

declare(strict_types=1);

use App\Domain\Fees\DueDateResolver;
use App\Domain\Fees\LateFeeCalculator;
use App\Domain\Fees\LateFeeContext;
use App\Domain\Fees\UnsupportedLateFeeMethod;
use Tests\Support\PresetFixture;

/**
 * GOLDEN: preset late_fee/default = days 1-7 flat Rs 500, from day 8 + Rs 50/day, cumulative.
 * Voucher due 10-Oct-2026 is a Saturday, so the effective due date is Monday 12-Oct-2026.
 */
function lateFeePolicy(): array
{
    return PresetFixture::policy('late_fee');
}

function lateFeeDueDate(): DateTimeImmutable
{
    return (new DueDateResolver())->nextWorkingDay(new DateTimeImmutable('2026-10-10'), ['saturday', 'sunday']);
}

it('moves a Saturday due date to the next working day', function () {
    expect(lateFeeDueDate()->format('Y-m-d'))->toBe('2026-10-12');
});

it('charges nothing on or before the due date', function () {
    $calc = new LateFeeCalculator();
    expect($calc->calculate(lateFeePolicy(), lateFeeDueDate(), new DateTimeImmutable('2026-10-12')))->toBe(0);
    expect($calc->calculate(lateFeePolicy(), lateFeeDueDate(), new DateTimeImmutable('2026-10-05')))->toBe(0);
});

it('GOLDEN day 1: flat Rs 500', function () {
    $fee = (new LateFeeCalculator())->calculate(lateFeePolicy(), lateFeeDueDate(), new DateTimeImmutable('2026-10-13'));
    expect($fee)->toBe(50000);
});

it('day 7 is still the flat Rs 500', function () {
    $fee = (new LateFeeCalculator())->calculate(lateFeePolicy(), lateFeeDueDate(), new DateTimeImmutable('2026-10-19'));
    expect($fee)->toBe(50000);
});

it('GOLDEN day 8: Rs 500 + Rs 50 = Rs 550', function () {
    $fee = (new LateFeeCalculator())->calculate(lateFeePolicy(), lateFeeDueDate(), new DateTimeImmutable('2026-10-20'));
    expect($fee)->toBe(55000);
});

it('GOLDEN day 15: Rs 500 + 8 x Rs 50 = Rs 900', function () {
    $fee = (new LateFeeCalculator())->calculate(lateFeePolicy(), lateFeeDueDate(), new DateTimeImmutable('2026-10-27'));
    expect($fee)->toBe(90000);
});

it('applies the cap when configured', function () {
    $policy = ['cap_minor' => 60000] + lateFeePolicy();
    $fee = (new LateFeeCalculator())->calculate($policy, lateFeeDueDate(), new DateTimeImmutable('2026-10-27'));
    expect($fee)->toBe(60000);
});

it('respects grace days', function () {
    $policy = ['grace_days' => 3] + lateFeePolicy();
    $calc = new LateFeeCalculator();
    expect($calc->calculate($policy, lateFeeDueDate(), new DateTimeImmutable('2026-10-15')))->toBe(0);      // 3 days late, all grace
    expect($calc->calculate($policy, lateFeeDueDate(), new DateTimeImmutable('2026-10-16')))->toBe(50000);  // day 1 after grace
});

it('supports percent_of_head in basis points', function () {
    $policy = [
        'steps' => [['from_day' => 1, 'to_day' => null, 'method' => 'percent_of_head', 'percent_bp' => 200]],
        'cumulative' => true,
    ] + lateFeePolicy();
    $ctx = new LateFeeContext(tuitionMinor: 2500000);
    $fee = (new LateFeeCalculator())->calculate($policy, lateFeeDueDate(), new DateTimeImmutable('2026-10-13'), $ctx);
    expect($fee)->toBe(50000);   // 2% of Rs 25,000
});

it('refuses methods that are not implemented instead of guessing', function () {
    $policy = ['steps' => [['from_day' => 1, 'to_day' => null, 'method' => 'formula']]] + lateFeePolicy();
    expect(fn () => (new LateFeeCalculator())->calculate($policy, lateFeeDueDate(), new DateTimeImmutable('2026-10-13')))
        ->toThrow(UnsupportedLateFeeMethod::class);
});
