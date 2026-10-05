<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasUlids;

    protected $table = 'roles';

    protected $fillable = [
        'organization_id',
        'campus_id',
        'key',
        'name',
        'is_system',
        'is_editable',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_editable' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Role>  $query
     */
    public function scopeSystem(Builder $query): void
    {
        $query->whereNull('organization_id')->where('is_system', true);
    }

    /**
     * @param  Builder<Role>  $query
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
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsToMany<Permission, $this, RolePermission>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
            ->using(RolePermission::class)
            ->withPivot(['max_scope', 'with_grant'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id');
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'role_id');
    }
}
