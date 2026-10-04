<?php

namespace Database\Seeders;

use App\Services\PresetImporter;
use Illuminate\Database\Seeder;

class PresetSeeder extends Seeder
{
    /** Preset files seeded on every `db:seed` (idempotent). Add new files/versions here. */
    private const FILES = ['presets/pk_general_v1.preset.json'];

    public function run(PresetImporter $importer): void
    {
        foreach (self::FILES as $relative) {
            $result = $importer->importFile(database_path($relative));

            $this->command?->info(sprintf(
                'Preset %s: %s (%d/%d policies created)',
                $result['preset'],
                $result['created'] ? 'imported' : 'already present',
                $result['policies_created'],
                $result['policies_total'],
            ));
        }
    }
}
