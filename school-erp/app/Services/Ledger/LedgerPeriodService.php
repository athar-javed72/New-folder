<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\AuditLog;
use App\Models\LedgerPeriod;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\ScopeContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Ledger periods (D-45): automatic calendar-month periods and a final, audited close. */
final class LedgerPeriodService
{
    /**
     * Returns the period (open or closed) covering $date. When none exists, creates the calendar month
     * 'YYYY-MM' with ON CONFLICT DO NOTHING (no conflict target, so the GiST exclusion constraint is
     * covered too) and selects again. Uses only the given date, never the server's today.
     */
    public function forDate(string $organizationId, CarbonInterface $date): LedgerPeriod
    {
        $day = $date->toDateString();

        $period = $this->covering($organizationId, $day);
        if ($period !== null) {
            return $period;
        }

        $month = $date->toImmutable();
        DB::statement(
            "INSERT INTO ledger_periods (id, organization_id, name, starts_on, ends_on, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'open', now(), now())
             ON CONFLICT DO NOTHING",
            [
                (string) Str::ulid(),
                $organizationId,
                $month->format('Y-m'),
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ],
        );

        $period = $this->covering($organizationId, $day);
        if ($period === null) {
            // A custom period partly overlaps this month, so the month could not be created.
            throw new RuntimeException('No ledger period for date.');
        }

        return $period;
    }

    /**
     * Closes a period for good. Organization check first (super admin excepted), then
     * ledger.period.close, both before any write. Closing a closed period is a no-op.
     *
     * @throws LedgerAccessDenied
     */
    public function close(User $actor, LedgerPeriod $period, ScopeContext $scope): LedgerPeriod
    {
        $organizationId = (string) $period->organization_id;

        if (! $actor->is_super_admin && ($actor->organization_id === null || (string) $actor->organization_id !== $organizationId)) {
            throw new LedgerAccessDenied;
        }

        if (! AccessResolver::can($actor, 'ledger.period.close', $scope)) {
            throw new LedgerAccessDenied;
        }

        return DB::transaction(function () use ($actor, $period, $organizationId): LedgerPeriod {
            /** @var LedgerPeriod $locked */
            $locked = LedgerPeriod::query()
                ->where('organization_id', $organizationId)
                ->where('id', $period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'closed') {
                return $locked;
            }

            $locked->status = 'closed';
            $locked->closed_at = now();
            $locked->closed_by = $actor->id;
            $locked->save();

            AuditLog::query()->create([
                'organization_id' => $organizationId,
                'actor_id' => $actor->id,
                'action' => 'ledger.period.closed',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => (string) $locked->getKey(),
                'meta' => [
                    'period' => $locked->name,
                ],
            ]);

            return $locked;
        });
    }

    private function covering(string $organizationId, string $day): ?LedgerPeriod
    {
        return LedgerPeriod::query()
            ->where('organization_id', $organizationId)
            ->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day)
            ->first();
    }
}
