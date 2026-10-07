<?php

declare(strict_types=1);

use App\Models\Account;
use App\Services\Ledger\DefaultChartOfAccounts;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

it('seeds exactly 15 accounts for an organization', function () {
    $org = F::org();

    DefaultChartOfAccounts::seedFor($org);

    expect(DB::table('accounts')->where('organization_id', $org)->count())->toBe(15);
});

it('gives every system key the type, code, name and requires_family flag of D-48', function () {
    $org = F::org();
    DefaultChartOfAccounts::seedFor($org);

    $expected = [
        'cash' => ['1000', 'Cash in Hand', 'asset', false],
        'bank' => ['1010', 'Bank', 'asset', false],
        'gateway_clearing' => ['1020', 'Gateway Clearing', 'asset', false],
        'fee_receivable' => ['1100', 'Fee Receivable', 'asset', true],
        'family_credit' => ['2000', 'Family Advance Credit', 'liability', true],
        'refunds_payable' => ['2100', 'Refunds Payable', 'liability', true],
        'security_deposits' => ['2200', 'Security Deposits', 'liability', true],
        'pass_through' => ['2300', 'Pass-through Payable', 'liability', false],
        'opening_equity' => ['3000', 'Opening Balance Equity', 'equity', false],
        'tuition_income' => ['4000', 'Tuition Fee Income', 'income', false],
        'other_fee_income' => ['4100', 'Other Fee Income', 'income', false],
        'late_fee_income' => ['4900', 'Late Fee Income', 'income', false],
        'fee_discounts' => ['5000', 'Fee Discounts', 'expense', false],
        'fee_waivers' => ['5100', 'Fee Waivers', 'expense', false],
        'scholarships' => ['5200', 'Scholarships', 'expense', false],
    ];

    $rows = Account::query()->where('organization_id', $org)->get()->keyBy('system_key');

    expect($rows->keys()->sort()->values()->all())->toBe(collect(array_keys($expected))->sort()->values()->all());
    foreach ($expected as $key => [$code, $name, $type, $requiresFamily]) {
        $account = $rows[$key];
        expect($account->code)->toBe($code)
            ->and($account->name)->toBe($name)
            ->and($account->type)->toBe($type)
            ->and($account->requires_family)->toBe($requiresFamily)
            ->and($account->is_active)->toBeTrue();
    }
});

it('changes nothing on a second run: same ids, same rows, same count', function () {
    $org = F::org();
    DefaultChartOfAccounts::seedFor($org);
    $before = DB::table('accounts')->where('organization_id', $org)->orderBy('system_key')->get()->map(fn ($r) => (array) $r)->all();

    DefaultChartOfAccounts::seedFor($org);
    $after = DB::table('accounts')->where('organization_id', $org)->orderBy('system_key')->get()->map(fn ($r) => (array) $r)->all();

    expect($after)->toHaveCount(15)
        ->and($after)->toBe($before);
});

it('never changes the type of an existing account and restores its D-48 name', function () {
    $org = F::org();
    $existing = F::account($org, ['code' => '1000', 'name' => 'Old Test Name', 'type' => 'liability', 'system_key' => 'cash']);

    DefaultChartOfAccounts::seedFor($org);

    $row = DB::table('accounts')->where('id', $existing)->first();
    expect($row->type)->toBe('liability')
        ->and($row->name)->toBe('Cash in Hand')
        ->and(DB::table('accounts')->where('organization_id', $org)->count())->toBe(15)
        ->and(DB::table('accounts')->where('organization_id', $org)->where('system_key', 'cash')->count())->toBe(1);
});

it('gives two organizations separate account sets', function () {
    $orgA = F::org();
    $orgB = F::org();

    DefaultChartOfAccounts::seedFor($orgA);
    DefaultChartOfAccounts::seedFor($orgB);

    $idsA = DB::table('accounts')->where('organization_id', $orgA)->pluck('id')->all();
    $idsB = DB::table('accounts')->where('organization_id', $orgB)->pluck('id')->all();

    expect($idsA)->toHaveCount(15)
        ->and($idsB)->toHaveCount(15)
        ->and(array_intersect($idsA, $idsB))->toBe([]);
});

it('keeps system_key present and unique per organization', function () {
    $org = F::org();
    DefaultChartOfAccounts::seedFor($org);

    $keys = DB::table('accounts')->where('organization_id', $org)->pluck('system_key')->all();
    expect($keys)->not->toContain(null)
        ->and(array_unique($keys))->toHaveCount(15);

    expect(fn () => DB::transaction(fn () => F::account($org, ['code' => '9999', 'name' => 'Test Duplicate', 'system_key' => 'cash'])))
        ->toThrow(QueryException::class);
});

it('finds the right account with scopeSystemKey', function () {
    $orgA = F::org();
    $orgB = F::org();
    DefaultChartOfAccounts::seedFor($orgA);
    DefaultChartOfAccounts::seedFor($orgB);

    $account = Account::query()->where('organization_id', $orgA)->systemKey('fee_receivable')->firstOrFail();

    expect($account->organization_id)->toBe($orgA)
        ->and($account->code)->toBe('1100')
        ->and($account->requires_family)->toBeTrue()
        ->and(Account::query()->where('organization_id', $orgA)->systemKey('fee_receivable')->count())->toBe(1)
        ->and(Account::query()->where('organization_id', $orgA)->systemKey('no_such_key')->exists())->toBeFalse();
});
