<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\SodRule;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

it('seeds access matrix permissions, system roles, and sod rules with exact counts', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Permission::count())->toBe(62);
    expect(Role::system()->count())->toBe(14);
    expect(Role::where('key', 'org_admin')->count())->toBe(1);
    expect(SodRule::system()->count())->toBe(6);
});

it('is idempotent and gives identical row counts when seeded twice', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $permCount1 = Permission::count();
    $roleCount1 = Role::count();
    $rpCount1 = RolePermission::count();
    $sodCount1 = SodRule::count();

    expect($permCount1)->toBe(62);
    expect($roleCount1)->toBe(14);
    expect($sodCount1)->toBe(6);

    // Second run
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Permission::count())->toBe($permCount1)
        ->and(Role::count())->toBe($roleCount1)
        ->and(RolePermission::count())->toBe($rpCount1)
        ->and(SodRule::count())->toBe($sodCount1);
});

it('ensures no sensitive role_permissions row has with_grant except for org_admin', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $sensitivePermissionIds = Permission::query()->where('is_sensitive', true)->pluck('id');
    expect($sensitivePermissionIds)->not->toBeEmpty();

    /** @var Role $orgAdmin */
    $orgAdmin = Role::query()->where('key', 'org_admin')->firstOrFail();

    $nonOrgAdminSensitiveWithGrant = RolePermission::query()
        ->whereIn('permission_id', $sensitivePermissionIds)
        ->where('with_grant', true)
        ->where('role_id', '!=', $orgAdmin->id)
        ->count();

    expect($nonOrgAdminSensitiveWithGrant)->toBe(0);

    // Confirm org_admin holds all 62 permissions with with_grant true at org scope
    $orgAdminGrants = RolePermission::query()
        ->where('role_id', $orgAdmin->id)
        ->where('max_scope', 'org')
        ->where('with_grant', true)
        ->count();

    expect($orgAdminGrants)->toBe(62);
});
