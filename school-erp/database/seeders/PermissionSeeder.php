<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use RuntimeException;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/access_matrix.json');
        if (! file_exists($path)) {
            throw new RuntimeException("Access matrix file not found at: {$path}");
        }

        /** @var array{permissions: list<array{code: string, module: string, is_sensitive: bool, is_delegable: bool}>} $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['permissions'] as $p) {
            $name = ucwords(str_replace(['.', '_'], ' ', $p['code']));

            Permission::query()->updateOrCreate(
                ['code' => $p['code']],
                [
                    'module' => $p['module'],
                    'name' => $name,
                    'is_sensitive' => $p['is_sensitive'],
                    'is_delegable' => $p['is_delegable'],
                    'allowed_scopes' => ['org', 'campus', 'program', 'grade', 'section', 'session'],
                    'depends_on' => [],
                ]
            );
        }
    }
}
