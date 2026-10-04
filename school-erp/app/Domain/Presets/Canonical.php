<?php

declare(strict_types=1);

namespace App\Domain\Presets;

use stdClass;

/** Canonical JSON: object keys sorted, lists keep order, `{}` stays `{}`. Input must come from json_decode($json, false). */
final class Canonical
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), self::FLAGS);
    }

    public static function checksum(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $props = (array) $value;
            ksort($props, SORT_STRING);
            $out = new stdClass;
            foreach ($props as $k => $v) {
                $out->{(string) $k} = self::normalize($v);
            }

            return $out;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(static fn ($v) => self::normalize($v), $value);
            }
            ksort($value, SORT_STRING);
            $out = new stdClass;
            foreach ($value as $k => $v) {
                $out->{(string) $k} = self::normalize($v);
            }

            return $out;
        }

        return $value;
    }
}
