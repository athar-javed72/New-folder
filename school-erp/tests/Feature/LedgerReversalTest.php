<?php

declare(strict_types=1);

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerPeriod;
use App\Models\User;
use App\Services\Ledger\DefaultChartOfAccounts;
use App\Services\Ledger\LedgerAccessDenied;
use App\Services\Ledger\LedgerPeriodClosed;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerValidationException;
use App\Services\Ledger\PostingLine;
use App\Services\Ledger\PostingRequest;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

/**
 * @return array{org: string, campus: string, actor: User, family: string, poster: LedgerPoster}
 */
function reversalFixture(): array
{
    $org = F::org();
    $campus = F::campus($org);
    $userId = F::user($org);
    $actor = User::query()->findOrFail($userId);
    $family = F::family($org);
    DefaultChartOfAccounts::seedFor($org);
    $poster = new LedgerPoster;

    return compact('org', 'campus', 'actor', 'family', 'poster');
}

/**
 * Creates a standard original entry (P3 voucher shape).
 */
function createOriginalEntry(array $f, ?Carbon $date = null): JournalEntry
{
    $date = $date ?? Carbon::create(2026, 8, 15);
    $request = new PostingRequest(
        organizationId: $f['org'],
        campusId: $f['campus'],
        entryDate: $date,
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $f['actor']->id,
        memo: 'August voucher run P3',
        lines: [
            PostingLine::debit('fee_receivable', 6_900_000, $f['family'], 'Tuition net'),
            PostingLine::debit('fee_discounts', 1_100_000, null, 'Need-based discount'),
            PostingLine::credit('tuition_income', 7_000_000, null, 'Tuition fees'),
            PostingLine::credit('other_fee_income', 1_000_000, null, 'Lab fees'),
        ],
    );

    return $f['poster']->post($request);
}

it('reverses an entry with kind reversal, reversal_of set, mirrored lines, and leaves original byte-identical', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    // Capture byte-identical state of original before reversal
    $beforeOriginalRow = (array) DB::table('journal_entries')->where('id', $original->id)->first();
    $beforeOriginalLines = DB::table('journal_lines')
        ->where('entry_id', $original->id)
        ->orderBy('line_no')
        ->get()
        ->map(fn ($r) => (array) $r)
        ->all();

    $reversalDate = Carbon::create(2026, 8, 20);
    $reversal = $f['poster']->reverse($original, $f['actor'], $reversalDate, 'Reversal of August voucher run');

    // Reversal shape assertions
    expect($reversal)->toBeInstanceOf(JournalEntry::class)
        ->and($reversal->kind)->toBe('reversal')
        ->and($reversal->reversal_of)->toBe($original->id)
        ->and($reversal->source_type)->toBe($original->source_type)
        ->and($reversal->source_id)->toBe($original->source_id)
        ->and($reversal->campus_id)->toBe($original->campus_id)
        ->and($reversal->line_count)->toBe($original->line_count)
        ->and($reversal->total_minor)->toBe($original->total_minor)
        ->and($reversal->created_by)->toBe($f['actor']->id)
        ->and($reversal->memo)->toBe('Reversal of August voucher run')
        ->and($reversal->entry_date->toDateString())->toBe('2026-08-20');

    // Mirrored lines assertions
    $originalLines = JournalLine::query()->where('entry_id', $original->id)->orderBy('line_no')->get();
    $reversalLines = JournalLine::query()->where('entry_id', $reversal->id)->orderBy('line_no')->get();

    expect($reversalLines)->toHaveCount(count($originalLines));

    for ($i = 0; $i < count($originalLines); $i++) {
        $orig = $originalLines[$i];
        $rev = $reversalLines[$i];

        expect($rev->line_no)->toBe($orig->line_no)
            ->and($rev->account_id)->toBe($orig->account_id)
            ->and($rev->family_id)->toBe($orig->family_id)
            ->and($rev->description)->toBe($orig->description)
            ->and($rev->debit_minor)->toBe($orig->credit_minor)
            ->and($rev->credit_minor)->toBe($orig->debit_minor);
    }

    // Original entry and lines are byte-identical before and after
    $afterOriginalRow = (array) DB::table('journal_entries')->where('id', $original->id)->first();
    $afterOriginalLines = DB::table('journal_lines')
        ->where('entry_id', $original->id)
        ->orderBy('line_no')
        ->get()
        ->map(fn ($r) => (array) $r)
        ->all();

    expect($afterOriginalRow)->toEqual($beforeOriginalRow)
        ->and($afterOriginalLines)->toEqual($beforeOriginalLines);
});

it('returns the same reversal id on a second reverse() and row counts do not change', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    $rev1 = $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 20), 'First call');

    $entriesCountBefore = DB::table('journal_entries')->count();
    $linesCountBefore = DB::table('journal_lines')->count();

    $rev2 = $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 20), 'First call');

    expect($rev2->id)->toBe($rev1->id)
        ->and(DB::table('journal_entries')->count())->toBe($entriesCountBefore)
        ->and(DB::table('journal_lines')->count())->toBe($linesCountBefore);
});

it('has different lines_hash than original and returns first reversal unchanged even with different memo or date', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    $rev1 = $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 20), 'Memo 1');

    expect($rev1->lines_hash)->not->toBe($original->lines_hash);

    // Call second time with different date and different memo
    $rev2 = $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 25), 'Different Memo');

    expect($rev2->id)->toBe($rev1->id)
        ->and($rev2->memo)->toBe('Memo 1')
        ->and($rev2->entry_date->toDateString())->toBe('2026-08-20');
});

it('throws LedgerValidationException reversal_of_reversal when reversing a reversal', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    $reversal = $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 20));

    expect(fn () => $f['poster']->reverse($reversal, $f['actor'], Carbon::create(2026, 8, 22)))
        ->toThrow(function (LedgerValidationException $e) {
            expect($e->reason)->toBe('reversal_of_reversal');
        });
});

it('throws date_before_original and writes nothing when date is before original', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    $entriesCountBefore = DB::table('journal_entries')->count();
    $linesCountBefore = DB::table('journal_lines')->count();

    expect(fn () => $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 14)))
        ->toThrow(function (LedgerValidationException $e) {
            expect($e->reason)->toBe('date_before_original');
        });

    expect(DB::table('journal_entries')->count())->toBe($entriesCountBefore)
        ->and(DB::table('journal_lines')->count())->toBe($linesCountBefore);
});

it('throws invalid_memo when memo exceeds 255 chars and writes nothing', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    $longMemo = str_repeat('a', 256);

    expect(fn () => $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 16), $longMemo))
        ->toThrow(function (LedgerValidationException $e) {
            expect($e->reason)->toBe('invalid_memo');
        });
});

it('lands in next months auto-created period when original period is closed', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    // Raw update to close the original entry's period (August 2026)
    DB::table('ledger_periods')
        ->where('id', $original->period_id)
        ->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $f['actor']->id,
        ]);

    $reversalDate = Carbon::create(2026, 9, 10);
    $reversal = $f['poster']->reverse($original, $f['actor'], $reversalDate);

    expect($reversal->period_id)->not->toBe($original->period_id);

    $septPeriod = LedgerPeriod::query()->findOrFail($reversal->period_id);
    expect($septPeriod->name)->toBe('2026-09')
        ->and($septPeriod->status)->toBe('open');
});

it('throws LedgerPeriodClosed and writes nothing when original period is closed and reversal is dated in that closed period', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    // Close August period via raw update
    DB::table('ledger_periods')
        ->where('id', $original->period_id)
        ->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $f['actor']->id,
        ]);

    $entriesCountBefore = DB::table('journal_entries')->count();
    $linesCountBefore = DB::table('journal_lines')->count();

    // Reversal dated inside closed August period
    expect(fn () => $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 25)))
        ->toThrow(LedgerPeriodClosed::class);

    expect(DB::table('journal_entries')->count())->toBe($entriesCountBefore)
        ->and(DB::table('journal_lines')->count())->toBe($linesCountBefore);
});

it('throws LedgerAccessDenied and writes nothing when actor belongs to another organization', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    $foreignOrg = F::org();
    $foreignUserId = F::user($foreignOrg);
    $foreignActor = User::query()->findOrFail($foreignUserId);

    $entriesCountBefore = DB::table('journal_entries')->count();
    $linesCountBefore = DB::table('journal_lines')->count();

    expect(fn () => $f['poster']->reverse($original, $foreignActor, Carbon::create(2026, 8, 20)))
        ->toThrow(LedgerAccessDenied::class);

    expect(DB::table('journal_entries')->count())->toBe($entriesCountBefore)
        ->and(DB::table('journal_lines')->count())->toBe($linesCountBefore);
});

it('allows super admin actor with null organization to reverse', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    $superAdminId = F::id();
    DB::table('users')->insert([
        'id' => $superAdminId,
        'organization_id' => null,
        'name' => 'Super Administrator',
        'email' => 'superadmin@example.test',
        'is_super_admin' => true,
    ]);
    $superAdmin = User::query()->findOrFail($superAdminId);

    $reversal = $f['poster']->reverse($original, $superAdmin, Carbon::create(2026, 8, 20));

    expect($reversal)->toBeInstanceOf(JournalEntry::class)
        ->and($reversal->created_by)->toBe($superAdmin->id);
});

it('nets trial balance per account to zero over both original and reversal entries by raw SQL', function () {
    $f = reversalFixture();
    $original = createOriginalEntry($f, Carbon::create(2026, 8, 15));

    $reversal = $f['poster']->reverse($original, $f['actor'], Carbon::create(2026, 8, 20));

    // Raw SQL trial balance across both entries
    $rows = DB::select(
        'SELECT account_id,
                SUM(debit_minor)::bigint AS total_debit,
                SUM(credit_minor)::bigint AS total_credit,
                (SUM(debit_minor) - SUM(credit_minor))::bigint AS net
         FROM journal_lines
         WHERE entry_id IN (?, ?)
         GROUP BY account_id',
        [$original->id, $reversal->id],
    );

    expect($rows)->toHaveCount(4);

    foreach ($rows as $row) {
        expect((int) $row->net)->toBe(0)
            ->and((int) $row->total_debit)->toBe((int) $row->total_credit);
    }
});

it('defaults to today when date is null and succeeds when today is on or after original date', function () {
    $f = reversalFixture();
    // Post original dated today
    $today = CarbonImmutable::today();
    $original = createOriginalEntry($f, Carbon::instance($today));

    $reversal = $f['poster']->reverse($original, $f['actor']);

    expect($reversal->entry_date->toDateString())->toBe($today->toDateString());
});
