<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DelegationBoundary;
use App\Models\Permission;
use App\Models\PermissionGrant;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\DelegationDeniedException;
use App\Services\Access\DelegationService;
use App\Services\Access\ScopeContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/**
 * Helper to assign a role to a user.
 *
 * @param  array<string, mixed>  $over
 */
function assignTestRole(User $user, Role $role, string $scopeType = 'org', ?string $scopeId = null, array $over = []): RoleAssignment
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

it('allows campus admin to grant within own campus', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgId));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgId));

    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();
    assignTestRole($grantor, $campusAdminRole, 'campus', $campusA);

    $service = new DelegationService;
    $grant = $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'students.admission.create',
        scopeType: 'campus',
        scopeId: $campusA,
    );

    expect($grant)->toBeInstanceOf(PermissionGrant::class);
    expect($grant->status)->toBe('active');
    expect($grant->scope_type)->toBe('campus');
    expect($grant->scope_id)->toBe($campusA);
    expect($grant->granted_by)->toBe($grantor->id);
    expect($grant->parent_grant_id)->toBeNull();

    // Verify audit log
    /** @var AuditLog|null $audit */
    $audit = AuditLog::query()
        ->where('action', 'access.grant.created')
        ->where('subject_id', $grant->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->actor_id)->toBe($grantor->id);
    expect($audit->organization_id)->toBe($orgId);
    expect($audit->meta['permission'])->toBe('students.admission.create');
    expect($audit->meta['grantee_id'])->toBe($grantee->id);

    // Verify resolver allows grantee on campus A
    $ctx = new ScopeContext(organizationId: $orgId, campusId: $campusA);
    expect(AccessResolver::can($grantee, 'students.admission.create', $ctx))->toBeTrue();
});

it('denies campus admin when granting for another campus', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);
    $campusB = F::campus($orgId);

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgId));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgId));

    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();
    assignTestRole($grantor, $campusAdminRole, 'campus', $campusA);

    $service = new DelegationService;

    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'students.admission.create',
        scopeType: 'campus',
        scopeId: $campusB,
    ))->toThrow(DelegationDeniedException::class);

    expect(PermissionGrant::query()->where('user_id', $grantee->id)->count())->toBe(0);
});

it('denies campus admin when granting a sensitive permission', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgId));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgId));

    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();
    assignTestRole($grantor, $campusAdminRole, 'campus', $campusA);

    /** @var Permission $auditLogPerm */
    $auditLogPerm = Permission::query()->where('code', 'audit.log.view')->firstOrFail();
    expect($auditLogPerm->is_sensitive)->toBeTrue();

    $service = new DelegationService;

    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'audit.log.view',
        scopeType: 'campus',
        scopeId: $campusA,
    ))->toThrow(DelegationDeniedException::class);
});

it('allows org_admin to grant a sensitive permission', function () {
    $orgId = F::org();

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgId));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgId));

    /** @var Role $orgAdminRole */
    $orgAdminRole = Role::query()->where('key', 'org_admin')->firstOrFail();
    assignTestRole($grantor, $orgAdminRole, 'org', null);

    $service = new DelegationService;
    $grant = $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'audit.log.view',
        scopeType: 'org',
    );

    expect($grant)->toBeInstanceOf(PermissionGrant::class);
    expect($grant->status)->toBe('active');
    expect($grant->granted_by)->toBe($grantor->id);

    $ctx = new ScopeContext(organizationId: $orgId);
    expect(AccessResolver::can($grantee, 'audit.log.view', $ctx))->toBeTrue();
});

it('denies principal when passing on a fees permission', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgId));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgId));

    /** @var Role $principalRole */
    $principalRole = Role::query()->where('key', 'principal')->firstOrFail();
    assignTestRole($grantor, $principalRole, 'campus', $campusA);

    $service = new DelegationService;

    // Principal holds fees.discount.create but with_grant = false
    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'fees.discount.create',
        scopeType: 'campus',
        scopeId: $campusA,
    ))->toThrow(DelegationDeniedException::class);
});

it('denies cross-organization grantee', function () {
    $orgA = F::org();
    $orgB = F::org();
    $campusA = F::campus($orgA);

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgA));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgB));

    /** @var Role $orgAdminRole */
    $orgAdminRole = Role::query()->where('key', 'org_admin')->firstOrFail();
    assignTestRole($grantor, $orgAdminRole, 'org', null);

    $service = new DelegationService;

    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'students.admission.create',
        scopeType: 'campus',
        scopeId: $campusA,
    ))->toThrow(DelegationDeniedException::class, 'Cross-organization');
});

it('cascades revoke to children and writes audit rows', function () {
    $orgId = F::org();
    $campus = F::campus($orgId);

    /** @var User $user1 */
    $user1 = User::query()->find(F::user($orgId));
    /** @var User $user2 */
    $user2 = User::query()->find(F::user($orgId));
    /** @var User $user3 */
    $user3 = User::query()->find(F::user($orgId));
    /** @var User $user4 */
    $user4 = User::query()->find(F::user($orgId));

    /** @var Role $orgAdminRole */
    $orgAdminRole = Role::query()->where('key', 'org_admin')->firstOrFail();
    assignTestRole($user1, $orgAdminRole, 'org', null);

    $service = new DelegationService;

    // User 1 grants to User 2 with with_grant = true
    $grant1 = $service->grant(
        grantor: $user1,
        grantee: $user2,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
        withGrant: true,
    );

    // User 2 grants to User 3 with with_grant = true (parent_grant_id = grant1)
    $grant2 = $service->grant(
        grantor: $user2,
        grantee: $user3,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
        withGrant: true,
    );
    expect($grant2->parent_grant_id)->toBe($grant1->id);

    // User 3 grants to User 4 without with_grant (parent_grant_id = grant2)
    $grant3 = $service->grant(
        grantor: $user3,
        grantee: $user4,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
        withGrant: false,
    );
    expect($grant3->parent_grant_id)->toBe($grant2->id);

    $ctx = new ScopeContext(organizationId: $orgId, campusId: $campus);
    expect(AccessResolver::can($user2, 'comms.announcement.send', $ctx))->toBeTrue();
    expect(AccessResolver::can($user3, 'comms.announcement.send', $ctx))->toBeTrue();
    expect(AccessResolver::can($user4, 'comms.announcement.send', $ctx))->toBeTrue();

    // Verify 3 create audit logs exist
    expect(AuditLog::query()->where('action', 'access.grant.created')->count())->toBe(3);

    // User 1 revokes Grant 1
    $service->revoke($user1, $grant1, 'Revoking root grant');

    // Reload all grants
    $grant1->refresh();
    $grant2->refresh();
    $grant3->refresh();

    expect($grant1->status)->toBe('revoked');
    expect($grant2->status)->toBe('revoked');
    expect($grant3->status)->toBe('revoked');

    // Verify 3 revoke audit logs were written
    $revokeAudits = AuditLog::query()->where('action', 'access.grant.revoked')->get();
    expect($revokeAudits)->toHaveCount(3);

    $revokedIds = $revokeAudits->pluck('subject_id')->all();
    expect($revokedIds)->toContain($grant1->id, $grant2->id, $grant3->id);

    // Access must now be denied for all
    expect(AccessResolver::can($user2, 'comms.announcement.send', $ctx))->toBeFalse();
    expect(AccessResolver::can($user3, 'comms.announcement.send', $ctx))->toBeFalse();
    expect(AccessResolver::can($user4, 'comms.announcement.send', $ctx))->toBeFalse();
});

it('enforces delegation_boundaries max_scope cap', function () {
    $orgId = F::org();
    $campus = F::campus($orgId);
    $ac = F::academics($orgId, $campus);
    $sectionId = $ac['section'];

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgId));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgId));

    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();
    /** @var Role $classTeacherRole */
    $classTeacherRole = Role::query()->where('key', 'class_teacher')->firstOrFail();

    assignTestRole($grantor, $campusAdminRole, 'campus', $campus);
    assignTestRole($grantee, $classTeacherRole, 'section', $sectionId);

    // Create boundary: Campus Admin delegating to Class Teacher capped at 'section'
    DelegationBoundary::query()->create([
        'organization_id' => $orgId,
        'grantor_role_id' => $campusAdminRole->id,
        'grantee_role_id' => $classTeacherRole->id,
        'max_scope' => 'section',
        'requires_approval' => false,
        'rules' => [],
    ]);

    $service = new DelegationService;

    // Granting at 'campus' scope must fail because 'campus' is wider than boundary max_scope 'section'
    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'students.profile.edit',
        scopeType: 'campus',
        scopeId: $campus,
    ))->toThrow(DelegationDeniedException::class, 'wider than allowed maximum scope');

    // Granting at 'section' scope must succeed because 'section' is not wider than 'section'
    $grant = $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'students.profile.edit',
        scopeType: 'section',
        scopeId: $sectionId,
    );

    expect($grant)->toBeInstanceOf(PermissionGrant::class);
    expect($grant->scope_type)->toBe('section');
    expect($grant->scope_id)->toBe($sectionId);
});

it('verifies AccessResolver::canGrant authority checks directly', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);
    $campusB = F::campus($orgId);

    /** @var User $campusAdmin */
    $campusAdmin = User::query()->find(F::user($orgId));
    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();
    assignTestRole($campusAdmin, $campusAdminRole, 'campus', $campusA);

    /** @var User $orgAdmin */
    $orgAdmin = User::query()->find(F::user($orgId));
    /** @var Role $orgAdminRole */
    $orgAdminRole = Role::query()->where('key', 'org_admin')->firstOrFail();
    assignTestRole($orgAdmin, $orgAdminRole, 'org', null);

    /** @var User $principal */
    $principal = User::query()->find(F::user($orgId));
    /** @var Role $principalRole */
    $principalRole = Role::query()->where('key', 'principal')->firstOrFail();
    assignTestRole($principal, $principalRole, 'campus', $campusA);

    $superAdmin = new User([
        'organization_id' => null,
        'name' => 'Super Admin',
        'email' => 'super_del@example.test',
        'password' => 'secret',
    ]);
    $superAdmin->forceFill(['is_super_admin' => true])->save();

    $ctxA = new ScopeContext(organizationId: $orgId, campusId: $campusA);
    $ctxB = new ScopeContext(organizationId: $orgId, campusId: $campusB);
    $ctxOrg = new ScopeContext(organizationId: $orgId);

    // Campus admin can grant within own campus, but not on another campus
    expect(AccessResolver::canGrant($campusAdmin, 'students.admission.create', $ctxA))->toBeTrue();
    expect(AccessResolver::canGrant($campusAdmin, 'students.admission.create', $ctxB))->toBeFalse();

    // Campus admin cannot grant sensitive permissions
    expect(AccessResolver::canGrant($campusAdmin, 'audit.log.view', $ctxA))->toBeFalse();

    // Org admin can grant sensitive permissions at org scope
    expect(AccessResolver::canGrant($orgAdmin, 'audit.log.view', $ctxOrg))->toBeTrue();

    // Principal cannot pass on fees permissions (with_grant is false)
    expect(AccessResolver::canGrant($principal, 'fees.discount.create', $ctxA))->toBeFalse();

    // Super admin can grant anything
    expect(AccessResolver::canGrant($superAdmin, 'audit.log.view', $ctxOrg))->toBeTrue();
    expect(AccessResolver::canGrant($superAdmin, 'students.admission.create', $ctxA))->toBeTrue();
});

it('denies unauthorized users from revoking a grant and allows super admin / org admin', function () {
    $orgId = F::org();
    $campus = F::campus($orgId);

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgId));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgId));
    /** @var User $randomUser */
    $randomUser = User::query()->find(F::user($orgId));
    /** @var User $orgAdmin */
    $orgAdmin = User::query()->find(F::user($orgId));

    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();
    assignTestRole($grantor, $campusAdminRole, 'campus', $campus);

    /** @var Role $orgAdminRole */
    $orgAdminRole = Role::query()->where('key', 'org_admin')->firstOrFail();
    assignTestRole($orgAdmin, $orgAdminRole, 'org', null);

    $superAdmin = new User([
        'organization_id' => null,
        'name' => 'Super Admin',
        'email' => 'super_revoke@example.test',
        'password' => 'secret',
    ]);
    $superAdmin->forceFill(['is_super_admin' => true])->save();

    $service = new DelegationService;
    $grant = $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'students.admission.create',
        scopeType: 'campus',
        scopeId: $campus,
    );

    // Random user cannot revoke
    expect(fn () => $service->revoke($randomUser, $grant))->toThrow(DelegationDeniedException::class);

    // Org admin CAN revoke grant created by campus admin
    $service->revoke($orgAdmin, $grant, 'Revoked by org admin');
    $grant->refresh();
    expect($grant->status)->toBe('revoked');

    // Create another grant and test super admin revoke
    $grant2 = $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'students.admission.create',
        scopeType: 'campus',
        scopeId: $campus,
    );

    $service->revoke($superAdmin, $grant2, 'Revoked by super admin');
    $grant2->refresh();
    expect($grant2->status)->toBe('revoked');
});

it('caps child grant ends_at by parent grant ends_at and denies self-delegation (D-37, D-38)', function () {
    $orgId = F::org();
    $campus = F::campus($orgId);

    /** @var User $campusAdmin */
    $campusAdmin = User::query()->find(F::user($orgId));
    /** @var User $teacherA */
    $teacherA = User::query()->find(F::user($orgId));
    /** @var User $teacherB */
    $teacherB = User::query()->find(F::user($orgId));

    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();
    assignTestRole($campusAdmin, $campusAdminRole, 'campus', $campus);

    $service = new DelegationService;

    // Self-grant is denied for normal user
    expect(fn () => $service->grant(
        grantor: $campusAdmin,
        grantee: $campusAdmin,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
    ))->toThrow(DelegationDeniedException::class, 'Self-delegation is not allowed.');

    // Super admin may self-grant
    /** @var User $superAdmin */
    $superAdmin = User::query()->find(F::user($orgId));
    $superAdmin->is_super_admin = true;

    $superSelfGrant = $service->grant(
        grantor: $superAdmin,
        grantee: $superAdmin,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
    );
    expect($superSelfGrant->user_id)->toBe($superAdmin->id);

    // Parent grant with ends_at in 10 days
    $parentEndsAt = now()->addDays(10)->startOfSecond();
    $parentGrant = $service->grant(
        grantor: $campusAdmin,
        grantee: $teacherA,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
        withGrant: true,
        endsAt: $parentEndsAt,
    );

    // Child with null ends_at gets the parent's ends_at
    $childNull = $service->grant(
        grantor: $teacherA,
        grantee: $teacherB,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
        endsAt: null,
    );
    expect($childNull->ends_at?->toIso8601String())->toBe($parentEndsAt->toIso8601String());

    // Child later than parent is capped and audit shows capped value
    $requestedLater = now()->addDays(20)->startOfSecond();
    $childLater = $service->grant(
        grantor: $teacherA,
        grantee: $teacherB,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
        endsAt: $requestedLater,
    );
    expect($childLater->ends_at?->toIso8601String())->toBe($parentEndsAt->toIso8601String());

    $auditRow = AuditLog::query()
        ->where('action', 'access.grant.created')
        ->where('subject_id', $childLater->id)
        ->firstOrFail();
    expect(Carbon::parse((string) $auditRow->after['ends_at'])->format('Y-m-d H:i:s'))->toBe($parentEndsAt->format('Y-m-d H:i:s'));

    // Child earlier than parent keeps its own date
    $requestedEarlier = now()->addDays(5)->startOfSecond();
    $childEarlier = $service->grant(
        grantor: $teacherA,
        grantee: $teacherB,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
        endsAt: $requestedEarlier,
    );
    expect($childEarlier->ends_at?->toIso8601String())->toBe($requestedEarlier->toIso8601String());
});

it('locks parent grant and revoke targets FOR UPDATE and ignores stale revoked models (D-39)', function () {
    $orgId = F::org();
    $campus = F::campus($orgId);

    /** @var User $campusAdmin */
    $campusAdmin = User::query()->find(F::user($orgId));
    /** @var User $teacherA */
    $teacherA = User::query()->find(F::user($orgId));
    /** @var User $teacherB */
    $teacherB = User::query()->find(F::user($orgId));

    /** @var Role $campusAdminRole */
    $campusAdminRole = Role::query()->where('key', 'campus_admin')->firstOrFail();
    assignTestRole($campusAdmin, $campusAdminRole, 'campus', $campus);

    $service = new DelegationService;

    $parentGrant = $service->grant(
        grantor: $campusAdmin,
        grantee: $teacherA,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
        withGrant: true,
        endsAt: now()->addDays(10),
    );

    // Track SQL queries during grant with parent
    $executedQueries = [];
    DB::listen(function ($query) use (&$executedQueries) {
        $executedQueries[] = $query->sql;
    });

    $childGrant = $service->grant(
        grantor: $teacherA,
        grantee: $teacherB,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campus,
    );

    $hasLockForUpdateOnGrant = collect($executedQueries)->contains(function ($sql) {
        return str_contains(strtolower($sql), 'for update') && str_contains(strtolower($sql), 'permission_grants');
    });
    expect($hasLockForUpdateOnGrant)->toBeTrue();

    // Track SQL queries during revoke
    $revokeQueries = [];
    DB::listen(function ($query) use (&$revokeQueries) {
        $revokeQueries[] = $query->sql;
    });

    $service->revoke($teacherA, $childGrant, 'Revoking child');

    $hasLockForUpdateOnRevoke = collect($revokeQueries)->contains(function ($sql) {
        return str_contains(strtolower($sql), 'for update') && str_contains(strtolower($sql), 'permission_grants');
    });
    expect($hasLockForUpdateOnRevoke)->toBeTrue();

    // Calling revoke with a stale model of an already revoked grant writes no second audit row
    $auditCountBefore = AuditLog::query()->where('action', 'access.grant.revoked')->count();
    $service->revoke($teacherA, $childGrant, 'Duplicate revoke attempt');
    $auditCountAfter = AuditLog::query()->where('action', 'access.grant.revoked')->count();
    expect($auditCountAfter)->toBe($auditCountBefore);
});

it('requires roles.is_system = true for org_admin power (D-40)', function () {
    $orgId = F::org();
    /** @var User $fakeOrgAdminUser */
    $fakeOrgAdminUser = User::query()->find(F::user($orgId));
    /** @var User $realOrgAdminUser */
    $realOrgAdminUser = User::query()->find(F::user($orgId));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgId));

    // Create a non-system role with key org_admin
    $fakeRole = Role::query()->create([
        'organization_id' => $orgId,
        'key' => 'org_admin',
        'name' => 'Custom Org Admin',
        'is_system' => false,
    ]);

    $sensitivePerm = Permission::query()->where('code', 'audit.log.view')->firstOrFail();
    DB::table('role_permissions')->insert([
        'role_id' => $fakeRole->id,
        'permission_id' => $sensitivePerm->id,
        'max_scope' => 'org',
        'with_grant' => true,
    ]);

    assignTestRole($fakeOrgAdminUser, $fakeRole, 'org', null);

    /** @var Role $realRole */
    $realRole = Role::query()->where('key', 'org_admin')->where('is_system', true)->firstOrFail();
    assignTestRole($realOrgAdminUser, $realRole, 'org', null);

    $service = new DelegationService;

    // Fake org_admin cannot grant sensitive permission
    expect(fn () => $service->grant(
        grantor: $fakeOrgAdminUser,
        grantee: $grantee,
        permission: 'audit.log.view',
        scopeType: 'org',
    ))->toThrow(DelegationDeniedException::class);

    // Real system org_admin can grant sensitive permission
    $grant = $service->grant(
        grantor: $realOrgAdminUser,
        grantee: $grantee,
        permission: 'audit.log.view',
        scopeType: 'org',
    );
    expect($grant->id)->not->toBeNull();

    // Fake org_admin cannot revoke real grant
    expect(fn () => $service->revoke($fakeOrgAdminUser, $grant))
        ->toThrow(DelegationDeniedException::class, 'Actor is not authorized to revoke this grant.');

    // Real system org_admin can revoke
    $service->revoke($realOrgAdminUser, $grant, 'Revoked by real org admin');
    $grant->refresh();
    expect($grant->status)->toBe('revoked');
});

it('validates scope_id existence in grantee organization for tables (D-42)', function () {
    $orgA = F::org();
    $orgB = F::org();

    $campusA = F::campus($orgA);
    $campusB = F::campus($orgB);

    $acA = F::academics($orgA, $campusA);
    $acB = F::academics($orgB, $campusB);

    /** @var User $grantor */
    $grantor = User::query()->find(F::user($orgA));
    /** @var User $grantee */
    $grantee = User::query()->find(F::user($orgA));

    /** @var Role $orgAdminRole */
    $orgAdminRole = Role::query()->where('key', 'org_admin')->where('is_system', true)->firstOrFail();
    assignTestRole($grantor, $orgAdminRole, 'org', null);

    $service = new DelegationService;

    // Nonexistent scope_id rejected
    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: (string) Str::ulid(),
    ))->toThrow(DelegationDeniedException::class, 'not found in organization');

    // Scope_id from another organization rejected
    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campusB,
    ))->toThrow(DelegationDeniedException::class, 'not found in organization');

    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'comms.announcement.send',
        scopeType: 'grade',
        scopeId: $acB['grade'],
    ))->toThrow(DelegationDeniedException::class, 'not found in organization');

    expect(fn () => $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'comms.announcement.send',
        scopeType: 'section',
        scopeId: $acB['section'],
    ))->toThrow(DelegationDeniedException::class, 'not found in organization');

    // Valid ones accepted for campus, grade, and section
    $grantCampus = $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'comms.announcement.send',
        scopeType: 'campus',
        scopeId: $campusA,
    );
    expect($grantCampus->scope_type)->toBe('campus');

    $grantGrade = $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'comms.announcement.send',
        scopeType: 'grade',
        scopeId: $acA['grade'],
    );
    expect($grantGrade->scope_type)->toBe('grade');

    $grantSection = $service->grant(
        grantor: $grantor,
        grantee: $grantee,
        permission: 'comms.announcement.send',
        scopeType: 'section',
        scopeId: $acA['section'],
    );
    expect($grantSection->scope_type)->toBe('section');
});
