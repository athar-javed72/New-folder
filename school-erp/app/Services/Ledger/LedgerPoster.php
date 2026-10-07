<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Internal. Callers (domain services) check their own permission and SoD first. Never call from a controller.
 *
 * Appends balanced journal entries with idempotent deduplication and canonical line hashing (D-46, D-47, D-50).
 */
final class LedgerPoster
{
    /**
     * Posts a journal entry inside a database transaction (joins the caller's transaction).
     *
     * @throws LedgerValidationException
     * @throws LedgerConflict
     * @throws LedgerPeriodClosed
     */
    public function post(PostingRequest $request): JournalEntry
    {
        return DB::transaction(function () use ($request): JournalEntry {
            // -------------------------------------------------------------
            // Step a: Validate in PHP before any write
            // -------------------------------------------------------------
            if (preg_match('/^[a-z_]{1,60}$/', $request->sourceType) !== 1 || ! Str::isUlid($request->sourceId)) {
                throw new LedgerValidationException('invalid_source');
            }

            if ($request->memo !== null && mb_strlen($request->memo) > 255) {
                throw new LedgerValidationException('invalid_memo');
            }

            if (count($request->lines) < 2) {
                throw new LedgerValidationException('not_enough_lines');
            }

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($request->lines as $line) {
                if (! is_int($line->debitMinor) || ! is_int($line->creditMinor)) {
                    throw new LedgerValidationException('invalid_amount');
                }
                if ($line->debitMinor < 0 || $line->creditMinor < 0) {
                    throw new LedgerValidationException('invalid_amount');
                }
                if ($line->debitMinor > 0 && $line->creditMinor > 0) {
                    throw new LedgerValidationException('both_sides');
                }
                if ($line->debitMinor === 0 && $line->creditMinor === 0) {
                    throw new LedgerValidationException('invalid_amount');
                }

                if ($line->debitMinor > PHP_INT_MAX - $totalDebit) {
                    throw new LedgerValidationException('amount_overflow');
                }
                $totalDebit += $line->debitMinor;

                if ($line->creditMinor > PHP_INT_MAX - $totalCredit) {
                    throw new LedgerValidationException('amount_overflow');
                }
                $totalCredit += $line->creditMinor;
            }

            if ($totalDebit !== $totalCredit) {
                throw new LedgerValidationException('unbalanced');
            }

            $campusOk = DB::table('campuses')
                ->where('id', $request->campusId)
                ->where('organization_id', $request->organizationId)
                ->exists();
            if (! $campusOk) {
                throw new LedgerValidationException('unknown_campus');
            }

            $userOk = DB::table('users')
                ->where('id', $request->createdBy)
                ->exists();
            if (! $userOk) {
                throw new LedgerValidationException('unknown_user');
            }

            $accountRefs = array_values(array_unique(array_map(fn (PostingLine $l) => $l->account, $request->lines)));
            $ids = array_values(array_filter($accountRefs, fn (string $ref) => Str::isUlid($ref)));
            $keys = array_values(array_filter($accountRefs, fn (string $ref) => ! Str::isUlid($ref)));

            $accountsQuery = DB::table('accounts')->where('organization_id', $request->organizationId);
            $accountsQuery->where(function ($q) use ($ids, $keys) {
                if (! empty($ids)) {
                    $q->whereIn('id', $ids);
                }
                if (! empty($keys)) {
                    $q->orWhereIn('system_key', $keys);
                }
            });
            $accounts = $accountsQuery->get();

            /** @var array<string, object{id: string, organization_id: string, system_key: ?string, requires_family: bool, is_active: bool}> $accountMap */
            $accountMap = [];
            foreach ($accounts as $acc) {
                $accountMap[$acc->id] = $acc;
                if ($acc->system_key !== null) {
                    $accountMap[$acc->system_key] = $acc;
                }
            }

            foreach ($request->lines as $line) {
                if (! isset($accountMap[$line->account])) {
                    throw new LedgerValidationException('unknown_account');
                }
                $acc = $accountMap[$line->account];
                if (! $acc->is_active) {
                    throw new LedgerValidationException('inactive_account');
                }
                if ($acc->requires_family && ($line->familyId === null || $line->familyId === '')) {
                    throw new LedgerValidationException('family_required');
                }
            }

            $familyIds = array_values(array_unique(array_filter(
                array_map(fn (PostingLine $l) => $l->familyId, $request->lines),
                fn (?string $fid) => $fid !== null && $fid !== '',
            )));

            if (! empty($familyIds)) {
                $foundFamiliesCount = DB::table('families')
                    ->where('organization_id', $request->organizationId)
                    ->whereIn('id', $familyIds)
                    ->count();

                if ($foundFamiliesCount !== count($familyIds)) {
                    throw new LedgerValidationException('unknown_family');
                }
            }

            // -------------------------------------------------------------
            // Step b: Compute lines_hash
            // -------------------------------------------------------------
            $canonicalLines = [];
            foreach ($request->lines as $line) {
                $acc = $accountMap[$line->account];
                $fam = $line->familyId ?? '';
                $canonicalLines[] = "{$acc->id}|{$fam}|{$line->debitMinor}|{$line->creditMinor}";
            }
            $linesHash = self::hashLines($request->campusId, $canonicalLines);

            // -------------------------------------------------------------
            // Step c: Look up existing entry FIRST
            // -------------------------------------------------------------
            /** @var JournalEntry|null $existing */
            $existing = JournalEntry::query()
                ->where('organization_id', $request->organizationId)
                ->where('source_type', $request->sourceType)
                ->where('source_id', $request->sourceId)
                ->where('kind', 'posting')
                ->first();

            if ($existing !== null) {
                if ($existing->lines_hash === $linesHash) {
                    return $existing;
                }

                throw new LedgerConflict;
            }

            // -------------------------------------------------------------
            // Step d: Resolve the period
            // -------------------------------------------------------------
            /** @var LedgerPeriodService $periodService */
            $periodService = app(LedgerPeriodService::class);
            $period = $periodService->forDate($request->organizationId, $request->entryDate);
            if ($period->status === 'closed') {
                throw new LedgerPeriodClosed;
            }

            // -------------------------------------------------------------
            // Step e: INSERT the entry with ON CONFLICT DO NOTHING RETURNING id
            // -------------------------------------------------------------
            $entryId = (string) Str::ulid();
            $inserted = DB::selectOne(
                'INSERT INTO journal_entries (id, organization_id, campus_id, period_id, entry_date, source_type, source_id, kind, reversal_of, line_count, total_minor, lines_hash, memo, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now())
                 ON CONFLICT (organization_id, source_type, source_id, kind) DO NOTHING
                 RETURNING id',
                [
                    $entryId,
                    $request->organizationId,
                    $request->campusId,
                    $period->id,
                    $request->entryDate->toDateString(),
                    $request->sourceType,
                    $request->sourceId,
                    'posting',
                    null,
                    count($request->lines),
                    $totalDebit,
                    $linesHash,
                    $request->memo,
                    $request->createdBy,
                ],
            );

            if ($inserted === null) {
                /** @var JournalEntry $existingConcurrent */
                $existingConcurrent = JournalEntry::query()
                    ->where('organization_id', $request->organizationId)
                    ->where('source_type', $request->sourceType)
                    ->where('source_id', $request->sourceId)
                    ->where('kind', 'posting')
                    ->firstOrFail();

                if ($existingConcurrent->lines_hash === $linesHash) {
                    return $existingConcurrent;
                }

                throw new LedgerConflict;
            }

            // -------------------------------------------------------------
            // Step f: Insert all lines in one statement
            // -------------------------------------------------------------
            $lineRows = [];
            $lineNo = 1;
            foreach ($request->lines as $line) {
                $acc = $accountMap[$line->account];
                $lineRows[] = [
                    'id' => (string) Str::ulid(),
                    'organization_id' => $request->organizationId,
                    'entry_id' => $entryId,
                    'line_no' => $lineNo++,
                    'account_id' => $acc->id,
                    'family_id' => $line->familyId ?: null,
                    'debit_minor' => $line->debitMinor,
                    'credit_minor' => $line->creditMinor,
                    'description' => $line->description,
                    'created_at' => now(),
                ];
            }
            DB::table('journal_lines')->insert($lineRows);

            // -------------------------------------------------------------
            // Step g: Force the deferred check
            // -------------------------------------------------------------
            DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced IMMEDIATE');
            DB::statement('SET CONSTRAINTS journal_entries_balanced, journal_lines_balanced DEFERRED');

            // -------------------------------------------------------------
            // Step h: Return the reloaded JournalEntry model
            // -------------------------------------------------------------
            /** @var JournalEntry */
            return JournalEntry::query()
                ->where('organization_id', $request->organizationId)
                ->whereKey($entryId)
                ->firstOrFail();
        });
    }

    /**
     * Canonical hash of lines plus campus id (D-46, D-50).
     *
     * @param  list<string>  $canonicalLines  Each line as "account_id|family_id_or_empty|debit|credit"
     */
    public static function hashLines(string $campusId, array $canonicalLines): string
    {
        sort($canonicalLines, SORT_STRING);
        $payload = $campusId."\n".implode("\n", $canonicalLines);

        return hash('sha256', $payload);
    }
}
