<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Gap-free document numbers per (organization, campus, key, fiscal year) (D-49). */
final class NumberSequenceService
{
    /**
     * Takes the next number with ONE statement (insert-or-increment, RETURNING last_number).
     *
     * Must be called inside a database transaction (transactionLevel >= 1); otherwise throws
     * LogicException so a failed document cannot leave a gap.
     *
     * Call this LAST, right before commit, so the row lock is held for the shortest time.
     * Never call it outside a transaction that also posts the document that uses the number:
     * a rollback gives the number back, which is what keeps the sequence gap-free.
     */
    public function next(string $organizationId, string $campusId, string $key, CarbonInterface $date): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Number sequences must be taken inside a transaction.');
        }

        if (preg_match('/^[a-z_]{1,40}$/', $key) !== 1) {
            throw new InvalidArgumentException('Invalid sequence key.');
        }

        $campusOk = DB::table('campuses')
            ->where('id', $campusId)
            ->where('organization_id', $organizationId)
            ->exists();
        if (! $campusOk) {
            throw new InvalidArgumentException('Invalid campus.');
        }

        $fiscalYear = FiscalYear::startYearFor($date);

        $row = DB::selectOne(
            'INSERT INTO number_sequences (id, organization_id, campus_id, "key", fiscal_year, last_number, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, now(), now())
             ON CONFLICT (organization_id, campus_id, "key", fiscal_year)
             DO UPDATE SET last_number = number_sequences.last_number + 1, updated_at = now()
             RETURNING last_number',
            [(string) Str::ulid(), $organizationId, $campusId, $key, $fiscalYear],
        );

        return (int) $row->last_number;
    }
}
