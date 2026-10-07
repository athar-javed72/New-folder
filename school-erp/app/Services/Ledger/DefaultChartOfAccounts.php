<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Default chart of accounts (D-48). Idempotent: upsert by (organization_id, system_key).
 * Not wired to organization creation or to any seeder yet (known gap).
 */
final class DefaultChartOfAccounts
{
    /** @var list<array{code: string, name: string, type: string, system_key: string, requires_family: bool}> */
    public const ACCOUNTS = [
        ['code' => '1000', 'name' => 'Cash in Hand', 'type' => 'asset', 'system_key' => 'cash', 'requires_family' => false],
        ['code' => '1010', 'name' => 'Bank', 'type' => 'asset', 'system_key' => 'bank', 'requires_family' => false],
        ['code' => '1020', 'name' => 'Gateway Clearing', 'type' => 'asset', 'system_key' => 'gateway_clearing', 'requires_family' => false],
        ['code' => '1100', 'name' => 'Fee Receivable', 'type' => 'asset', 'system_key' => 'fee_receivable', 'requires_family' => true],
        ['code' => '2000', 'name' => 'Family Advance Credit', 'type' => 'liability', 'system_key' => 'family_credit', 'requires_family' => true],
        ['code' => '2100', 'name' => 'Refunds Payable', 'type' => 'liability', 'system_key' => 'refunds_payable', 'requires_family' => true],
        ['code' => '2200', 'name' => 'Security Deposits', 'type' => 'liability', 'system_key' => 'security_deposits', 'requires_family' => true],
        ['code' => '2300', 'name' => 'Pass-through Payable', 'type' => 'liability', 'system_key' => 'pass_through', 'requires_family' => false],
        ['code' => '3000', 'name' => 'Opening Balance Equity', 'type' => 'equity', 'system_key' => 'opening_equity', 'requires_family' => false],
        ['code' => '4000', 'name' => 'Tuition Fee Income', 'type' => 'income', 'system_key' => 'tuition_income', 'requires_family' => false],
        ['code' => '4100', 'name' => 'Other Fee Income', 'type' => 'income', 'system_key' => 'other_fee_income', 'requires_family' => false],
        ['code' => '4900', 'name' => 'Late Fee Income', 'type' => 'income', 'system_key' => 'late_fee_income', 'requires_family' => false],
        ['code' => '5000', 'name' => 'Fee Discounts', 'type' => 'expense', 'system_key' => 'fee_discounts', 'requires_family' => false],
        ['code' => '5100', 'name' => 'Fee Waivers', 'type' => 'expense', 'system_key' => 'fee_waivers', 'requires_family' => false],
        ['code' => '5200', 'name' => 'Scholarships', 'type' => 'expense', 'system_key' => 'scholarships', 'requires_family' => false],
    ];

    /**
     * One statement. The conflict target repeats the partial-index predicate so PostgreSQL can match
     * accounts_system_key_uq. On conflict only name and code are refreshed (and only when they differ);
     * type, requires_family, is_active and id of an existing account are never changed.
     */
    public static function seedFor(string $organizationId): void
    {
        $rows = [];
        $bindings = [];
        foreach (self::ACCOUNTS as $a) {
            $rows[] = '(?, ?, ?, ?, ?, ?, CAST(? AS boolean), true, now(), now())';
            array_push(
                $bindings,
                (string) Str::ulid(),
                $organizationId,
                $a['code'],
                $a['name'],
                $a['type'],
                $a['system_key'],
                $a['requires_family'] ? 'true' : 'false',
            );
        }

        DB::statement(
            'INSERT INTO accounts (id, organization_id, code, name, type, system_key, requires_family, is_active, created_at, updated_at) VALUES '
            .implode(', ', $rows)
            .' ON CONFLICT (organization_id, system_key) WHERE system_key IS NOT NULL DO UPDATE'
            .' SET name = EXCLUDED.name, code = EXCLUDED.code, updated_at = now()'
            .' WHERE accounts.name IS DISTINCT FROM EXCLUDED.name OR accounts.code IS DISTINCT FROM EXCLUDED.code',
            $bindings,
        );
    }
}
