<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access\ScopeContext;
use App\Services\Ledger\DefaultChartOfAccounts;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerReports;
use App\Services\Ledger\LedgerValidationException;
use App\Services\Ledger\PostingLine;
use App\Services\Ledger\PostingRequest;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/**
 * Assigns system role to user via active role assignment.
 */
function assignGoldenRole(User $user, string $roleKey, string $scopeType = 'org', ?string $scopeId = null): void
{
    /** @var Role $role */
    $role = Role::query()->where('key', $roleKey)->where('is_system', true)->firstOrFail();

    RoleAssignment::query()->create([
        'organization_id' => $user->organization_id,
        'user_id' => $user->id,
        'role_id' => $role->id,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'status' => 'active',
        'starts_at' => now()->subMinute(),
        'ends_at' => null,
    ]);
}

/**
 * @return array{org: string, campus: string, actor: User, family: string, poster: LedgerPoster, scope: ScopeContext}
 */
function goldenFixture(): array
{
    $org = F::org();
    $campus = F::campus($org);
    $userId = F::user($org);
    $actor = User::query()->findOrFail($userId);
    assignGoldenRole($actor, 'org_admin');
    $family = F::family($org);
    DefaultChartOfAccounts::seedFor($org);
    $poster = new LedgerPoster;
    $scope = new ScopeContext(organizationId: $org);

    return compact('org', 'campus', 'actor', 'family', 'poster', 'scope');
}

test('P3 voucher issue: Dr fee_receivable + Dr fee_discounts = Cr tuition_income + Cr other_fee_income', function () {
    $f = goldenFixture();
    $date = Carbon::create(2026, 8, 15);

    $request = new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: $date,
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'P3 August voucher run',
        lines: [
            PostingLine::debit('fee_receivable', 6_900_000, $f['family'], 'Tuition net receivable'),
            PostingLine::debit('fee_discounts', 1_100_000, null, 'Need-based discount'),
            PostingLine::credit('tuition_income', 7_000_000, null, 'Tuition fee income'),
            PostingLine::credit('other_fee_income', 1_000_000, null, 'Lab and library fee income'),
        ],
    );

    $entry = $f['poster']->post($request);
    expect($entry->line_count)->toBe(4)
        ->and($entry->total_minor)->toBe(8_000_000);

    // Retrieve trial balance
    $reports = new LedgerReports;
    $tb = $reports->trialBalance($f['actor'], $f['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f['scope']);

    $byKey = collect($tb)->keyBy('system_key');

    // Assert active account balances
    expect($byKey['fee_receivable']['debit_minor'])->toBe(6_900_000)
        ->and($byKey['fee_receivable']['credit_minor'])->toBe(0)
        ->and($byKey['fee_receivable']['balance_minor'])->toBe(6_900_000)
        ->and($byKey['fee_discounts']['debit_minor'])->toBe(1_100_000)
        ->and($byKey['fee_discounts']['credit_minor'])->toBe(0)
        ->and($byKey['fee_discounts']['balance_minor'])->toBe(1_100_000)
        ->and($byKey['tuition_income']['debit_minor'])->toBe(0)
        ->and($byKey['tuition_income']['credit_minor'])->toBe(7_000_000)
        ->and($byKey['tuition_income']['balance_minor'])->toBe(7_000_000)
        ->and($byKey['other_fee_income']['debit_minor'])->toBe(0)
        ->and($byKey['other_fee_income']['credit_minor'])->toBe(1_000_000)
        ->and($byKey['other_fee_income']['balance_minor'])->toBe(1_000_000);

    // Totals match
    $totalDebit = collect($tb)->sum('debit_minor');
    $totalCredit = collect($tb)->sum('credit_minor');
    expect($totalDebit)->toBe(8_000_000)
        ->and($totalCredit)->toBe(8_000_000);
});

test('P4 payment after due date: Dr cash = Cr fee_receivable + Cr late_fee_income, netting family balance to 0', function () {
    $f = goldenFixture();
    $reports = new LedgerReports;

    // 1. Post P3 voucher issue
    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'P3 voucher',
        lines: [
            PostingLine::debit('fee_receivable', 6_900_000, $f['family'], 'Tuition net'),
            PostingLine::debit('fee_discounts', 1_100_000, null, 'Discount'),
            PostingLine::credit('tuition_income', 7_000_000, null, 'Tuition'),
            PostingLine::credit('other_fee_income', 1_000_000, null, 'Lab'),
        ],
    ));

    // Family owes 6,900,000 after P3 alone
    $balanceAfterP3 = $reports->familyBalance($f['actor'], $f['org'], $f['family'], 'fee_receivable', $f['scope']);
    expect($balanceAfterP3)->toBe(6_900_000);

    // 2. Post P4 payment after due date
    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 25),
        sourceType: 'payment',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'P4 late payment',
        lines: [
            PostingLine::debit('cash', 7_000_000, null, 'Cash paid'),
            PostingLine::credit('fee_receivable', 6_900_000, $f['family'], 'Settling fee receivable'),
            PostingLine::credit('late_fee_income', 100_000, null, 'Late fee fine'),
        ],
    ));

    // Family owes 0 after P3 plus P4
    $balanceAfterP4 = $reports->familyBalance($f['actor'], $f['org'], $f['family'], 'fee_receivable', $f['scope']);
    expect($balanceAfterP4)->toBe(0);
});

test('P5 raw UPDATE and raw DELETE on journal_entries and journal_lines fail at database', function () {
    $f = goldenFixture();

    $entry = $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'Append only test',
        lines: [
            PostingLine::debit('cash', 100_000),
            PostingLine::credit('other_fee_income', 100_000),
        ],
    ));

    $lineId = DB::table('journal_lines')->where('entry_id', $entry->id)->value('id');

    // UPDATE on journal_entries
    expect(function () use ($entry) {
        DB::transaction(function () use ($entry) {
            DB::table('journal_entries')->where('id', $entry->id)->update(['memo' => 'Hacked']);
        });
    })->toThrow(QueryException::class);

    // DELETE on journal_entries
    expect(function () use ($entry) {
        DB::transaction(function () use ($entry) {
            DB::table('journal_entries')->where('id', $entry->id)->delete();
        });
    })->toThrow(QueryException::class);

    // UPDATE on journal_lines
    expect(function () use ($lineId) {
        DB::transaction(function () use ($lineId) {
            DB::table('journal_lines')->where('id', $lineId)->update(['debit_minor' => 200_000]);
        });
    })->toThrow(QueryException::class);

    // DELETE on journal_lines
    expect(function () use ($lineId) {
        DB::transaction(function () use ($lineId) {
            DB::table('journal_lines')->where('id', $lineId)->delete();
        });
    })->toThrow(QueryException::class);
});

test('P6 unbalanced entry is rejected by poster in PHP and by trigger in raw SQL', function () {
    $f = goldenFixture();

    // 1. Rejected by poster in PHP
    $unbalancedRequest = new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'Unbalanced',
        lines: [
            PostingLine::debit('cash', 1_000_000),
            PostingLine::credit('tuition_income', 900_000),
        ],
    );

    $countBefore = DB::table('journal_entries')->count();

    expect(fn () => $f['poster']->post($unbalancedRequest))
        ->toThrow(function (LedgerValidationException $e) {
            expect($e->reason)->toBe('unbalanced');
        });

    expect(DB::table('journal_entries')->count())->toBe($countBefore);

    // 2. Rejected by database trigger when inserted via raw SQL
    $period = DB::table('ledger_periods')->where('organization_id', $f['org'])->first();
    if ($period === null) {
        $periodId = F::id();
        DB::table('ledger_periods')->insert([
            'id' => $periodId,
            'organization_id' => $f['org'],
            'name' => '2026-08',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
            'status' => 'open',
            'created_at' => now(),
        ]);
    } else {
        $periodId = $period->id;
    }

    $cashAccount = Account::query()->where('organization_id', $f['org'])->where('system_key', 'cash')->firstOrFail();

    expect(function () use ($f, $periodId, $cashAccount) {
        DB::transaction(function () use ($f, $periodId, $cashAccount) {
            $rawId = (string) Str::ulid();
            DB::table('journal_entries')->insert([
                'id' => $rawId,
                'organization_id' => $f['org'],
                'campus_id' => $f['campus'],
                'period_id' => $periodId,
                'entry_date' => '2026-08-15',
                'source_type' => 'voucher',
                'source_id' => (string) Str::ulid(),
                'kind' => 'posting',
                'line_count' => 2,
                'total_minor' => 1_000_000,
                'lines_hash' => str_repeat('b', 64),
                'created_by' => $f['actor']->id,
                'created_at' => now(),
            ]);

            DB::table('journal_lines')->insert([
                [
                    'id' => (string) Str::ulid(),
                    'organization_id' => $f['org'],
                    'entry_id' => $rawId,
                    'line_no' => 1,
                    'account_id' => $cashAccount->id,
                    'debit_minor' => 1_000_000,
                    'credit_minor' => 0,
                    'created_at' => now(),
                ],
                [
                    'id' => (string) Str::ulid(),
                    'organization_id' => $f['org'],
                    'entry_id' => $rawId,
                    'line_no' => 2,
                    'account_id' => $cashAccount->id,
                    'debit_minor' => 0,
                    'credit_minor' => 900_000, // Unbalanced
                    'created_at' => now(),
                ],
            ]);

            DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
        });
    })->toThrow(QueryException::class);
});

test('P7 reversal: original untouched, idempotent second call, trial balance nets to zero', function () {
    $f = goldenFixture();
    $reports = new LedgerReports;

    $original = $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'P7 Original voucher',
        lines: [
            PostingLine::debit('fee_receivable', 6_900_000, $f['family'], 'Tuition net'),
            PostingLine::debit('fee_discounts', 1_100_000, null, 'Discount'),
            PostingLine::credit('tuition_income', 7_000_000, null, 'Tuition'),
            PostingLine::credit('other_fee_income', 1_000_000, null, 'Lab'),
        ],
    ));

    $beforeRow = (array) DB::table('journal_entries')->where('id', $original->id)->first();
    $beforeLines = DB::table('journal_lines')->where('entry_id', $original->id)->orderBy('line_no')->get()->map(fn ($r) => (array) $r)->all();

    $reversal = $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 20), 'P7 Reversal');

    // Original is byte-identical
    $afterRow = (array) DB::table('journal_entries')->where('id', $original->id)->first();
    $afterLines = DB::table('journal_lines')->where('entry_id', $original->id)->orderBy('line_no')->get()->map(fn ($r) => (array) $r)->all();
    expect($afterRow)->toEqual($beforeRow)
        ->and($afterLines)->toEqual($beforeLines);

    // Second reverse returns same id
    $secondReversal = $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 20));
    expect($secondReversal->id)->toBe($reversal->id);

    // Trial balance over whole range nets to zero for every account
    $tb = $reports->trialBalance($f['actor'], $f['org'], Carbon::create(2026, 8, 1), Carbon::create(2026, 8, 31), $f['scope']);

    foreach ($tb as $row) {
        expect($row['balance_minor'])->toBe(0)
            ->and($row['debit_minor'])->toBe($row['credit_minor']);
    }
});

test('P8 security deposit refund 1,000,000: Dr security_deposits = Cr cash, family balance is -1,000,000', function () {
    $f = goldenFixture();
    $reports = new LedgerReports;

    $f['poster']->post(new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: Carbon::create(2026, 8, 15),
        sourceType: 'refund',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'P8 Security deposit refund',
        lines: [
            PostingLine::debit('security_deposits', 1_000_000, $f['family'], 'Refund deposit'),
            PostingLine::credit('cash', 1_000_000, null, 'Cash paid out'),
        ],
    ));

    $bal = $reports->familyBalance($f['actor'], $f['org'], $f['family'], 'security_deposits', $f['scope']);

    // Security deposits is a liability (credit-normal); debited 1,000,000 so balance is credit - debit = 0 - 1,000,000 = -1,000,000.
    expect($bal)->toBe(-1_000_000);
});
