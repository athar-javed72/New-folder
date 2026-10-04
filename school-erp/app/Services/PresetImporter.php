<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Presets\PresetMapper;
use App\Exceptions\PresetChecksumMismatch;
use App\Models\Preset;
use App\Models\SystemPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent, immutable-per-version preset import.
 *  same key+version, same content    -> no-op (safe to re-run `db:seed`)
 *  same key+version, changed content -> PresetChecksumMismatch (bump the version in the JSON)
 *  new version                       -> new rows; older versions stay for orgs pinned to them
 */
final class PresetImporter
{
    /** @return array{preset:string, created:bool, policies_created:int, policies_total:int} */
    public function importFile(string $path): array
    {
        $mapped = PresetMapper::fromFile($path);

        return DB::transaction(function () use ($mapped): array {
            $p = $mapped['preset'];

            $preset = Preset::query()->where('key', $p['key'])->where('version', $p['version'])->lockForUpdate()->first();

            $created = false;
            if ($preset === null) {
                $preset = new Preset;
                $preset->fill([
                    'key' => $p['key'], 'version' => $p['version'], 'name' => $p['name'],
                    'payload' => $p['payload_json'],       // pre-encoded: `{}` stays `{}`
                    'checksum' => $p['checksum'], 'status' => 'active',
                ])->save();
                $created = true;
            } elseif ($preset->checksum !== $p['checksum']) {
                throw PresetChecksumMismatch::for("{$p['key']}@{$p['version']}", $preset->checksum, $p['checksum']);
            }

            $policiesCreated = 0;
            foreach ($mapped['policies'] as $row) {
                $policy = SystemPolicy::query()
                    ->where('preset_id', $preset->id)->where('type', $row['type'])->where('key', $row['key'])->first();

                if ($policy !== null) {
                    if ($policy->checksum !== $row['checksum']) {
                        throw PresetChecksumMismatch::for("{$row['type']}/{$row['key']}", $policy->checksum, $row['checksum']);
                    }

                    continue;
                }

                (new SystemPolicy)->fill([
                    'preset_id' => $preset->id, 'type' => $row['type'], 'key' => $row['key'],
                    'value' => $row['value_json'], 'override_levels' => $row['override_levels_json'],
                    'editable_by' => $row['editable_by_json'], 'schema_version' => 1, 'checksum' => $row['checksum'],
                ])->save();
                $policiesCreated++;
            }

            return [
                'preset' => $preset->key.'@'.$preset->version,
                'created' => $created,
                'policies_created' => $policiesCreated,
                'policies_total' => count($mapped['policies']),
            ];
        });
    }
}
