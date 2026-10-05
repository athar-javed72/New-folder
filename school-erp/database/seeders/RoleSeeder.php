<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\SodRule;
use Illuminate\Database\Seeder;
use RuntimeException;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/access_matrix.json');
        if (! file_exists($path)) {
            throw new RuntimeException("Access matrix file not found at: {$path}");
        }

        /**
         * @var array{
         *     roles: list<array{key: string, name: string, permissions: list<array{permission: string, scope: string, with_grant: bool}>}>,
         *     sod_rules: list<array{permission_a: string, permission_b: string, record_type: string}>
         * } $data
         */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, string> $permissionMap [code => id] */
        $permissionMap = Permission::query()->pluck('id', 'code')->all();

        // 1. Seed the 13 system roles from access_matrix.json
        foreach ($data['roles'] as $roleData) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                [
                    'organization_id' => null,
                    'campus_id' => null,
                    'key' => $roleData['key'],
                ],
                [
                    'name' => $roleData['name'],
                    'is_system' => true,
                    'is_editable' => true,
                ]
            );

            foreach ($roleData['permissions'] as $rp) {
                if (! isset($permissionMap[$rp['permission']])) {
                    continue;
                }

                $maxScope = match ($rp['scope']) {
                    'org' => 'org',
                    'campus' => 'campus',
                    'assigned', 'self' => 'section',
                    default => $rp['scope'],
                };

                RolePermission::query()->updateOrCreate(
                    [
                        'role_id' => $role->id,
                        'permission_id' => $permissionMap[$rp['permission']],
                    ],
                    [
                        'max_scope' => $maxScope,
                        'with_grant' => $rp['with_grant'],
                    ]
                );
            }
        }

        // 2. Seed org_admin system role: holds every permission at 'org' with with_grant true
        /** @var Role $orgAdmin */
        $orgAdmin = Role::query()->updateOrCreate(
            [
                'organization_id' => null,
                'campus_id' => null,
                'key' => 'org_admin',
            ],
            [
                'name' => 'Organization Administrator',
                'is_system' => true,
                'is_editable' => false,
            ]
        );

        foreach ($permissionMap as $permissionId) {
            RolePermission::query()->updateOrCreate(
                [
                    'role_id' => $orgAdmin->id,
                    'permission_id' => $permissionId,
                ],
                [
                    'max_scope' => 'org',
                    'with_grant' => true,
                ]
            );
        }

        // 3. Seed system SoD rules from access_matrix.json
        foreach ($data['sod_rules'] as $rule) {
            SodRule::query()->updateOrCreate(
                [
                    'organization_id' => null,
                    'permission_a' => $rule['permission_a'],
                    'permission_b' => $rule['permission_b'],
                    'record_type' => $rule['record_type'],
                ],
                [
                    'is_active' => true,
                ]
            );
        }
    }
}
