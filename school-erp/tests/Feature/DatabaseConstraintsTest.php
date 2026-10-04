<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

/** Every rule below is enforced by PostgreSQL itself, not by application code. */
function tenant(): array
{
    $org = F::org();
    $campus = F::campus($org);

    return [$org, $campus, F::academics($org, $campus)];
}

// ---------- enrollments ----------

it('allows only one active enrollment per student', function () {
    [$org, $campus, $ac] = tenant();
    $student = F::student($org);
    F::enrollment($org, $campus, $student, $ac);

    $e = F::exception(fn () => F::enrollment($org, $campus, $student, $ac));
    expect($e)->not->toBeNull();
    expect($e->getMessage())->toContain('enrollments_one_active_per_student_uq');
});

it('allows a closed enrollment next to an active one', function () {
    [$org, $campus, $ac] = tenant();
    $student = F::student($org);
    F::enrollment($org, $campus, $student, $ac, ['status' => 'promoted', 'end_date' => '2026-03-31', 'start_date' => '2025-04-01']);
    F::enrollment($org, $campus, $student, $ac);

    expect(DB::table('enrollments')->where('student_id', $student)->count())->toBe(2);
});

it('requires end_date exactly when the enrollment is not active', function () {
    [$org, $campus, $ac] = tenant();
    $student = F::student($org);

    $a = F::exception(fn () => F::enrollment($org, $campus, $student, $ac, ['status' => 'active', 'end_date' => '2026-12-31']));
    expect($a->getMessage())->toContain('enrollments_active_end_chk');

    $b = F::exception(fn () => F::enrollment($org, $campus, $student, $ac, ['status' => 'withdrawn', 'end_date' => null]));
    expect($b->getMessage())->toContain('enrollments_active_end_chk');
});

it('blocks cross-tenant references through the composite foreign key', function () {
    [$orgA, $campusA, $acA] = tenant();
    [$orgB, $campusB] = tenant();
    $studentB = F::student($orgB);

    // Org B student enrolled into Org A's campus/grade: every id exists, but not inside the same tenant.
    $e = F::exception(fn () => F::enrollment($orgB, $campusA, $studentB, $acA));
    expect($e)->not->toBeNull();
    expect($e->getCode())->toBe('23503');   // foreign_key_violation
});

// ---------- users ----------

it('requires organization_id for normal users and forbids it for super admins', function () {
    $org = F::org();

    $a = F::exception(fn () => F::user($org, ['organization_id' => null]));
    expect($a->getMessage())->toContain('users_super_admin_org_chk');

    $b = F::exception(fn () => F::user($org, ['is_super_admin' => true]));
    expect($b->getMessage())->toContain('users_super_admin_org_chk');

    F::user($org, ['organization_id' => null, 'is_super_admin' => true]);
    expect(DB::table('users')->where('is_super_admin', true)->count())->toBe(1);
});

it('requires an email or a phone number', function () {
    $org = F::org();
    $e = F::exception(fn () => F::user($org, ['email' => null, 'phone' => null]));
    expect($e->getMessage())->toContain('users_login_identity_chk');

    F::user($org, ['email' => null, 'phone' => '+923001234567']);
    expect(DB::table('users')->where('phone', '+923001234567')->count())->toBe(1);
});

it('treats emails as case-insensitive unique', function () {
    $org = F::org();
    F::user($org, ['email' => 'Ayesha@School.pk']);
    $e = F::exception(fn () => F::user($org, ['email' => 'ayesha@school.PK']));
    expect($e->getMessage())->toContain('users_email_uq');
});

// ---------- employment contracts ----------

it('rejects overlapping active contracts for the same employee at the same campus', function () {
    $org = F::org();
    $campus = F::campus($org);
    $staff = F::staff($org);
    F::contract($org, $campus, $staff);

    $e = F::exception(fn () => F::contract($org, $campus, $staff, ['start_date' => '2026-09-01', 'end_date' => '2027-08-31']));
    expect($e->getMessage())->toContain('employment_contracts_no_overlap');
});

it('allows concurrent active contracts at different campuses', function () {
    $org = F::org();
    $c1 = F::campus($org);
    $c2 = F::campus($org);
    $staff = F::staff($org);
    F::contract($org, $c1, $staff);
    F::contract($org, $c2, $staff);

    expect(DB::table('employment_contracts')->where('employee_id', $staff['employee'])->count())->toBe(2);
});

it('allows back-to-back contracts and overlapping drafts', function () {
    $org = F::org();
    $campus = F::campus($org);
    $staff = F::staff($org);
    F::contract($org, $campus, $staff, ['start_date' => '2026-04-01', 'end_date' => '2026-12-31']);
    F::contract($org, $campus, $staff, ['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);   // starts the next day
    F::contract($org, $campus, $staff, ['start_date' => '2026-06-01', 'end_date' => '2026-07-01', 'status' => 'draft']);

    expect(DB::table('employment_contracts')->where('employee_id', $staff['employee'])->count())->toBe(3);
});

// ---------- leave ledger ----------

function ledgerEntry(string $org, string $contract, string $leaveType, string $qty, array $over = []): string
{
    $id = F::id();
    DB::table('leave_ledgers')->insert($over + [
        'id' => $id, 'organization_id' => $org, 'contract_id' => $contract, 'leave_type_id' => $leaveType,
        'entry_type' => (float) $qty > 0 ? 'accrual' : 'used', 'qty' => $qty, 'entry_date' => '2026-10-01',
    ]);

    return $id;
}

it('makes the leave ledger append-only', function () {
    $org = F::org();
    $campus = F::campus($org);
    $staff = F::staff($org);
    $contract = F::contract($org, $campus, $staff);
    $entry = ledgerEntry($org, $contract, $staff['leave_type'], '1.00');

    $u = F::exception(fn () => DB::table('leave_ledgers')->where('id', $entry)->update(['qty' => '5.00']));
    expect($u->getMessage())->toContain('append-only');

    $d = F::exception(fn () => DB::table('leave_ledgers')->where('id', $entry)->delete());
    expect($d->getMessage())->toContain('append-only');
});

it('rejects duplicate idempotency keys and zero quantities', function () {
    $org = F::org();
    $campus = F::campus($org);
    $staff = F::staff($org);
    $contract = F::contract($org, $campus, $staff);
    ledgerEntry($org, $contract, $staff['leave_type'], '1.00', ['idempotency_key' => '2026-10:casual']);

    $dup = F::exception(fn () => ledgerEntry($org, $contract, $staff['leave_type'], '1.00', ['idempotency_key' => '2026-10:casual']));
    expect($dup->getMessage())->toContain('leave_ledgers_contract_id_idempotency_key_unique');

    $zero = F::exception(fn () => ledgerEntry($org, $contract, $staff['leave_type'], '0.00', ['entry_type' => 'adjustment']));
    expect($zero->getMessage())->toContain('leave_ledgers_qty_chk');
});

it('derives the balance as the sum of ledger entries: 1.00 - 0.50 = 0.50', function () {
    $org = F::org();
    $campus = F::campus($org);
    $staff = F::staff($org);
    $contract = F::contract($org, $campus, $staff);
    ledgerEntry($org, $contract, $staff['leave_type'], '1.00');
    ledgerEntry($org, $contract, $staff['leave_type'], '-0.50');

    $row = DB::table('leave_balances')->where('contract_id', $contract)->first();
    expect((float) $row->balance)->toBe(0.5);
});

// ---------- policy overrides ----------

it('rejects overlapping published overrides for the same policy and scope', function () {
    $org = F::org();
    F::override($org, ['effective_from' => '2026-04-01', 'effective_to' => '2026-10-01', 'version' => 1]);

    $e = F::exception(fn () => F::override($org, ['effective_from' => '2026-09-01', 'effective_to' => null, 'version' => 2]));
    expect($e->getMessage())->toContain('policy_overrides_no_overlap');
});

it('allows adjacent published ranges, other scopes and overlapping drafts', function () {
    $org = F::org();
    F::override($org, ['effective_from' => '2026-04-01', 'effective_to' => '2026-10-01', 'version' => 1]);
    F::override($org, ['effective_from' => '2026-10-01', 'effective_to' => null, 'version' => 2]);          // effective_to is exclusive
    F::override($org, ['scopeable_id' => 'CAMPUS2', 'version' => 1]);                                      // different scope
    F::override($org, ['effective_from' => '2026-06-01', 'version' => 3, 'status' => 'draft']);           // drafts never clash

    expect(DB::table('policy_overrides')->where('organization_id', $org)->count())->toBe(4);
});

it('rejects an unknown scope type and a reversed date range', function () {
    $org = F::org();
    $a = F::exception(fn () => F::override($org, ['scopeable_type' => 'galaxy']));
    expect($a->getMessage())->toContain('policy_overrides_scope_chk');

    $b = F::exception(fn () => F::override($org, ['effective_from' => '2026-10-01', 'effective_to' => '2026-04-01']));
    expect($b->getMessage())->toContain('policy_overrides_dates_chk');
});

// ---------- audit + outbox ----------

it('makes audit_logs append-only', function () {
    $org = F::org();
    $id = F::id();
    DB::table('audit_logs')->insert(['id' => $id, 'organization_id' => $org, 'action' => 'policy.published']);

    $u = F::exception(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'edited']));
    expect($u->getMessage())->toContain('append-only');
    $d = F::exception(fn () => DB::table('audit_logs')->where('id', $id)->delete());
    expect($d->getMessage())->toContain('append-only');
});

it('deduplicates outbox events by dedupe_key within an organization', function () {
    $org = F::org();
    $event = fn () => DB::table('domain_events')->insert([
        'id' => F::id(), 'organization_id' => $org, 'event_type' => 'fee.late_fee_posted', 'aggregate_type' => 'voucher',
        'aggregate_id' => 'V1', 'payload' => '{}', 'dedupe_key' => 'voucher:V1:late:2026-10',
    ]);
    $event();

    $e = F::exception($event);
    expect($e->getMessage())->toContain('domain_events_dedupe_uq');
});
