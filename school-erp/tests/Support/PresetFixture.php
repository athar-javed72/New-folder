<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Reads policies straight from the preset JSON so unit tests never depend on a database. */
final class PresetFixture
{
    public static function path(): string
    {
        return __DIR__.'/../../database/presets/pk_general_v1.preset.json';
    }

    public static function policy(string $type, string $key = 'default'): array
    {
        $data = json_decode((string) file_get_contents(self::path()), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['policies'] as $p) {
            if ($p['type'] === $type && $p['key'] === $key) {
                return $p['value'];
            }
        }
        throw new RuntimeException("Policy {$type}/{$key} not found in preset.");
    }
}
