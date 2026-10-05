<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionGrant;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DelegationService
{
    /**
     * @var list<string>
     */
    private const VALID_SCOPES = [
        'org',
        'campus',
        'program',
        'grade',
        'section',
        'session',
    ];

    /**
     * Grant a single permission to a grantee.
     */
    public function grant(
        User $grantor,
        User $grantee,
        string $permission,
        string $scopeType,
        ?string $scopeId = null,
        bool $withGrant = false,
        ?DateTimeInterface $endsAt = null,
        ?string $reason = null,
    ): PermissionGrant {
        return DB::transaction(function () use (
            $grantor,
            $grantee,
            $permission,
            $scopeType,
            $scopeId,
            $withGrant,
            $endsAt,
            $reason,
        ): PermissionGrant {
            // 0. Self-delegation check (D-38)
            if (! $grantor->is_super_admin && $grantor->id === $grantee->id) {
                throw new DelegationDeniedException('Self-delegation is not allowed.');
            }

            // 1. Grantee must belong to an organization
            if ($grantee->organization_id === null) {
                throw new DelegationDeniedException('Grantee must belong to an organization.');
            }

            // 2. Cross-org check (Decision 4)
            if ($grantor->organization_id !== null && $grantor->organization_id !== $grantee->organization_id) {
                throw new DelegationDeniedException('Cross-organization delegation is not allowed.');
            }

            $orgId = $grantee->organization_id;

            // Validate scope_id against table if table exists (D-42)
            $scopeTable = match ($scopeType) {
                'campus' => 'campuses',
                'grade' => 'grades',
                'section' => 'sections',
                'program' => 'programs',
                default => null, // 'org' has no scope_id; 'session' has no domain table
            };

            if ($scopeTable !== null) {
                $exists = DB::table($scopeTable)
                    ->where('organization_id', $orgId)
                    ->where('id', $scopeId)
                    ->exists();

                if (! $exists) {
                    throw new DelegationDeniedException("Scope ID '{$scopeId}' not found in organization for scope type '{$scopeType}'.");
                }
            }

            // 3. Validate scope type
            if (! in_array($scopeType, self::VALID_SCOPES, true)) {
                throw new DelegationDeniedException("Invalid scope type: {$scopeType}.");
            }

            if ($scopeType !== 'org' && $scopeId === null) {
                throw new DelegationDeniedException("Scope ID is required for scope type '{$scopeType}'.");
            }

            if ($scopeType === 'org') {
                $scopeId = null;
            }

            // 4. Validate ends_at
            $now = now();
            if ($endsAt !== null && $endsAt <= $now) {
                throw new DelegationDeniedException('Grant ends_at must be in the future.');
            }

            // 5. Build target ScopeContext
            $campusId = match ($scopeType) {
                'campus' => $scopeId,
                'grade' => DB::table('sections')->where('organization_id', $orgId)->where('grade_id', $scopeId)->value('campus_id'),
                'section' => DB::table('sections')->where('organization_id', $orgId)->where('id', $scopeId)->value('campus_id'),
                default => null,
            };

            $targetCtx = new ScopeContext(
                organizationId: $orgId,
                campusId: $campusId !== null ? (string) $campusId : null,
                programId: $scopeType === 'program' ? $scopeId : null,
                gradeId: $scopeType === 'grade' ? $scopeId : null,
                sectionId: $scopeType === 'section' ? $scopeId : null,
                sessionId: $scopeType === 'session' ? $scopeId : null,
            );

            // 6. Check grantor authority (Decision 2, 3, 6)
            $authority = AccessResolver::resolveGrantAuthority($grantor, $permission, $targetCtx);
            if ($authority === null) {
                throw new DelegationDeniedException("Grantor is not authorized to grant permission '{$permission}' at scope '{$scopeType}'.");
            }

            $parentGrantId = $authority['grant_id'];

            // Concurrency & lifetime cap from parent grant (D-37, D-39)
            if ($parentGrantId !== null) {
                /** @var PermissionGrant|null $parentGrant */
                $parentGrant = PermissionGrant::query()
                    ->where('id', $parentGrantId)
                    ->lockForUpdate()
                    ->first();

                if ($parentGrant === null || $parentGrant->status !== 'active') {
                    throw new DelegationDeniedException('Parent grant is no longer active.');
                }

                if ($parentGrant->ends_at !== null && $parentGrant->ends_at <= $now) {
                    throw new DelegationDeniedException('Parent grant has expired.');
                }

                if ($parentGrant->ends_at !== null) {
                    if ($endsAt === null || $endsAt > $parentGrant->ends_at) {
                        $endsAt = $parentGrant->ends_at;
                    }
                }
            }

            // 7. Check delegation boundaries (Decision 5)
            $this->assertDelegationBoundaries($grantor, $grantee, $orgId, $scopeType);

            // 8. Lookup permission record
            /** @var Permission $permissionRecord */
            $permissionRecord = Permission::query()->where('code', $permission)->firstOrFail();

            // 9. Create permission grant
            /** @var PermissionGrant $grant */
            $grant = PermissionGrant::query()->create([
                'organization_id' => $orgId,
                'user_id' => $grantee->id,
                'permission_id' => $permissionRecord->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'granted_by' => $grantor->id,
                'parent_grant_id' => $parentGrantId,
                'with_grant' => $withGrant,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'status' => 'active',
                'reason' => $reason,
            ]);

            // 10. Write audit log (Decision 8)
            AuditLog::query()->create([
                'organization_id' => $orgId,
                'actor_id' => $grantor->id,
                'action' => 'access.grant.created',
                'subject_type' => 'permission_grant',
                'subject_id' => $grant->id,
                'before' => null,
                'after' => [
                    'status' => 'active',
                    'with_grant' => $withGrant,
                    'ends_at' => $endsAt?->format(DateTimeInterface::ATOM),
                ],
                'meta' => [
                    'permission' => $permission,
                    'scope' => $scopeType,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'grantee' => $grantee->id,
                    'grantee_id' => $grantee->id,
                ],
            ]);

            return $grant;
        });
    }

    /**
     * Revoke a grant and cascade revocation to all descendants.
     */
    public function revoke(User $actor, PermissionGrant $grant, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $grant, $reason): void {
            /** @var PermissionGrant|null $freshGrant */
            $freshGrant = PermissionGrant::query()
                ->where('id', $grant->id)
                ->lockForUpdate()
                ->first();

            if ($freshGrant === null || $freshGrant->status === 'revoked') {
                return;
            }

            // 1. Authorization check (Decision 7 & D-40)
            if (! $this->canRevoke($actor, $freshGrant)) {
                throw new DelegationDeniedException('Actor is not authorized to revoke this grant.');
            }

            // 2. Cascade revoke
            $visited = [];
            $this->revokeRecursively($actor, $freshGrant, $reason, $visited);
        });
    }

    /**
     * Check if actor is allowed to revoke: original grantor, org_admin, or super admin.
     */
    private function canRevoke(User $actor, PermissionGrant $grant): bool
    {
        if ($actor->is_super_admin) {
            return true;
        }

        if ($grant->granted_by !== null && $grant->granted_by === $actor->id) {
            return true;
        }

        // Check if actor has active org_admin role at org scope
        $now = now();

        return DB::table('role_assignments')
            ->join('roles', 'role_assignments.role_id', '=', 'roles.id')
            ->where('role_assignments.organization_id', $grant->organization_id)
            ->where('role_assignments.user_id', $actor->id)
            ->where('roles.key', 'org_admin')
            ->where('roles.is_system', true)
            ->where('role_assignments.scope_type', 'org')
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.starts_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('role_assignments.ends_at')
                    ->orWhere('role_assignments.ends_at', '>', $now);
            })
            ->exists();
    }

    /**
     * Recursively revoke grant and all children.
     *
     * @param  array<string, bool>  $visited
     */
    private function revokeRecursively(User $actor, PermissionGrant $grant, ?string $reason, array &$visited): void
    {
        if (isset($visited[$grant->id])) {
            return;
        }
        $visited[$grant->id] = true;

        if ($grant->status !== 'revoked') {
            $originalStatus = $grant->status;
            $grant->status = 'revoked';
            if ($reason !== null) {
                $grant->reason = $reason;
            }
            $grant->save();

            $permissionCode = DB::table('permissions')
                ->where('id', $grant->permission_id)
                ->value('code') ?? 'unknown';

            AuditLog::query()->create([
                'organization_id' => $grant->organization_id,
                'actor_id' => $actor->id,
                'action' => 'access.grant.revoked',
                'subject_type' => 'permission_grant',
                'subject_id' => $grant->id,
                'before' => ['status' => $originalStatus],
                'after' => ['status' => 'revoked'],
                'meta' => [
                    'permission' => $permissionCode,
                    'scope' => $grant->scope_type,
                    'scope_type' => $grant->scope_type,
                    'scope_id' => $grant->scope_id,
                    'grantee' => $grant->user_id,
                    'grantee_id' => $grant->user_id,
                ],
            ]);
        }

        /** @var Collection<int, PermissionGrant> $childGrants */
        $childGrants = PermissionGrant::query()
            ->where('parent_grant_id', $grant->id)
            ->lockForUpdate()
            ->get();

        foreach ($childGrants as $child) {
            $this->revokeRecursively($actor, $child, $reason, $visited);
        }
    }

    /**
     * Check delegation boundaries constraint.
     */
    private function assertDelegationBoundaries(User $grantor, User $grantee, string $orgId, string $targetScope): void
    {
        $now = now();
        $grantorRoleIds = DB::table('role_assignments')
            ->where('organization_id', $orgId)
            ->where('user_id', $grantor->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->pluck('role_id')
            ->all();

        $granteeRoleIds = DB::table('role_assignments')
            ->where('organization_id', $orgId)
            ->where('user_id', $grantee->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->pluck('role_id')
            ->all();

        if (empty($grantorRoleIds) || empty($granteeRoleIds)) {
            return;
        }

        $boundaries = DB::table('delegation_boundaries')
            ->where('organization_id', $orgId)
            ->whereIn('grantor_role_id', $grantorRoleIds)
            ->whereIn('grantee_role_id', $granteeRoleIds)
            ->get();

        foreach ($boundaries as $boundary) {
            if (AccessResolver::isScopeWider($targetScope, (string) $boundary->max_scope)) {
                throw new DelegationDeniedException(
                    "Delegation boundary exceeded: target scope '{$targetScope}' is wider than allowed maximum scope '{$boundary->max_scope}'."
                );
            }
        }
    }
}
