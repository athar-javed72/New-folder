<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\ScopeContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ledger reporting service (D-51).
 *
 * All aggregations happen in the database; no line-looping in PHP.
 */
final class LedgerReports
{
    /**
     * Aggregates trial balance across all accounts for an organization within a date range and optional campus filter.
     *
     * @return list<array{
     *     account_id: string,
     *     code: string,
     *     name: string,
     *     type: string,
     *     system_key: string|null,
     *     debit_minor: int,
     *     credit_minor: int,
     *     balance_minor: int
     * }>
     *
     * @throws LedgerAccessDenied
     * @throws LedgerValidationException
     */
    public function trialBalance(
        User $actor,
        string $organizationId,
        CarbonInterface $from,
        CarbonInterface $to,
        ScopeContext $scope,
        ?string $campusId = null,
    ): array {
        // -----------------------------------------------------------------
        // Access checks (Item 2)
        // -----------------------------------------------------------------
        // (a) Organization check: actor's organization must match, unless super admin
        LedgerTenancy::assertActorInOrganization($actor, $organizationId);

        // (b) Permission check: actor must hold ledger.entry.view in the given scope
        if (! AccessResolver::can($actor, 'ledger.entry.view', $scope)) {
            throw new LedgerAccessDenied;
        }

        // (c) Scope coverage:
        // If scope specifies an organization, it must match
        if ($scope->organizationId !== null && $scope->organizationId !== $organizationId) {
            throw new LedgerAccessDenied;
        }

        $isOrgWide = $scope->campusId === null
            && $scope->programId === null
            && $scope->gradeId === null
            && $scope->sectionId === null
            && $scope->sessionId === null;

        if ($campusId === null) {
            // trialBalance with $campusId null: the scope must be organization-wide
            if (! $isOrgWide) {
                throw new LedgerAccessDenied;
            }
        } else {
            // trialBalance with $campusId set: the scope must be organization-wide or be that exact campus
            $isExactCampus = $scope->campusId === $campusId
                && $scope->programId === null
                && $scope->gradeId === null
                && $scope->sectionId === null
                && $scope->sessionId === null;

            if (! $isOrgWide && ! $isExactCampus) {
                throw new LedgerAccessDenied;
            }
        }

        // -----------------------------------------------------------------
        // Domain validation
        // -----------------------------------------------------------------
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        if ($fromDate > $toDate) {
            throw new LedgerValidationException('invalid_range');
        }

        if ($campusId !== null) {
            $campusExists = DB::table('campuses')
                ->where('organization_id', $organizationId)
                ->where('id', $campusId)
                ->exists();

            if (! $campusExists) {
                throw new LedgerValidationException('unknown_campus');
            }
        }

        // -----------------------------------------------------------------
        // Single SQL query (Item 3)
        // Accounts LEFT JOIN journal_lines LEFT JOIN journal_entries with entry filters in JOIN
        // -----------------------------------------------------------------
        $campusCondition = $campusId !== null ? 'AND je.campus_id = ?' : '';

        $sql = "
            SELECT
                a.id AS account_id,
                a.code,
                a.name,
                a.type,
                a.system_key,
                COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.debit_minor ELSE 0 END), 0)::bigint AS debit_minor,
                COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.credit_minor ELSE 0 END), 0)::bigint AS credit_minor
            FROM accounts a
            LEFT JOIN journal_lines jl
                ON jl.organization_id = a.organization_id
                AND jl.account_id = a.id
            LEFT JOIN journal_entries je
                ON je.organization_id = jl.organization_id
                AND je.id = jl.entry_id
                AND je.entry_date >= ?
                AND je.entry_date <= ?
                {$campusCondition}
            WHERE a.organization_id = ?
            GROUP BY a.id, a.code, a.name, a.type, a.system_key
            ORDER BY a.code ASC
        ";

        $bindings = [$fromDate, $toDate];
        if ($campusId !== null) {
            $bindings[] = $campusId;
        }
        $bindings[] = $organizationId;

        $rows = DB::select($sql, $bindings);

        $results = [];
        foreach ($rows as $row) {
            $debit = (int) $row->debit_minor;
            $credit = (int) $row->credit_minor;
            $isDebitNormal = in_array($row->type, ['asset', 'expense'], true);
            $balance = $isDebitNormal ? ($debit - $credit) : ($credit - $debit);

            $results[] = [
                'account_id' => (string) $row->account_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'system_key' => $row->system_key !== null ? (string) $row->system_key : null,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'balance_minor' => $balance,
            ];
        }

        return $results;
    }

    /**
     * Calculates the net sub-ledger balance for a specific family and account.
     *
     * @throws LedgerAccessDenied
     * @throws LedgerValidationException
     */
    public function familyBalance(
        User $actor,
        string $organizationId,
        string $familyId,
        string $systemKey,
        ScopeContext $scope,
    ): int {
        // -----------------------------------------------------------------
        // Access checks (Item 2)
        // -----------------------------------------------------------------
        // (a) Organization check
        LedgerTenancy::assertActorInOrganization($actor, $organizationId);

        // (b) Permission check
        if (! AccessResolver::can($actor, 'ledger.entry.view', $scope)) {
            throw new LedgerAccessDenied;
        }

        // (c) Scope coverage: familyBalance must be organization-wide
        if ($scope->organizationId !== null && $scope->organizationId !== $organizationId) {
            throw new LedgerAccessDenied;
        }

        $isOrgWide = $scope->campusId === null
            && $scope->programId === null
            && $scope->gradeId === null
            && $scope->sectionId === null
            && $scope->sessionId === null;

        if (! $isOrgWide) {
            throw new LedgerAccessDenied;
        }

        // -----------------------------------------------------------------
        // Domain validation
        // -----------------------------------------------------------------
        $familyExists = DB::table('families')
            ->where('organization_id', $organizationId)
            ->where('id', $familyId)
            ->exists();

        if (! $familyExists) {
            throw new LedgerValidationException('unknown_family');
        }

        /** @var object{id: string, type: string}|null $account */
        $account = DB::table('accounts')
            ->where('organization_id', $organizationId)
            ->where('system_key', $systemKey)
            ->select(['id', 'type'])
            ->first();

        if ($account === null) {
            throw new LedgerValidationException('unknown_account');
        }

        // -----------------------------------------------------------------
        // One SQL query: sum debit and credit of that account's lines for that family
        // joined to journal_entries for the organization filter
        // -----------------------------------------------------------------
        $row = DB::selectOne(
            'SELECT
                COALESCE(SUM(jl.debit_minor), 0)::bigint AS debit_minor,
                COALESCE(SUM(jl.credit_minor), 0)::bigint AS credit_minor
             FROM journal_lines jl
             JOIN journal_entries je
                ON je.id = jl.entry_id
                AND je.organization_id = jl.organization_id
             WHERE jl.organization_id = ?
                AND jl.account_id = ?
                AND jl.family_id = ?',
            [$organizationId, $account->id, $familyId],
        );

        $debit = (int) ($row->debit_minor ?? 0);
        $credit = (int) ($row->credit_minor ?? 0);
        $isDebitNormal = in_array($account->type, ['asset', 'expense'], true);

        return $isDebitNormal ? ($debit - $credit) : ($credit - $debit);
    }
}
