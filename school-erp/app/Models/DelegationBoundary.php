<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DelegationBoundary extends Model
{
    use HasUlids;

    protected $table = 'delegation_boundaries';

    protected $fillable = [
        'organization_id',
        'grantor_role_id',
        'grantee_role_id',
        'max_scope',
        'requires_approval',
        'rules',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'rules' => 'array',
        ];
    }

    /**
     * @param  Builder<DelegationBoundary>  $query
     */
    public function scopeForOrganization(Builder $query, string $organizationId): void
    {
        $query->where('organization_id', $organizationId);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function grantorRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'grantor_role_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function granteeRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'grantee_role_id');
    }
}
