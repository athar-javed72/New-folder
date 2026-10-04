<?php

declare(strict_types=1);

use App\Exceptions\PresetChecksumMismatch;
use App\Models\Preset;
use App\Models\SystemPolicy;
use App\Services\PresetImporter;

it('first import creates 17 policies', function () {
    $importer = app(PresetImporter::class);
    $path = database_path('presets/pk_general_v1.preset.json');

    $result = $importer->importFile($path);

    expect($result['created'])->toBeTrue()
        ->and($result['policies_created'])->toBe(17)
        ->and($result['policies_total'])->toBe(17);

    expect(Preset::query()->where('key', 'PK_GENERAL_V1')->where('version', '1.5')->count())->toBe(1);
    expect(SystemPolicy::count())->toBe(17);
});

it('second import creates 0', function () {
    $importer = app(PresetImporter::class);
    $path = database_path('presets/pk_general_v1.preset.json');

    // First import
    $importer->importFile($path);
    expect(SystemPolicy::count())->toBe(17);

    // Second import (idempotent no-op)
    $result = $importer->importFile($path);

    expect($result['created'])->toBeFalse()
        ->and($result['policies_created'])->toBe(0)
        ->and($result['policies_total'])->toBe(17);

    expect(Preset::count())->toBe(1);
    expect(SystemPolicy::count())->toBe(17);
});

it('same version with changed content raises PresetChecksumMismatch', function () {
    $importer = app(PresetImporter::class);
    $path = database_path('presets/pk_general_v1.preset.json');

    // First valid import
    $importer->importFile($path);

    // Create a modified copy with the same key and version but altered content
    $content = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $content['notes'] = 'Tampered content for mismatch test';
    $content['policies'][0]['value'] = ['tampered' => true];

    $tmp = tempnam(sys_get_temp_dir(), 'preset_test_');
    try {
        file_put_contents($tmp, json_encode($content, JSON_THROW_ON_ERROR));

        expect(fn () => $importer->importFile($tmp))
            ->toThrow(PresetChecksumMismatch::class);
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});
