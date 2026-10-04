<?php

declare(strict_types=1);

namespace App\Domain\Presets;

use InvalidArgumentException;
use stdClass;

/** Pure mapping: preset JSON file -> rows for `presets` and `system_policies`. No framework or DB dependency. */
final class PresetMapper
{
    public static function fromFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException("Preset file not readable: {$path}");
        }
        // assoc=false keeps empty objects as stdClass so `{}` is not turned into `[]`
        $payload = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        if (! $payload instanceof stdClass) {
            throw new InvalidArgumentException('Preset root must be a JSON object.');
        }

        return self::map($payload);
    }

    /**
     * @return array{
     *   preset: array{key:string,version:string,name:string,checksum:string,payload_json:string},
     *   policies: list<array{type:string,key:string,value_json:string,override_levels_json:string,editable_by_json:string,checksum:string}>
     * }
     */
    public static function map(stdClass $payload): array
    {
        $key = self::requireString($payload, 'preset');
        $version = self::requireString($payload, 'version');
        if (! preg_match('/^\d+(\.\d+)*$/', $version)) {
            throw new InvalidArgumentException("Invalid preset version '{$version}'.");
        }
        $policies = $payload->policies ?? null;
        if (! is_array($policies) || $policies === []) {
            throw new InvalidArgumentException('Preset must contain a non-empty "policies" list.');
        }

        $rows = [];
        $seen = [];
        foreach ($policies as $i => $policy) {
            if (! $policy instanceof stdClass) {
                throw new InvalidArgumentException("policies[{$i}] must be an object.");
            }
            $type = self::requireString($policy, 'type');
            $pkey = self::requireString($policy, 'key');
            if (! property_exists($policy, 'value')) {
                throw new InvalidArgumentException("policies[{$i}] ({$type}/{$pkey}) has no value.");
            }
            $id = $type . '/' . $pkey;
            if (isset($seen[$id])) {
                throw new InvalidArgumentException("Duplicate policy {$id} in preset.");
            }
            $seen[$id] = true;

            $rows[] = [
                'type' => $type,
                'key' => $pkey,
                'value_json' => Canonical::encode($policy->value),
                'override_levels_json' => Canonical::encode($policy->override_levels ?? []),
                'editable_by_json' => Canonical::encode($policy->editable_by ?? []),
                'checksum' => Canonical::checksum($policy),
            ];
        }

        return [
            'preset' => [
                'key' => $key,
                'version' => $version,
                'name' => (string) ($payload->name ?? $key),
                'checksum' => Canonical::checksum($payload),
                'payload_json' => Canonical::encode($payload),
            ],
            'policies' => $rows,
        ];
    }

    private static function requireString(stdClass $o, string $prop): string
    {
        $v = $o->{$prop} ?? null;
        if (! is_string($v) || $v === '') {
            throw new InvalidArgumentException("Missing or invalid string property '{$prop}'.");
        }

        return $v;
    }
}
