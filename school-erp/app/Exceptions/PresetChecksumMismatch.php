<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PresetChecksumMismatch extends RuntimeException
{
    public static function for(string $what, string $expected, string $actual): self
    {
        return new self(
            "Preset content changed without a version bump ({$what}). Stored checksum {$expected}, file checksum {$actual}. "
            .'Presets are immutable per version: bump "version" in the JSON file.'
        );
    }
}
