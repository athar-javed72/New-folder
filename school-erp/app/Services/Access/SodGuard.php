<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\AuditLog;
use App\Models\SodRule;
use App\Models\User;

class SodGuard
{
    /**
     * Assert that the actor is allowed to execute the permission on the specified record
     * without violating separation of duties.
     *
     * @throws SeparationOfDutiesViolation
     */
    public static function assertAllowed(
        User $actor,
        string $permission,
        string $recordType,
        string $recordId,
        ?string $organizationId = null,
    ): void {
        $effectiveOrgId = $organizationId ?? $actor->organization_id;

        // 1. Look up active rules matching record_type and actor's org (or system rules)
        $rules = SodRule::query()
            ->where('is_active', true)
            ->where('record_type', $recordType)
            ->where(function ($query) use ($effectiveOrgId) {
                $query->whereNull('organization_id');
                if ($effectiveOrgId !== null) {
                    $query->orWhere('organization_id', $effectiveOrgId);
                }
            })
            ->where(function ($query) use ($permission) {
                $query->where('permission_a', $permission)
                    ->orWhere('permission_b', $permission);
            })
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        // 2. For each rule, determine paired permission and check audit_logs
        foreach ($rules as $rule) {
            $pairedPermission = $rule->permission_a === $permission
                ? $rule->permission_b
                : $rule->permission_a;

            $auditQuery = AuditLog::query()
                ->where('actor_id', $actor->id)
                ->where('action', $pairedPermission)
                ->where('subject_type', $recordType)
                ->where('subject_id', $recordId);

            if ($effectiveOrgId !== null) {
                $auditQuery->where('organization_id', $effectiveOrgId);
            }

            if ($auditQuery->exists()) {
                throw new SeparationOfDutiesViolation(
                    "Separation of duties violation: user '{$actor->id}' cannot perform '{$permission}' because they already performed paired permission '{$pairedPermission}' on {$recordType} '{$recordId}'."
                );
            }
        }
    }
}
