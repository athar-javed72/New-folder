<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerPeriod;
use App\Models\NumberSequence;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

/** @return array{org: string, campus: string, entry: string, debit: string, credit: string, period: string} */
function postedTestEntry(): array
{
    $org = F::org();
    $campus = F::campus($org);
    $user = F::user($org);
    $period = F::period($org);
    $cash = F::account($org, ['code' => '1000', 'name' => 'Test Cash', 'type' => 'asset']);
    $income = F::account($org, ['code' => '4000', 'name' => 'Test Income', 'type' => 'income']);

    $entry = F::entry($org, $campus, $period, $user, ['line_count' => 2, 'total_minor' => 50000]);
    F::line($org, $entry, $cash, ['line_no' => 1, 'debit_minor' => 50000, 'credit_minor' => 0]);
    F::line($org, $entry, $income, ['line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 50000]);
    DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
    DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');

    return ['org' => $org, 'campus' => $campus, 'entry' => $entry, 'debit' => $cash, 'credit' => $income, 'period' => $period];
}

it('throws LogicException when a JournalEntry model is updated', function () {
    $t = postedTestEntry();
    $entry = JournalEntry::query()->where('organization_id', $t['org'])->findOrFail($t['entry']);

    $entry->memo = 'Changed test memo';
    expect(fn () => $entry->save())->toThrow(LogicException::class);
    expect(DB::table('journal_entries')->where('id', $t['entry'])->value('memo'))->toBe('Test entry');
});

it('throws LogicException when a JournalEntry model is deleted', function () {
    $t = postedTestEntry();
    $entry = JournalEntry::query()->where('organization_id', $t['org'])->findOrFail($t['entry']);

    expect(fn () => $entry->delete())->toThrow(LogicException::class);
    expect(DB::table('journal_entries')->where('id', $t['entry'])->exists())->toBeTrue();
});

it('throws LogicException when a JournalLine model is updated', function () {
    $t = postedTestEntry();
    $line = JournalLine::query()->where('organization_id', $t['org'])->where('entry_id', $t['entry'])->where('line_no', 1)->firstOrFail();

    $line->description = 'Changed test line';
    expect(fn () => $line->save())->toThrow(LogicException::class);
});

it('throws LogicException when a JournalLine model is deleted', function () {
    $t = postedTestEntry();
    $line = JournalLine::query()->where('organization_id', $t['org'])->where('entry_id', $t['entry'])->where('line_no', 1)->firstOrFail();

    expect(fn () => $line->delete())->toThrow(LogicException::class);
    expect(DB::table('journal_lines')->where('entry_id', $t['entry'])->count())->toBe(2);
});

it('throws LogicException when a NumberSequence model is deleted', function () {
    $org = F::org();
    $campus = F::campus($org);
    $id = F::sequence($org, $campus);
    $sequence = NumberSequence::query()->where('organization_id', $org)->findOrFail($id);

    expect(fn () => $sequence->delete())->toThrow(LogicException::class);
    expect(DB::table('number_sequences')->where('id', $id)->exists())->toBeTrue();
});

it('casts money to integer, dates to date and flags to boolean', function () {
    $t = postedTestEntry();
    $entry = JournalEntry::query()->where('organization_id', $t['org'])->findOrFail($t['entry']);
    $line = $entry->lines()->where('line_no', 1)->firstOrFail();
    $account = Account::query()->where('organization_id', $t['org'])->findOrFail($t['debit']);
    $period = LedgerPeriod::query()->where('organization_id', $t['org'])->findOrFail($t['period']);

    expect($entry->total_minor)->toBe(50000)
        ->and($entry->line_count)->toBe(2)
        ->and($entry->entry_date->toDateString())->toBe('2026-07-15')
        ->and($entry->updated_at)->toBeNull()
        ->and($line->debit_minor)->toBe(50000)
        ->and($line->credit_minor)->toBe(0)
        ->and($account->requires_family)->toBeFalse()
        ->and($account->is_active)->toBeTrue()
        ->and($period->starts_on->toDateString())->toBe('2026-07-01')
        ->and($period->ends_on->toDateString())->toBe('2026-07-31');
});

it('resolves ledger relations', function () {
    $t = postedTestEntry();
    $entry = JournalEntry::query()->where('organization_id', $t['org'])->findOrFail($t['entry']);

    expect($entry->lines)->toHaveCount(2)
        ->and($entry->period->id)->toBe($t['period'])
        ->and($entry->reversalOf)->toBeNull()
        ->and($entry->reversal)->toBeNull()
        ->and($entry->lines->firstWhere('line_no', 1)->account->id)->toBe($t['debit'])
        ->and($entry->lines->firstWhere('line_no', 1)->entry->id)->toBe($t['entry'])
        ->and(Account::query()->findOrFail($t['credit'])->lines)->toHaveCount(1)
        ->and(LedgerPeriod::query()->findOrFail($t['period'])->entries)->toHaveCount(1);
});

it('registers morph aliases for the ledger models', function () {
    expect(Relation::getMorphedModel('account'))->toBe(Account::class)
        ->and(Relation::getMorphedModel('ledger_period'))->toBe(LedgerPeriod::class)
        ->and(Relation::getMorphedModel('journal_entry'))->toBe(JournalEntry::class)
        ->and(Relation::getMorphedModel('journal_line'))->toBe(JournalLine::class)
        ->and((new JournalEntry)->getMorphClass())->toBe('journal_entry');
});
