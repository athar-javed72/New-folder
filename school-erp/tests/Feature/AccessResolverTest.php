<?php

declare(strict_types=1);

use App\Models\PermissionGrant;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\ScopeContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/**
 * @param  array<string, mixed>  $over
 */
function assignRole(User $user, Role $role, string $scopeType = 'org', ?string $scopeId = null, array $over = []): RoleAssignment
{
    return RoleAssignment::query()->create($over + [
        'organization_id' => $user->organization_id,
        'user_id' => $user->id,
        'role_id' => $role->id,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'status' => 'active',
        'starts_at' => now()->subMinute(),
        'ends_at' => null,
    ]);
}

it('ensures campus admin of campus A cannot act on campus B', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);
    $campusB = F::campus($orgId);

    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();

    assignRole($user, $campusAdminRole, 'campus', $campusA);

    $ctxA = new ScopeContext(organizationId: $orgId, campusId: $campusA);
    $ctxB = new ScopeContext(organizationId: $orgId, campusId: $campusB);

    expect(AccessResolver::can($user, 'students.admission.create', $ctxA))->toBeTrue();
    expect(AccessResolver::can($user, 'students.admission.create', $ctxB))->toBeFalse();
});

it('denies expired assignments', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);

    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();

    assignRole($user, $campusAdminRole, 'campus', $campusA, [
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDay(),
    ]);

    $ctx = new ScopeContext(organizationId: $orgId, campusId: $campusA);

    expect(AccessResolver::can($user, 'students.admission.create', $ctx))->toBeFalse();
});

it('denies suspended and revoked assignments', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);

    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();

    $assignment = assignRole($user, $campusAdminRole, 'campus', $campusA, [
        'status' => 'suspended',
    ]);

    $ctx = new ScopeContext(organizationId: $orgId, campusId: $campusA);

    expect(AccessResolver::can($user, 'students.admission.create', $ctx))->toBeFalse();

    $assignment->update(['status' => 'revoked']);
    expect(AccessResolver::can($user, 'students.admission.create', $ctx))->toBeFalse();
});

it('ensures section-scoped teacher passes only for the assigned section', function () {
    $orgId = F::org();
    $campus = F::campus($orgId);
    $ac = F::academics($orgId, $campus);
    $sec1 = $ac['section'];

    $sec2 = F::id();
    DB::table('sections')->insert([
        'id' => $sec2,
        'organization_id' => $orgId,
        'campus_id' => $campus,
        'academic_calendar_id' => $ac['calendar'],
        'grade_id' => $ac['grade'],
        'name' => 'B',
    ]);

    /** @var User $teacher */
    $teacher = User::query()->find(F::user($orgId));
    /** @var Role $teacherRole */
    $teacherRole = Role::query()->where('key', 'class_teacher')->firstOrFail();

    assignRole($teacher, $teacherRole, 'section', $sec1);

    $ctx1 = new ScopeContext(organizationId: $orgId, campusId: $campus, sectionId: $sec1);
    $ctx2 = new ScopeContext(organizationId: $orgId, campusId: $campus, sectionId: $sec2);

    expect(AccessResolver::can($teacher, 'attendance.student.mark', $ctx1))->toBeTrue();
    expect(AccessResolver::can($teacher, 'attendance.student.mark', $ctx2))->toBeFalse();
});

it('allows super admin to pass unconditionally', function () {
    $superAdmin = User::query()->create([
        'organization_id' => null,
        'name' => 'Super Admin',
        'email' => 'super@example.test',
        'password' => 'secret',
        'is_super_admin' => true,
    ]);

    $orgId = F::org();
    $campusId = F::campus($orgId);
    $ctx = new ScopeContext(organizationId: $orgId, campusId: $campusId);

    // No roles or grants exist for super admin, yet all permissions pass
    expect(AccessResolver::can($superAdmin, 'students.admission.create', $ctx))->toBeTrue();
    expect(AccessResolver::can($superAdmin, 'audit.log.view'))->toBeTrue();
});

it('denies cross-organization access', function () {
    $orgA = F::org();
    $orgB = F::org();
    $campusA = F::campus($orgA);

    /** @var User $userA */
    $userA = User::query()->find(F::user($orgA));
    /** @var Role $role */
    $role = Role::query()->where('key', 'campus_admin')->firstOrFail();

    assignRole($userA, $role, 'campus', $campusA);

    // Context specifies Org B
    $ctxCross = new ScopeContext(organizationId: $orgB, campusId: $campusA);

    expect(AccessResolver::can($userA, 'students.admission.create', $ctxCross))->toBeFalse();
});

it('denies access when module is disabled in module_enablement', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);
    $campusB = F::campus($orgId);

    /** @var User $userA */
    $userA = User::query()->find(F::user($orgId));
    /** @var User $userB */
    $userB = User::query()->find(F::user($orgId));
    /** @var Role $accountantRole */
    $accountantRole = Role::query()->where('key', 'accountant')->firstOrFail();

    assignRole($userA, $accountantRole, 'campus', $campusA);
    assignRole($userB, $accountantRole, 'campus', $campusB);

    $ctxA = new ScopeContext(organizationId: $orgId, campusId: $campusA);
    $ctxB = new ScopeContext(organizationId: $orgId, campusId: $campusB);

    // Initially passes on both campuses
    expect(AccessResolver::can($userA, 'fees.voucher.view', $ctxA))->toBeTrue();
    expect(AccessResolver::can($userB, 'fees.voucher.view', $ctxB))->toBeTrue();

    // Disable fees at org level
    DB::table('module_enablement')->insert([
        'id' => F::id(),
        'organization_id' => $orgId,
        'campus_id' => null,
        'module' => 'fees',
        'enabled' => false,
        'source' => 'org',
    ]);

    expect(AccessResolver::can($userA, 'fees.voucher.view', $ctxA))->toBeFalse();
    expect(AccessResolver::can($userB, 'fees.voucher.view', $ctxB))->toBeFalse();

    // Override at campus A to enable
    DB::table('module_enablement')->insert([
        'id' => F::id(),
        'organization_id' => $orgId,
        'campus_id' => $campusA,
        'module' => 'fees',
        'enabled' => true,
        'source' => 'campus',
    ]);

    expect(AccessResolver::can($userA, 'fees.voucher.view', $ctxA))->toBeTrue();
    expect(AccessResolver::can($userB, 'fees.voucher.view', $ctxB))->toBeFalse();
});

it('denies access when assignment scope exceeds permission max_scope ceiling', function () {
    $orgId = F::org();
    $campus = F::campus($orgId);

    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    /** @var Role $teacherRole */
    $teacherRole = Role::query()->where('key', 'class_teacher')->firstOrFail();

    // class_teacher has attendance.student.mark with max_scope = 'section'
    // Assigning at 'campus' scope (wider than section ceiling) must deny
    assignRole($user, $teacherRole, 'campus', $campus);

    $ctx = new ScopeContext(organizationId: $orgId, campusId: $campus);

    expect(AccessResolver::can($user, 'attendance.student.mark', $ctx))->toBeFalse();
});

it('grants permission via direct PermissionGrant regardless of max_scope', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);
    $campusB = F::campus($orgId);

    /** @var User $user */
    $user = User::query()->find(F::user($orgId));
    $permissionId = DB::table('permissions')->where('code', 'fees.discount.create')->value('id');
    expect($permissionId)->not->toBeNull();

    PermissionGrant::query()->create([
        'organization_id' => $orgId,
        'user_id' => $user->id,
        'permission_id' => $permissionId,
        'scope_type' => 'campus',
        'scope_id' => $campusA,
        'status' => 'active',
        'starts_at' => now()->subMinute(),
        'ends_at' => null,
    ]);

    $ctxA = new ScopeContext(organizationId: $orgId, campusId: $campusA);
    $ctxB = new ScopeContext(organizationId: $orgId, campusId: $campusB);

    expect(AccessResolver::can($user, 'fees.discount.create', $ctxA))->toBeTrue();
    expect(AccessResolver::can($user, 'fees.discount.create', $ctxB))->toBeFalse();
});
