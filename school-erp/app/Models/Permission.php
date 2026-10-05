<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    use HasUlids;

    protected $table = 'permissions';

    protected $fillable = [
        'code',
        'module',
        'name',
        'is_sensitive',
        'is_delegable',
        'allowed_scopes',
        'depends_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_sensitive' => 'boolean',
            'is_delegable' => 'boolean',
            'allowed_scopes' => 'array',
            'depends_on' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this, RolePermission>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id')
            ->using(RolePermission::class)
            ->withPivot(['max_scope', 'with_grant'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'permission_id');
    }

    /**
     * @return HasMany<PermissionGrant, $this>
     */
    public function permissionGrants(): HasMany
    {
        return $this->hasMany(PermissionGrant::class, 'permission_id');
    }
}
