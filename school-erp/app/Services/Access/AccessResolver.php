<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccessResolver
{
    /**
     * Scope width ranks: lower number = wider scope.
     *
     * @var array<string, int>
     */
    public const SCOPE_RANKS = [
        'org' => 1,
        'campus' => 2,
        'program' => 3,
        'grade' => 4,
        'section' => 5,
        'session' => 6,
    ];

    /**
     * Determine whether a user holds a permission within a given scope context.
     */
    public static function can(User $user, string $permission, ?ScopeContext $scope = null): bool
    {
        // 1. Super admin passes unconditionally
        if ($user->is_super_admin) {
            return true;
        }

        // 2. Non-super admin must have an organization
        if ($user->organization_id === null) {
            return false;
        }

        $orgId = $user->organization_id;

        // 3. If context specifies an organization, it must match the user's organization
        if ($scope?->organizationId !== null && $scope->organizationId !== $orgId) {
            return false;
        }

        // 4. Check module enablement (first segment of permission code)
        $module = explode('.', $permission)[0];
        if (! self::isModuleEnabled($orgId, $module, $scope)) {
            return false;
        }

        // 5. Look up permission record
        $permissionRecord = Permission::query()->where('code', $permission)->first();
        if ($permissionRecord === null) {
            return false;
        }

        $now = now();

        // 6. Check active role assignments
        $assignments = DB::table('role_assignments')
            ->join('role_permissions', 'role_assignments.role_id', '=', 'role_permissions.role_id')
            ->where('role_assignments.organization_id', $orgId)
            ->where('role_assignments.user_id', $user->id)
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.starts_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('role_assignments.ends_at')
                    ->orWhere('role_assignments.ends_at', '>', $now);
            })
            ->where('role_permissions.permission_id', $permissionRecord->id)
            ->select([
                'role_assignments.scope_type',
                'role_assignments.scope_id',
                'role_permissions.max_scope',
            ])
            ->get();

        foreach ($assignments as $assignment) {
            // Ceiling rule: assignment scope cannot be wider than the role_permission's max_scope
            if (self::isScopeWider((string) $assignment->scope_type, (string) $assignment->max_scope)) {
                continue;
            }

            // Coverage rule: check if assignment scope covers the context
            if (self::covers((string) $assignment->scope_type, $assignment->scope_id !== null ? (string) $assignment->scope_id : null, $scope)) {
                return true;
            }
        }

        // 7. Check active permission grants (permission grants have no max_scope ceiling)
        $grants = DB::table('permission_grants')
            ->where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->where('permission_id', $permissionRecord->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $now);
            })
            ->select([
                'scope_type',
                'scope_id',
            ])
            ->get();

        foreach ($grants as $grant) {
            if (self::covers((string) $grant->scope_type, $grant->scope_id !== null ? (string) $grant->scope_id : null, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a user holds grant authority for a permission within a given scope context.
     */
    public static function canGrant(User $user, string $permission, ?ScopeContext $scope = null): bool
    {
        return self::resolveGrantAuthority($user, $permission, $scope) !== null;
    }

    /**
     * Resolve the grantor's authority for granting a permission:
     * - Returns ['source' => 'super_admin'|'role', 'grant_id' => null] if from super admin or role assignment.
     * - Returns ['source' => 'grant', 'grant_id' => string] if from a permission grant.
     * - Returns null if not authorized.
     *
     * @return array{source: string, grant_id: ?string}|null
     */
    public static function resolveGrantAuthority(User $user, string $permission, ?ScopeContext $scope = null): ?array
    {
        // 1. Super admin passes unconditionally
        if ($user->is_super_admin) {
            return ['source' => 'super_admin', 'grant_id' => null];
        }

        // 2. Non-super admin must have an organization
        if ($user->organization_id === null) {
            return null;
        }

        $orgId = $user->organization_id;

        // 3. If context specifies an organization, it must match user's organization
        if ($scope?->organizationId !== null && $scope->organizationId !== $orgId) {
            return null;
        }

        // 4. Check module enablement (first segment of permission code)
        $module = explode('.', $permission)[0];
        if (! self::isModuleEnabled($orgId, $module, $scope)) {
            return null;
        }

        // 5. Look up permission record
        $permissionRecord = Permission::query()->where('code', $permission)->first();
        if ($permissionRecord === null) {
            return null;
        }

        $now = now();

        // 6. Sensitive permission check: only active org_admin at org scope (or super_admin) may grant
        if ($permissionRecord->is_sensitive) {
            $isOrgAdmin = DB::table('role_assignments')
                ->join('roles', 'role_assignments.role_id', '=', 'roles.id')
                ->where('role_assignments.organization_id', $orgId)
                ->where('role_assignments.user_id', $user->id)
                ->where('roles.key', 'org_admin')
                ->where('role_assignments.scope_type', 'org')
                ->where('role_assignments.status', 'active')
                ->where('role_assignments.starts_at', '<=', $now)
                ->where(function ($q) use ($now) {
                    $q->whereNull('role_assignments.ends_at')
                        ->orWhere('role_assignments.ends_at', '>', $now);
                })
                ->exists();

            if (! $isOrgAdmin) {
                return null;
            }
        }

        // 7. Check active role assignments with with_grant = true
        $assignments = DB::table('role_assignments')
            ->join('role_permissions', 'role_assignments.role_id', '=', 'role_permissions.role_id')
            ->where('role_assignments.organization_id', $orgId)
            ->where('role_assignments.user_id', $user->id)
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.starts_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('role_assignments.ends_at')
                    ->orWhere('role_assignments.ends_at', '>', $now);
            })
            ->where('role_permissions.permission_id', $permissionRecord->id)
            ->where('role_permissions.with_grant', true)
            ->select([
                'role_assignments.scope_type',
                'role_assignments.scope_id',
                'role_permissions.max_scope',
            ])
            ->get();

        foreach ($assignments as $assignment) {
            // Ceiling rule: assignment scope cannot be wider than the role_permission's max_scope
            if (self::isScopeWider((string) $assignment->scope_type, (string) $assignment->max_scope)) {
                continue;
            }

            // Coverage rule: check if assignment scope covers the context
            if (self::covers((string) $assignment->scope_type, $assignment->scope_id !== null ? (string) $assignment->scope_id : null, $scope)) {
                return ['source' => 'role', 'grant_id' => null];
            }
        }

        // 8. Sensitive permissions cannot be granted via permission grants (only org_admin role or super admin)
        if ($permissionRecord->is_sensitive) {
            return null;
        }

        // 9. Check active permission grants with with_grant = true
        $grants = DB::table('permission_grants')
            ->where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->where('permission_id', $permissionRecord->id)
            ->where('status', 'active')
            ->where('with_grant', true)
            ->where('starts_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $now);
            })
            ->select([
                'id',
                'scope_type',
                'scope_id',
            ])
            ->get();

        foreach ($grants as $grant) {
            if (self::covers((string) $grant->scope_type, $grant->scope_id !== null ? (string) $grant->scope_id : null, $scope)) {
                return ['source' => 'grant', 'grant_id' => (string) $grant->id];
            }
        }

        return null;
    }

    /**
     * Check if module is enabled in module_enablement.
     * A campus row overrides the org row; no row means enabled.
     */
    private static function isModuleEnabled(string $orgId, string $module, ?ScopeContext $ctx): bool
    {
        if ($ctx?->campusId !== null) {
            $campusRow = DB::table('module_enablement')
                ->where('organization_id', $orgId)
                ->where('campus_id', $ctx->campusId)
                ->where('module', $module)
                ->first();

            if ($campusRow !== null) {
                return (bool) $campusRow->enabled;
            }
        }

        $orgRow = DB::table('module_enablement')
            ->where('organization_id', $orgId)
            ->whereNull('campus_id')
            ->where('module', $module)
            ->first();

        if ($orgRow !== null) {
            return (bool) $orgRow->enabled;
        }

        return true;
    }

    /**
     * Returns true if scopeA is wider than scopeB (lower rank number = wider).
     */
    public static function isScopeWider(string $scopeA, string $scopeB): bool
    {
        $rankA = self::SCOPE_RANKS[$scopeA] ?? 99;
        $rankB = self::SCOPE_RANKS[$scopeB] ?? 99;

        return $rankA < $rankB;
    }

    /**
     * Determine if a holder at (scopeType, scopeId) covers the given context.
     * No context (null) means only an org-level holder passes.
     */
    private static function covers(string $scopeType, ?string $scopeId, ?ScopeContext $ctx): bool
    {
        if ($ctx === null) {
            return $scopeType === 'org';
        }

        if ($scopeType === 'org') {
            return true;
        }

        return match ($scopeType) {
            'campus' => $scopeId !== null && $scopeId === $ctx->campusId,
            'program' => $scopeId !== null && $scopeId === $ctx->programId,
            'grade' => $scopeId !== null && $scopeId === $ctx->gradeId,
            'section' => $scopeId !== null && $scopeId === $ctx->sectionId,
            'session' => $scopeId !== null && $scopeId === $ctx->sessionId,
            default => false,
        };
    }
}
