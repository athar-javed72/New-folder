<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

/**
 * Raw-SQL tests verifying ledger core schema constraints and triggers.
 * Models do not exist yet (Task 2). Every expected failure runs inside a nested transaction savepoint.
 */

/**
 * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
 */
function ledgerTenant(): array
{
    $org = F::org();
    $campus = F::campus($org);
    $user = F::user($org);
    $period = F::period($org, [
        'name' => '2026-07',
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-07-31',
    ]);
    $cashAcc = F::account($org, [
        'code' => '1000',
        'name' => 'Cash in Hand',
        'type' => 'asset',
        'system_key' => 'cash',
    ]);
    $tuitionAcc = F::account($org, [
        'code' => '4000',
        'name' => 'Tuition Fee Income',
        'type' => 'income',
        'system_key' => 'tuition_income',
    ]);

    return [$org, $campus, $user, $period, $cashAcc, $tuitionAcc];
}

it('allows a balanced entry with matching declared shape and total', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();

    DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc) {
        $entry = F::entry($org, $campus, $period, $user, [
            'line_count' => 2,
            'total_minor' => 50000,
        ]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);

        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');
    });

    expect(DB::table('journal_entries')->where('organization_id', $org)->count())->toBe(1)
        ->and(DB::table('journal_lines')->where('organization_id', $org)->count())->toBe(2);
});

it('rejects an unbalanced journal entry at deferred commit check', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();

    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc) {
        $entry = F::entry($org, $campus, $period, $user, [
            'line_count' => 2,
            'total_minor' => 50000,
        ]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 40000]);

        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('rejects an entry with no lines at deferred commit check', function () {
    [$org, $campus, $user, $period] = ledgerTenant();

    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user) {
        F::entry($org, $campus, $period, $user, [
            'line_count' => 2,
            'total_minor' => 50000,
        ]);

        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('rejects an entry whose line count differs from declared line count', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();

    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc) {
        $entry = F::entry($org, $campus, $period, $user, [
            'line_count' => 3,
            'total_minor' => 50000,
        ]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);

        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('rejects an entry whose lines total differs from declared total', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();

    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc) {
        $entry = F::entry($org, $campus, $period, $user, [
            'line_count' => 2,
            'total_minor' => 60000,
        ]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);

        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('rejects adding a balanced pair of lines to a committed entry in a later transaction', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();

    $entry = DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc) {
        $entry = F::entry($org, $campus, $period, $user, [
            'line_count' => 2,
            'total_minor' => 50000,
        ]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);

        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');

        return $entry;
    });

    expect(fn () => DB::transaction(function () use ($org, $entry, $cashAcc, $tuitionAcc) {
        F::line($org, $entry, $cashAcc, ['line_no' => 3, 'debit_minor' => 10000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 4, 'debit_minor' => 0, 'credit_minor' => 10000]);

        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
    }))->toThrow(QueryException::class);
});

it('prevents update and delete on journal entries and journal lines', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();

    $entry = DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc) {
        $entry = F::entry($org, $campus, $period, $user, [
            'line_count' => 2,
            'total_minor' => 50000,
        ]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);

        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');

        return $entry;
    });

    // UPDATE journal_entries fails
    expect(fn () => DB::transaction(function () use ($entry) {
        DB::table('journal_entries')->where('id', $entry)->update(['memo' => 'Tampered memo']);
    }))->toThrow(QueryException::class);

    // DELETE journal_entries fails
    expect(fn () => DB::transaction(function () use ($entry) {
        DB::table('journal_entries')->where('id', $entry)->delete();
    }))->toThrow(QueryException::class);

    // UPDATE journal_lines fails
    expect(fn () => DB::transaction(function () use ($entry) {
        DB::table('journal_lines')->where('entry_id', $entry)->where('line_no', 1)->update(['description' => 'Tampered line']);
    }))->toThrow(QueryException::class);

    // DELETE journal_lines fails
    expect(fn () => DB::transaction(function () use ($entry) {
        DB::table('journal_lines')->where('entry_id', $entry)->where('line_no', 1)->delete();
    }))->toThrow(QueryException::class);
});

it('rejects a journal line with both sides positive, both zero, or a negative amount', function () {
    [$org, $campus, $user, $period, $cashAcc] = ledgerTenant();
    $entry = F::entry($org, $campus, $period, $user);

    // Both debit and credit positive
    expect(fn () => DB::transaction(function () use ($org, $entry, $cashAcc) {
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 5000, 'credit_minor' => 5000]);
    }))->toThrow(QueryException::class);

    // Both debit and credit zero
    expect(fn () => DB::transaction(function () use ($org, $entry, $cashAcc) {
        F::line($org, $entry, $cashAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 0]);
    }))->toThrow(QueryException::class);

    // Negative debit
    expect(fn () => DB::transaction(function () use ($org, $entry, $cashAcc) {
        F::line($org, $entry, $cashAcc, ['line_no' => 3, 'debit_minor' => -100, 'credit_minor' => 0]);
    }))->toThrow(QueryException::class);

    // Negative credit
    expect(fn () => DB::transaction(function () use ($org, $entry, $cashAcc) {
        F::line($org, $entry, $cashAcc, ['line_no' => 4, 'debit_minor' => 0, 'credit_minor' => -100]);
    }))->toThrow(QueryException::class);
});

it('rejects a journal line on an account requiring family when family is null', function () {
    [$org, $campus, $user, $period] = ledgerTenant();
    $receivableAcc = F::account($org, [
        'code' => '1100',
        'name' => 'Fee Receivable',
        'type' => 'asset',
        'system_key' => 'fee_receivable',
        'requires_family' => true,
    ]);
    $entry = F::entry($org, $campus, $period, $user);

    expect(fn () => DB::transaction(function () use ($org, $entry, $receivableAcc) {
        F::line($org, $entry, $receivableAcc, ['line_no' => 1, 'family_id' => null, 'debit_minor' => 50000, 'credit_minor' => 0]);
    }))->toThrow(QueryException::class);
});

it('rejects a journal line on an inactive account', function () {
    [$org, $campus, $user, $period] = ledgerTenant();
    $inactiveAcc = F::account($org, [
        'code' => '1099',
        'name' => 'Inactive Account',
        'type' => 'asset',
        'is_active' => false,
    ]);
    $entry = F::entry($org, $campus, $period, $user);

    expect(fn () => DB::transaction(function () use ($org, $entry, $inactiveAcc) {
        F::line($org, $entry, $inactiveAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
    }))->toThrow(QueryException::class);
});

it('rejects an account, family or campus of another organization', function () {
    [$orgA, $campusA, $userA, $periodA, $cashAccA] = ledgerTenant();
    [$orgB, $campusB, , , $cashAccB] = ledgerTenant();
    $familyB = F::family($orgB);

    // 1. Campus of another org on journal_entries
    expect(fn () => DB::transaction(function () use ($orgA, $campusB, $periodA, $userA) {
        F::entry($orgA, $campusB, $periodA, $userA);
    }))->toThrow(QueryException::class);

    // 2. Account of another org on journal_lines
    $entryA = F::entry($orgA, $campusA, $periodA, $userA);
    expect(fn () => DB::transaction(function () use ($orgA, $entryA, $cashAccB) {
        F::line($orgA, $entryA, $cashAccB, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
    }))->toThrow(QueryException::class);

    // 3. Family of another org on journal_lines
    expect(fn () => DB::transaction(function () use ($orgA, $entryA, $cashAccA, $familyB) {
        F::line($orgA, $entryA, $cashAccA, ['line_no' => 1, 'family_id' => $familyB, 'debit_minor' => 50000, 'credit_minor' => 0]);
    }))->toThrow(QueryException::class);
});

it('rejects an entry date outside its ledger period', function () {
    [$org, $campus, $user, $period] = ledgerTenant(); // Period is 2026-07-01 to 2026-07-31

    // Before period starts_on
    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user) {
        F::entry($org, $campus, $period, $user, ['entry_date' => '2026-06-30']);
    }))->toThrow(QueryException::class);

    // After period ends_on
    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user) {
        F::entry($org, $campus, $period, $user, ['entry_date' => '2026-08-01']);
    }))->toThrow(QueryException::class);
});

it('rejects posting an entry into a closed ledger period', function () {
    [$org, $campus, $user] = ledgerTenant();
    $closedPeriod = F::period($org, [
        'name' => '2026-06',
        'starts_on' => '2026-06-01',
        'ends_on' => '2026-06-30',
        'status' => 'closed',
        'closed_at' => now(),
        'closed_by' => $user,
    ]);

    expect(fn () => DB::transaction(function () use ($org, $campus, $closedPeriod, $user) {
        F::entry($org, $campus, $closedPeriod, $user, ['entry_date' => '2026-06-15']);
    }))->toThrow(QueryException::class);
});

it('prevents reopening or editing a closed ledger period', function () {
    [$org, , $user, $period] = ledgerTenant();

    DB::table('ledger_periods')->where('id', $period)->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closed_by' => $user,
    ]);

    // Reopening is rejected
    expect(fn () => DB::transaction(function () use ($period) {
        DB::table('ledger_periods')->where('id', $period)->update(['status' => 'open', 'closed_at' => null]);
    }))->toThrow(QueryException::class);

    // Editing closed period name is rejected
    expect(fn () => DB::transaction(function () use ($period) {
        DB::table('ledger_periods')->where('id', $period)->update(['name' => '2026-07-Modified']);
    }))->toThrow(QueryException::class);
});

it('rejects overlapping ledger periods within the same organization but allows them in another', function () {
    [$orgA] = ledgerTenant();
    $orgB = F::org();

    // Overlapping period in orgA fails (GiST exclusion constraint)
    expect(fn () => DB::transaction(function () use ($orgA) {
        F::period($orgA, [
            'name' => '2026-07-overlap',
            'starts_on' => '2026-07-15',
            'ends_on' => '2026-08-15',
        ]);
    }))->toThrow(QueryException::class);

    // Identical dates in orgB succeed
    $periodB = F::period($orgB, [
        'name' => '2026-07',
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-07-31',
    ]);
    expect(DB::table('ledger_periods')->where('id', $periodB)->count())->toBe(1);
});

it('prevents changing ledger period dates once journal entries exist', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();

    DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc) {
        $entry = F::entry($org, $campus, $period, $user, ['line_count' => 2, 'total_minor' => 50000]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');
    });

    // Attempt to change starts_on
    expect(fn () => DB::transaction(function () use ($period) {
        DB::table('ledger_periods')->where('id', $period)->update(['starts_on' => '2026-07-02']);
    }))->toThrow(QueryException::class);

    // Attempt to change ends_on
    expect(fn () => DB::transaction(function () use ($period) {
        DB::table('ledger_periods')->where('id', $period)->update(['ends_on' => '2026-08-01']);
    }))->toThrow(QueryException::class);
});

it('rejects a duplicate source type, source id and kind while on conflict do nothing inserts nothing', function () {
    [$org, $campus, $user, $period] = ledgerTenant();
    $sourceId = F::id();

    F::entry($org, $campus, $period, $user, [
        'source_type' => 'payment',
        'source_id' => $sourceId,
        'kind' => 'posting',
    ]);

    // Duplicate insert throws unique violation
    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $sourceId) {
        F::entry($org, $campus, $period, $user, [
            'source_type' => 'payment',
            'source_id' => $sourceId,
            'kind' => 'posting',
        ]);
    }))->toThrow(QueryException::class);

    // ON CONFLICT DO NOTHING inserts nothing
    $inserted = DB::table('journal_entries')->insertOrIgnore([
        'id' => F::id(),
        'organization_id' => $org,
        'campus_id' => $campus,
        'period_id' => $period,
        'entry_date' => '2026-07-15',
        'source_type' => 'payment',
        'source_id' => $sourceId,
        'kind' => 'posting',
        'reversal_of' => null,
        'line_count' => 2,
        'total_minor' => 50000,
        'lines_hash' => hash('sha256', 'duplicate_seed'),
        'created_by' => $user,
        'created_at' => now(),
    ]);

    expect($inserted)->toBe(0)
        ->and(DB::table('journal_entries')->where('organization_id', $org)->count())->toBe(1);
});

it('enforces reversal rules and accepts a valid mirror reversal', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();
    $campus2 = F::campus($org);
    $sourceId = F::id();

    $postingId = DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc, $sourceId) {
        $entry = F::entry($org, $campus, $period, $user, [
            'source_type' => 'payment',
            'source_id' => $sourceId,
            'kind' => 'posting',
            'reversal_of' => null,
            'line_count' => 2,
            'total_minor' => 50000,
        ]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');

        return $entry;
    });

    // 1. kind = 'posting' with reversal_of is rejected
    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $postingId) {
        F::entry($org, $campus, $period, $user, [
            'source_type' => 'voucher',
            'source_id' => F::id(),
            'kind' => 'posting',
            'reversal_of' => $postingId,
        ]);
    }))->toThrow(QueryException::class);

    // 2. Different total in reversal is rejected
    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $sourceId, $postingId) {
        F::entry($org, $campus, $period, $user, [
            'source_type' => 'payment',
            'source_id' => $sourceId,
            'kind' => 'reversal',
            'reversal_of' => $postingId,
            'total_minor' => 60000,
            'line_count' => 2,
        ]);
    }))->toThrow(QueryException::class);

    // 3. Another source in reversal is rejected
    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $postingId) {
        F::entry($org, $campus, $period, $user, [
            'source_type' => 'payment',
            'source_id' => F::id(),
            'kind' => 'reversal',
            'reversal_of' => $postingId,
            'total_minor' => 50000,
            'line_count' => 2,
        ]);
    }))->toThrow(QueryException::class);

    // 4. Another campus in reversal is rejected
    expect(fn () => DB::transaction(function () use ($org, $campus2, $period, $user, $sourceId, $postingId) {
        F::entry($org, $campus2, $period, $user, [
            'source_type' => 'payment',
            'source_id' => $sourceId,
            'kind' => 'reversal',
            'reversal_of' => $postingId,
            'total_minor' => 50000,
            'line_count' => 2,
        ]);
    }))->toThrow(QueryException::class);

    // 5. Valid mirror reversal is accepted
    $reversalId = DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc, $sourceId, $postingId) {
        $rev = F::entry($org, $campus, $period, $user, [
            'source_type' => 'payment',
            'source_id' => $sourceId,
            'kind' => 'reversal',
            'reversal_of' => $postingId,
            'line_count' => 2,
            'total_minor' => 50000,
        ]);
        F::line($org, $rev, $cashAcc, ['line_no' => 1, 'debit_minor' => 0, 'credit_minor' => 50000]);
        F::line($org, $rev, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 50000, 'credit_minor' => 0]);
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');

        return $rev;
    });

    expect(DB::table('journal_entries')->where('id', $reversalId)->count())->toBe(1);

    // 6. Second reversal of one posting is rejected
    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $sourceId, $postingId) {
        F::entry($org, $campus, $period, $user, [
            'source_type' => 'payment',
            'source_id' => $sourceId,
            'kind' => 'reversal',
            'reversal_of' => $postingId,
            'line_count' => 2,
            'total_minor' => 50000,
        ]);
    }))->toThrow(QueryException::class);

    // 7. Reversal of a reversal is rejected
    expect(fn () => DB::transaction(function () use ($org, $campus, $period, $user, $sourceId, $reversalId) {
        F::entry($org, $campus, $period, $user, [
            'source_type' => 'payment',
            'source_id' => $sourceId,
            'kind' => 'reversal',
            'reversal_of' => $reversalId,
            'line_count' => 2,
            'total_minor' => 50000,
        ]);
    }))->toThrow(QueryException::class);
});

it('prevents account type change after it has lines and prevents deleting an account with lines', function () {
    [$org, $campus, $user, $period, $cashAcc, $tuitionAcc] = ledgerTenant();

    DB::transaction(function () use ($org, $campus, $period, $user, $cashAcc, $tuitionAcc) {
        $entry = F::entry($org, $campus, $period, $user, ['line_count' => 2, 'total_minor' => 50000]);
        F::line($org, $entry, $cashAcc, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
        F::line($org, $entry, $tuitionAcc, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
        DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');
    });

    // Account type cannot change
    expect(fn () => DB::transaction(function () use ($cashAcc) {
        DB::table('accounts')->where('id', $cashAcc)->update(['type' => 'liability']);
    }))->toThrow(QueryException::class);

    // Account cannot be deleted
    expect(fn () => DB::transaction(function () use ($cashAcc) {
        DB::table('accounts')->where('id', $cashAcc)->delete();
    }))->toThrow(QueryException::class);
});

it('enforces unique system key per organization', function () {
    $orgA = F::org();
    $orgB = F::org();

    F::account($orgA, ['code' => '1000', 'name' => 'Cash A', 'system_key' => 'cash']);

    // Duplicate system_key in orgA is rejected
    expect(fn () => DB::transaction(function () use ($orgA) {
        F::account($orgA, ['code' => '1001', 'name' => 'Cash A2', 'system_key' => 'cash']);
    }))->toThrow(QueryException::class);

    // Same system_key in orgB is allowed
    $accB = F::account($orgB, ['code' => '1000', 'name' => 'Cash B', 'system_key' => 'cash']);
    expect(DB::table('accounts')->where('id', $accB)->count())->toBe(1);

    // Null system_key is allowed multiple times in orgA
    $accNull1 = F::account($orgA, ['code' => '9001', 'name' => 'Custom 1', 'system_key' => null]);
    $accNull2 = F::account($orgA, ['code' => '9002', 'name' => 'Custom 2', 'system_key' => null]);
    expect(DB::table('accounts')->where('id', $accNull1)->count())->toBe(1)
        ->and(DB::table('accounts')->where('id', $accNull2)->count())->toBe(1);
});

it('enforces number sequence rules: advance by 1 passes, jumps, decrements, deletes, and identity changes fail', function () {
    [$org, $campus] = ledgerTenant();
    $org2 = F::org();
    $campus2 = F::campus($org);

    $seqId = F::sequence($org, $campus, [
        'key' => 'receipt',
        'fiscal_year' => 2026,
        'last_number' => 10,
    ]);

    // 1. Advance by exactly 1 passes
    DB::table('number_sequences')->where('id', $seqId)->update(['last_number' => 11]);
    expect((int) DB::table('number_sequences')->where('id', $seqId)->value('last_number'))->toBe(11);

    // 2. Jump by 2 fails
    expect(fn () => DB::transaction(function () use ($seqId) {
        DB::table('number_sequences')->where('id', $seqId)->update(['last_number' => 13]);
    }))->toThrow(QueryException::class);

    // 3. Go backwards fails
    expect(fn () => DB::transaction(function () use ($seqId) {
        DB::table('number_sequences')->where('id', $seqId)->update(['last_number' => 10]);
    }))->toThrow(QueryException::class);

    // 4. Delete fails
    expect(fn () => DB::transaction(function () use ($seqId) {
        DB::table('number_sequences')->where('id', $seqId)->delete();
    }))->toThrow(QueryException::class);

    // 5. Change organization_id fails
    expect(fn () => DB::transaction(function () use ($seqId, $org2) {
        DB::table('number_sequences')->where('id', $seqId)->update(['organization_id' => $org2]);
    }))->toThrow(QueryException::class);

    // 6. Change campus_id fails
    expect(fn () => DB::transaction(function () use ($seqId, $campus2) {
        DB::table('number_sequences')->where('id', $seqId)->update(['campus_id' => $campus2]);
    }))->toThrow(QueryException::class);

    // 7. Change key fails
    expect(fn () => DB::transaction(function () use ($seqId) {
        DB::table('number_sequences')->where('id', $seqId)->update(['key' => 'voucher']);
    }))->toThrow(QueryException::class);

    // 8. Change fiscal_year fails
    expect(fn () => DB::transaction(function () use ($seqId) {
        DB::table('number_sequences')->where('id', $seqId)->update(['fiscal_year' => 2027]);
    }))->toThrow(QueryException::class);
});
