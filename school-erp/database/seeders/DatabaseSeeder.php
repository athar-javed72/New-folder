<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PresetSeeder::class,
            // Next: PermissionSeeder (catalog), RoleSeeder (system roles), DemoOrganizationSeeder
        ]);
    }
}
