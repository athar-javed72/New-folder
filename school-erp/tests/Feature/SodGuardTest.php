<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SodRule;
use App\Models\User;
use App\Services\Access\SeparationOfDutiesViolation;
use App\Services\Access\SodGuard;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

it('blocks the same user from creating then approving the same discount', function () {
    $orgId = F::org();
    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    $discountId = F::id();

    // User created the discount
    AuditLog::query()->create([
        'organization_id' => $orgId,
        'actor_id' => $user->id,
        'action' => 'fees.discount.create',
        'subject_type' => 'fee_discount',
        'subject_id' => $discountId,
        'meta' => [],
    ]);

    // Same user attempting to approve the same discount must be blocked
    expect(fn () => SodGuard::assertAllowed($user, 'fees.discount.approve', 'fee_discount', $discountId))
        ->toThrow(SeparationOfDutiesViolation::class);
});

it('allows a different user to approve the discount', function () {
    $orgId = F::org();
    /** @var User $creator */
    $creator = User::query()->find(F::user($orgId));
    /** @var User $approver */
    $approver = User::query()->find(F::user($orgId));
    $discountId = F::id();

    AuditLog::query()->create([
        'organization_id' => $orgId,
        'actor_id' => $creator->id,
        'action' => 'fees.discount.create',
        'subject_type' => 'fee_discount',
        'subject_id' => $discountId,
        'meta' => [],
    ]);

    // Different user is allowed
    SodGuard::assertAllowed($approver, 'fees.discount.approve', 'fee_discount', $discountId);
    expect(true)->toBeTrue();
});

it('allows the same user to approve a different discount record', function () {
    $orgId = F::org();
    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    $discount1 = F::id();
    $discount2 = F::id();

    AuditLog::query()->create([
        'organization_id' => $orgId,
        'actor_id' => $user->id,
        'action' => 'fees.discount.create',
        'subject_type' => 'fee_discount',
        'subject_id' => $discount1,
        'meta' => [],
    ]);

    // Same user on discount2 is allowed
    SodGuard::assertAllowed($user, 'fees.discount.approve', 'fee_discount', $discount2);
    expect(true)->toBeTrue();
});

it('enforces all 6 seeded system SoD pairs in both directions', function (string $permA, string $permB, string $recordType) {
    $orgId = F::org();
    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    $record1 = F::id();
    $record2 = F::id();

    // Direction 1: Did permA, tries permB -> blocked
    AuditLog::query()->create([
        'organization_id' => $orgId,
        'actor_id' => $user->id,
        'action' => $permA,
        'subject_type' => $recordType,
        'subject_id' => $record1,
        'meta' => [],
    ]);

    expect(fn () => SodGuard::assertAllowed($user, $permB, $recordType, $record1))
        ->toThrow(SeparationOfDutiesViolation::class);

    // Direction 2: Did permB, tries permA -> blocked
    AuditLog::query()->create([
        'organization_id' => $orgId,
        'actor_id' => $user->id,
        'action' => $permB,
        'subject_type' => $recordType,
        'subject_id' => $record2,
        'meta' => [],
    ]);

    expect(fn () => SodGuard::assertAllowed($user, $permA, $recordType, $record2))
        ->toThrow(SeparationOfDutiesViolation::class);
})->with([
    'discount create vs approve' => ['fees.discount.approve', 'fees.discount.create', 'fee_discount'],
    'refund request vs approve' => ['fees.refund.approve', 'fees.refund.request', 'refund'],
    'payment receive vs reverse' => ['fees.payment.receive', 'fees.payment.reverse', 'payment'],
    'payroll run vs approve' => ['hr.payroll.approve', 'hr.payroll.run', 'payroll_run'],
    'marks enter vs edit after lock' => ['exams.marks.edit_after_lock', 'exams.marks.enter', 'marks_sheet'],
    'admission create vs approve' => ['students.admission.approve', 'students.admission.create', 'admission'],
]);

it('enforces an organization-specific extra rule', function () {
    $orgId = F::org();
    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    $campaignId = F::id();

    // Org adds an extra SoD rule (note permission_a < permission_b per constraint)
    SodRule::query()->create([
        'organization_id' => $orgId,
        'permission_a' => 'comms.announcement.send',
        'permission_b' => 'comms.sms.send_bulk',
        'record_type' => 'campaign',
        'is_active' => true,
    ]);

    AuditLog::query()->create([
        'organization_id' => $orgId,
        'actor_id' => $user->id,
        'action' => 'comms.announcement.send',
        'subject_type' => 'campaign',
        'subject_id' => $campaignId,
        'meta' => [],
    ]);

    expect(fn () => SodGuard::assertAllowed($user, 'comms.sms.send_bulk', 'campaign', $campaignId))
        ->toThrow(SeparationOfDutiesViolation::class);
});

it('ignores an inactive rule', function () {
    $orgId = F::org();
    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    $policyId = F::id();

    SodRule::query()->create([
        'organization_id' => $orgId,
        'permission_a' => 'org.policy.edit',
        'permission_b' => 'org.settings.edit',
        'record_type' => 'policy',
        'is_active' => false,
    ]);

    AuditLog::query()->create([
        'organization_id' => $orgId,
        'actor_id' => $user->id,
        'action' => 'org.policy.edit',
        'subject_type' => 'policy',
        'subject_id' => $policyId,
        'meta' => [],
    ]);

    // Inactive rule is ignored; allowed
    SodGuard::assertAllowed($user, 'org.settings.edit', 'policy', $policyId);
    expect(true)->toBeTrue();
});

it('ignores another organizations rules and audit rows', function () {
    $org1 = F::org();
    $org2 = F::org();

    /** @var User $user1 */
    $user1 = User::query()->find(F::user($org1));
    $recordId = F::id();

    // 1. Rule belongs to Org 2, not Org 1
    SodRule::query()->create([
        'organization_id' => $org2,
        'permission_a' => 'pastoral.medical.edit',
        'permission_b' => 'pastoral.medical.view',
        'record_type' => 'medical_record',
        'is_active' => true,
    ]);

    AuditLog::query()->create([
        'organization_id' => $org1,
        'actor_id' => $user1->id,
        'action' => 'pastoral.medical.edit',
        'subject_type' => 'medical_record',
        'subject_id' => $recordId,
        'meta' => [],
    ]);

    // Allowed because rule belongs only to org2
    SodGuard::assertAllowed($user1, 'pastoral.medical.view', 'medical_record', $recordId);

    // 2. Audit row belongs to Org 2, while user is in Org 1
    $discId = F::id();
    AuditLog::query()->create([
        'organization_id' => $org2,
        'actor_id' => $user1->id,
        'action' => 'fees.discount.create',
        'subject_type' => 'fee_discount',
        'subject_id' => $discId,
        'meta' => [],
    ]);

    // Allowed because audit row was in Org 2, not Org 1
    SodGuard::assertAllowed($user1, 'fees.discount.approve', 'fee_discount', $discId);
    expect(true)->toBeTrue();
});

it('allows the same permission twice on the same record', function () {
    $orgId = F::org();
    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    $discountId = F::id();

    AuditLog::query()->create([
        'organization_id' => $orgId,
        'actor_id' => $user->id,
        'action' => 'fees.discount.create',
        'subject_type' => 'fee_discount',
        'subject_id' => $discountId,
        'meta' => [],
    ]);

    // Doing the same action twice is NOT a violation
    SodGuard::assertAllowed($user, 'fees.discount.create', 'fee_discount', $discountId);
    expect(true)->toBeTrue();
});

it('enforces SoD rules for super admin as well', function () {
    $superAdmin = new User([
        'organization_id' => null,
        'name' => 'Super Admin',
        'email' => 'super_sod@example.test',
        'password' => 'secret',
    ]);
    $superAdmin->forceFill(['is_super_admin' => true])->save();

    $recordId = F::id();

    AuditLog::query()->create([
        'organization_id' => null,
        'actor_id' => $superAdmin->id,
        'action' => 'fees.discount.create',
        'subject_type' => 'fee_discount',
        'subject_id' => $recordId,
        'meta' => [],
    ]);

    expect(fn () => SodGuard::assertAllowed($superAdmin, 'fees.discount.approve', 'fee_discount', $recordId))
        ->toThrow(SeparationOfDutiesViolation::class);
});
