<?php

declare(strict_types=1);

use App\Domain\Fees\DueDateResolver;
use App\Domain\Fees\LateFeeCalculator;
use App\Domain\Policies\PolicyResolver;
use Tests\Support\PresetFixture;

function policyOverride(string $scope, array $value, array $extra = []): array
{
    return array_merge([
        'scopeable_type' => $scope, 'scopeable_id' => 'X', 'merge_mode' => 'deep_merge', 'value' => $value,
        'effective_from' => '2026-04-01', 'effective_to' => null, 'status' => 'published',
    ], $extra);
}

it('a campus override changes the late fee: day 15 = Rs 1,300 instead of Rs 900', function () {
    $base = PresetFixture::policy('late_fee');
    $campus = policyOverride('campus', ['steps' => [
        ['from_day' => 1, 'to_day' => 7, 'method' => 'flat_once', 'amount_minor' => 50000],
        ['from_day' => 8, 'to_day' => null, 'method' => 'per_day', 'amount_minor' => 10000],
    ]]);
    $on = new DateTimeImmutable('2026-10-27');
    $resolved = (new PolicyResolver())->resolve($base, [$campus], $on);

    $due = (new DueDateResolver())->nextWorkingDay(new DateTimeImmutable('2026-10-10'), ['saturday', 'sunday']);
    $calc = new LateFeeCalculator();
    expect($calc->calculate($base, $due, $on))->toBe(90000);
    expect($calc->calculate($resolved, $due, $on))->toBe(130000);
});

it('ignores not-yet-effective, expired and draft overrides', function () {
    $base = ['x' => 1];
    $list = [
        policyOverride('campus', ['x' => 2], ['effective_from' => '2027-01-01']),
        policyOverride('campus', ['x' => 3], ['effective_to' => '2026-06-01']),
        policyOverride('campus', ['x' => 4], ['status' => 'draft']),
    ];
    expect((new PolicyResolver())->resolve($base, $list, new DateTimeImmutable('2026-10-01')))->toBe(['x' => 1]);
});

it('effective_to is exclusive', function () {
    $list = [policyOverride('campus', ['x' => 2], ['effective_to' => '2026-10-01'])];
    $r = new PolicyResolver();
    expect($r->resolve(['x' => 1], $list, new DateTimeImmutable('2026-09-30')))->toBe(['x' => 2]);
    expect($r->resolve(['x' => 1], $list, new DateTimeImmutable('2026-10-01')))->toBe(['x' => 1]);
});

it('the more specific scope wins regardless of list order', function () {
    $list = [policyOverride('grade', ['x' => 'grade']), policyOverride('organization', ['x' => 'org']), policyOverride('campus', ['x' => 'campus'])];
    expect((new PolicyResolver())->resolve(['x' => 'base'], $list, new DateTimeImmutable('2026-10-01')))->toBe(['x' => 'grade']);
});

it('an employment_contract override beats the contract_type override', function () {
    $list = [policyOverride('employment_contract', ['rate' => 2]), policyOverride('contract_type', ['rate' => 1])];
    expect((new PolicyResolver())->resolve(['rate' => 0], $list, new DateTimeImmutable('2026-10-01')))->toBe(['rate' => 2]);
});

it('explicit null sets null, and replace mode discards lower layers', function () {
    $r = new PolicyResolver();
    $on = new DateTimeImmutable('2026-10-01');
    expect($r->resolve(['cap' => 100, 'y' => 1], [policyOverride('campus', ['cap' => null])], $on))->toBe(['cap' => null, 'y' => 1]);
    expect($r->resolve(['cap' => 100, 'y' => 1], [policyOverride('campus', ['z' => 9], ['merge_mode' => 'replace'])], $on))->toBe(['z' => 9]);
});

it('deep merges objects but replaces lists', function () {
    $base = ['a' => ['b' => 1, 'c' => 2], 'list' => [1, 2, 3]];
    $r = (new PolicyResolver())->resolve($base, [policyOverride('campus', ['a' => ['b' => 9], 'list' => [7]])], new DateTimeImmutable('2026-10-01'));
    expect($r)->toBe(['a' => ['b' => 9, 'c' => 2], 'list' => [7]]);
});
