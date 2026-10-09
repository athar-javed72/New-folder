<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;

/** Shared helper for attaching a system role to a user in ledger feature tests. */
final class RoleAssignments
{
    public static function assign(User $user, string $roleKey, string $scopeType = 'org', ?string $scopeId = null): void
    {
        /** @var Role $role */
        $role = Role::query()->where('key', $roleKey)->where('is_system', true)->firstOrFail();

        RoleAssignment::query()->create([
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
}
