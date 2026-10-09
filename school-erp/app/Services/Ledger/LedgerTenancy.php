<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\User;

/** Shared organization tenancy check for ledger write and report entry points. */
final class LedgerTenancy
{
    /**
     * Ensures the actor belongs to the organization, or is a super admin.
     * A non-super-admin with a null organization_id is denied.
     *
     * @throws LedgerAccessDenied
     */
    public static function assertActorInOrganization(User $actor, string $organizationId): void
    {
        if (! $actor->is_super_admin && ($actor->organization_id === null || (string) $actor->organization_id !== $organizationId)) {
            throw new LedgerAccessDenied;
        }
    }
}
