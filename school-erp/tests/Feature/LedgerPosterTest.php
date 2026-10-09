<?php

declare(strict_types=1);

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerPeriod;
use App\Services\Ledger\DefaultChartOfAccounts;
use App\Services\Ledger\LedgerConflict;
use App\Services\Ledger\LedgerPeriodClosed;
use App\Services\Ledger\LedgerPoster;
use App\Services\Ledger\LedgerValidationException;
use App\Services\Ledger\PostingLine;
use App\Services\Ledger\PostingRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

/**
 * @return array{org: string, campus: string, user: string, family: string}
 */
function posterTenant(): array
{
    $org = F::org();
    $campus = F::campus($org);
    $user = F::user($org);
    $family = F::family($org);
    DefaultChartOfAccounts::seedFor($org);

    return compact('org', 'campus', 'user', 'family');
}

it('posts a valid entry (P3 shape) storing shape, hash, user, period, and lines', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;
    $sourceId = F::id();
    $date = Carbon::create(2026, 8, 15);

    $request = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: $date,
        sourceType: 'voucher',
        sourceId: $sourceId,
        createdBy: $t['user'],
        memo: 'August voucher run',
        lines: [
            PostingLine::debit('fee_receivable', 6_900_000, $t['family'], 'Tuition net'),
            PostingLine::debit('fee_discounts', 1_100_000, null, 'Need-based discount'),
            PostingLine::credit('tuition_income', 7_000_000, null, 'Tuition fees'),
            PostingLine::credit('other_fee_income', 1_000_000, null, 'Lab fees'),
        ],
    );

    $entry = $poster->post($request);

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->line_count)->toBe(4)
        ->and($entry->total_minor)->toBe(8_000_000)
        ->and($entry->created_by)->toBe($t['user'])
        ->and($entry->entry_date->toDateString())->toBe('2026-08-15')
        ->and($entry->memo)->toBe('August voucher run')
        ->and($entry->lines_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($entry->lines)->toHaveCount(4);

    $period = LedgerPeriod::query()->findOrFail($entry->period_id);
    expect($period->name)->toBe('2026-08');

    $lines = JournalLine::query()->where('entry_id', $entry->id)->orderBy('line_no')->get();
    expect($lines->pluck('line_no')->all())->toBe([1, 2, 3, 4]);

    $totalDebit = $lines->sum('debit_minor');
    $totalCredit = $lines->sum('credit_minor');
    expect($totalDebit)->toBe(8_000_000)
        ->and($totalCredit)->toBe(8_000_000);
});

it('returns the same entry id when posted twice with only one row stored', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;
    $sourceId = F::id();
    $date = Carbon::create(2026, 8, 15);

    $request = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: $date,
        sourceType: 'payment',
        sourceId: $sourceId,
        createdBy: $t['user'],
        memo: 'Payment 1',
        lines: [
            PostingLine::debit('cash', 50_000),
            PostingLine::credit('fee_receivable', 50_000, $t['family']),
        ],
    );

    $first = $poster->post($request);
    $second = $poster->post($request);

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::query()->where('source_id', $sourceId)->count())->toBe(1)
        ->and(JournalLine::query()->where('entry_id', $first->id)->count())->toBe(2);
});

it('throws LedgerConflict and writes nothing new when same source is posted with different lines', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;
    $sourceId = F::id();
    $date = Carbon::create(2026, 8, 15);

    $req1 = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: $date,
        sourceType: 'payment',
        sourceId: $sourceId,
        createdBy: $t['user'],
        memo: 'Payment 1',
        lines: [
            PostingLine::debit('cash', 50_000),
            PostingLine::credit('fee_receivable', 50_000, $t['family']),
        ],
    );
    $poster->post($req1);

    $entryCountBefore = JournalEntry::query()->count();
    $lineCountBefore = JournalLine::query()->count();

    $req2 = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: $date,
        sourceType: 'payment',
        sourceId: $sourceId,
        createdBy: $t['user'],
        memo: 'Payment 1 tampered lines',
        lines: [
            PostingLine::debit('cash', 60_000),
            PostingLine::credit('fee_receivable', 60_000, $t['family']),
        ],
    );

    expect(fn () => $poster->post($req2))->toThrow(LedgerConflict::class);

    expect(JournalEntry::query()->count())->toBe($entryCountBefore)
        ->and(JournalLine::query()->count())->toBe($lineCountBefore);
});

it('ignores line order, memo, and date for lines_hash and returns old entry on replay', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;
    $sourceId = F::id();

    $lineA = PostingLine::debit('cash', 50_000);
    $lineB = PostingLine::credit('fee_receivable', 50_000, $t['family']);

    $req1 = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: Carbon::create(2026, 8, 10),
        sourceType: 'payment',
        sourceId: $sourceId,
        createdBy: $t['user'],
        memo: 'First memo',
        lines: [$lineA, $lineB],
    );
    $entry1 = $poster->post($req1);

    $req2 = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: Carbon::create(2026, 8, 25),
        sourceType: 'payment',
        sourceId: $sourceId,
        createdBy: $t['user'],
        memo: 'Different memo',
        lines: [$lineB, $lineA],
    );
    $entry2 = $poster->post($req2);

    expect($entry2->id)->toBe($entry1->id)
        ->and($entry2->lines_hash)->toBe($entry1->lines_hash);
});

it('returns old entry on retry even when its period is now closed', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;
    $sourceId = F::id();
    $date = Carbon::create(2026, 8, 15);

    $req = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: $date,
        sourceType: 'payment',
        sourceId: $sourceId,
        createdBy: $t['user'],
        memo: 'Payment',
        lines: [
            PostingLine::debit('cash', 50_000),
            PostingLine::credit('fee_receivable', 50_000, $t['family']),
        ],
    );

    $original = $poster->post($req);

    DB::table('ledger_periods')->where('id', $original->period_id)->update([
        'status' => 'closed',
        'closed_at' => now(),
        'closed_by' => $t['user'],
    ]);

    $replayed = $poster->post($req);
    expect($replayed->id)->toBe($original->id);
});

it('throws LedgerPeriodClosed and writes nothing when first post targets a closed period', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;

    F::period($t['org'], [
        'name' => '2026-05',
        'starts_on' => '2026-05-01',
        'ends_on' => '2026-05-31',
        'status' => 'closed',
        'closed_at' => now(),
        'closed_by' => $t['user'],
    ]);

    $entriesBefore = JournalEntry::query()->count();
    $linesBefore = JournalLine::query()->count();

    $req = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: Carbon::create(2026, 5, 10),
        sourceType: 'payment',
        sourceId: F::id(),
        createdBy: $t['user'],
        memo: 'Old closed payment',
        lines: [
            PostingLine::debit('cash', 10_000),
            PostingLine::credit('fee_receivable', 10_000, $t['family']),
        ],
    );

    expect(fn () => $poster->post($req))->toThrow(LedgerPeriodClosed::class);

    expect(JournalEntry::query()->count())->toBe($entriesBefore)
        ->and(JournalLine::query()->count())->toBe($linesBefore);
});

it('auto-creates month period when missing', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;
    $date = Carbon::create(2026, 11, 20);

    expect(LedgerPeriod::query()->where('organization_id', $t['org'])->where('name', '2026-11')->exists())->toBeFalse();

    $req = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: $date,
        sourceType: 'voucher',
        sourceId: F::id(),
        createdBy: $t['user'],
        memo: 'Auto-create period test',
        lines: [
            PostingLine::debit('cash', 10_000),
            PostingLine::credit('other_fee_income', 10_000),
        ],
    );

    $entry = $poster->post($req);
    expect($entry->period->name)->toBe('2026-11');
});

it('rejects invalid posting payloads and writes nothing', function (string $reason, Closure $makeRequest) {
    $t = posterTenant();
    $poster = new LedgerPoster;

    $entriesBefore = JournalEntry::query()->count();
    $linesBefore = JournalLine::query()->count();

    /** @var PostingRequest $request */
    $request = $makeRequest($t);

    try {
        $poster->post($request);
        test()->fail("Expected LedgerValidationException with reason {$reason} but none was thrown.");
    } catch (LedgerValidationException $e) {
        expect($e->reason)->toBe($reason)
            ->and($e->getMessage())->toBe("Ledger posting is not valid: {$reason}");
    }

    expect(JournalEntry::query()->count())->toBe($entriesBefore)
        ->and(JournalLine::query()->count())->toBe($linesBefore);
})->with([
    'unbalanced' => [
        'unbalanced',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
            PostingLine::debit('cash', 10_000),
            PostingLine::credit('fee_receivable', 9_000, $t['family']),
        ]),
    ],
    'both sides' => [
        'both_sides',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
            new PostingLine('cash', 10_000, 5_000),
            PostingLine::credit('fee_receivable', 5_000, $t['family']),
        ]),
    ],
    'zero amount' => [
        'invalid_amount',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
            PostingLine::debit('cash', 0),
            PostingLine::credit('fee_receivable', 0, $t['family']),
        ]),
    ],
    'one line only' => [
        'not_enough_lines',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
            PostingLine::debit('cash', 10_000),
        ]),
    ],
    'float amount' => [
        'invalid_amount',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
            PostingLine::debit('cash', 1.5),
            PostingLine::credit('fee_receivable', 1.5, $t['family']),
        ]),
    ],
    'string amount' => [
        'invalid_amount',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
            PostingLine::debit('cash', '100'),
            PostingLine::credit('fee_receivable', '100', $t['family']),
        ]),
    ],
    'inactive account' => [
        'inactive_account',
        function (array $t) {
            $inactive = F::account($t['org'], ['code' => '9999', 'name' => 'Inactive', 'is_active' => false]);

            return new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
                PostingLine::debit($inactive, 10_000),
                PostingLine::credit('fee_receivable', 10_000, $t['family']),
            ]);
        },
    ],
    'account of another org' => [
        'unknown_account',
        function (array $t) {
            $otherOrg = F::org();
            $otherAcc = F::account($otherOrg, ['code' => '1000', 'name' => 'Other Cash']);

            return new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
                PostingLine::debit($otherAcc, 10_000),
                PostingLine::credit('fee_receivable', 10_000, $t['family']),
            ]);
        },
    ],
    'campus of another org' => [
        'unknown_campus',
        function (array $t) {
            $otherOrg = F::org();
            $otherCampus = F::campus($otherOrg);

            return new PostingRequest($t['org'], $otherCampus, Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
                PostingLine::debit('cash', 10_000),
                PostingLine::credit('fee_receivable', 10_000, $t['family']),
            ]);
        },
    ],
    'receivable line without family' => [
        'family_required',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
            PostingLine::debit('fee_receivable', 10_000, null),
            PostingLine::credit('tuition_income', 10_000),
        ]),
    ],
    'family of another org' => [
        'unknown_family',
        function (array $t) {
            $otherOrg = F::org();
            $otherFamily = F::family($otherOrg);

            return new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
                PostingLine::debit('fee_receivable', 10_000, $otherFamily),
                PostingLine::credit('tuition_income', 10_000),
            ]);
        },
    ],
    'invalid source id' => [
        'invalid_source',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', 'not-a-ulid', $t['user'], null, [
            PostingLine::debit('cash', 10_000),
            PostingLine::credit('tuition_income', 10_000),
        ]),
    ],
    'memo longer than 255' => [
        'invalid_memo',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], str_repeat('a', 256), [
            PostingLine::debit('cash', 10_000),
            PostingLine::credit('tuition_income', 10_000),
        ]),
    ],
    'sum that overflows' => [
        'amount_overflow',
        fn (array $t) => new PostingRequest($t['org'], $t['campus'], Carbon::create(2026, 8, 1), 'payment', F::id(), $t['user'], null, [
            PostingLine::debit('cash', PHP_INT_MAX),
            PostingLine::debit('cash', PHP_INT_MAX),
            PostingLine::credit('tuition_income', 10_000),
        ]),
    ],
]);

it('allows subsequent valid post after a failed post inside same test transaction', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;

    try {
        DB::transaction(function () use ($t, $poster) {
            // Unbalanced post throws LedgerValidationException
            $poster->post(new PostingRequest(
                $t['org'],
                $t['campus'],
                Carbon::create(2026, 8, 1),
                'payment',
                F::id(),
                $t['user'],
                null,
                [PostingLine::debit('cash', 10_000), PostingLine::credit('fee_receivable', 5_000, $t['family'])],
            ));
        });
    } catch (LedgerValidationException) {
        // expected
    }

    $valid = $poster->post(new PostingRequest(
        $t['org'],
        $t['campus'],
        Carbon::create(2026, 8, 1),
        'payment',
        F::id(),
        $t['user'],
        null,
        [PostingLine::debit('cash', 10_000), PostingLine::credit('fee_receivable', 10_000, $t['family'])],
    ));

    expect($valid)->toBeInstanceOf(JournalEntry::class)
        ->and($valid->total_minor)->toBe(10_000);
});

it('posts P4 shape fine', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;
    $date = Carbon::create(2026, 8, 20);

    $request = new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: $date,
        sourceType: 'payment',
        sourceId: F::id(),
        createdBy: $t['user'],
        memo: 'Payment after due date',
        lines: [
            PostingLine::debit('cash', 7_000_000, null, 'Cash collected'),
            PostingLine::credit('fee_receivable', 6_900_000, $t['family'], 'Voucher settled'),
            PostingLine::credit('late_fee_income', 100_000, null, 'Late fee collected'),
        ],
    );

    $entry = $poster->post($request);

    expect($entry->total_minor)->toBe(7_000_000)
        ->and($entry->line_count)->toBe(3);
});

it('rejects createdBy from another organization as unknown_user and writes nothing', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;
    $foreignUser = F::user(F::org());

    $entriesBefore = JournalEntry::query()->count();
    $linesBefore = JournalLine::query()->count();

    try {
        $poster->post(new PostingRequest(
            organizationId: $t['org'],
            campusId: $t['campus'],
            entryDate: Carbon::create(2026, 8, 1),
            sourceType: 'payment',
            sourceId: F::id(),
            createdBy: $foreignUser,
            memo: null,
            lines: [
                PostingLine::debit('cash', 10_000),
                PostingLine::credit('tuition_income', 10_000),
            ],
        ));
        test()->fail('Expected LedgerValidationException with reason unknown_user but none was thrown.');
    } catch (LedgerValidationException $e) {
        expect($e->reason)->toBe('unknown_user');
    }

    expect(JournalEntry::query()->count())->toBe($entriesBefore)
        ->and(JournalLine::query()->count())->toBe($linesBefore);
});

it('accepts a super admin with null organization_id as createdBy', function () {
    $t = posterTenant();
    $poster = new LedgerPoster;

    $superAdminId = F::id();
    DB::table('users')->insert([
        'id' => $superAdminId,
        'organization_id' => null,
        'name' => 'Super Administrator',
        'email' => strtolower($superAdminId).'@example.test',
        'is_super_admin' => true,
    ]);

    $entry = $poster->post(new PostingRequest(
        organizationId: $t['org'],
        campusId: $t['campus'],
        entryDate: Carbon::create(2026, 8, 1),
        sourceType: 'payment',
        sourceId: F::id(),
        createdBy: $superAdminId,
        memo: null,
        lines: [
            PostingLine::debit('cash', 10_000),
            PostingLine::credit('tuition_income', 10_000),
        ],
    ));

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->created_by)->toBe($superAdminId);
});
