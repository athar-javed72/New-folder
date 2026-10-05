<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermissionGrant extends Model
{
    use HasUlids;

    protected $table = 'permission_grants';

    protected $fillable = [
        'organization_id',
        'user_id',
        'permission_id',
        'scope_type',
        'scope_id',
        'granted_by',
        'parent_grant_id',
        'with_grant',
        'starts_at',
        'ends_at',
        'status',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'with_grant' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<PermissionGrant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(function (Builder $q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    /**
     * @param  Builder<PermissionGrant>  $query
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * @return BelongsTo<PermissionGrant, $this>
     */
    public function parentGrant(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_grant_id');
    }

    /**
     * @return HasMany<PermissionGrant, $this>
     */
    public function childGrants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_grant_id');
    }
}
