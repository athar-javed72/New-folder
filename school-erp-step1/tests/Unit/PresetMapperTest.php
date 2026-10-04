<?php

declare(strict_types=1);

use App\Domain\Presets\Canonical;
use App\Domain\Presets\PresetMapper;
use Tests\Support\PresetFixture;

function mappedPreset(): array
{
    return PresetMapper::fromFile(PresetFixture::path());
}

function presetPayload(?array $policies = null, string $version = '1.5'): stdClass
{
    $policies ??= [['type' => 'late_fee', 'key' => 'default', 'value' => ['a' => 1]]];

    return json_decode(json_encode(['preset' => 'T', 'version' => $version, 'policies' => $policies]), false);
}

it('maps 17 policies and version 1.5 from the real preset file', function () {
    $m = mappedPreset();
    expect($m['preset']['key'])->toBe('PK_GENERAL_V1');
    expect($m['preset']['version'])->toBe('1.5');
    expect($m['policies'])->toHaveCount(17);
});

it('contains every expected policy type', function () {
    $types = array_column(mappedPreset()['policies'], 'type');
    foreach (['late_fee', 'discount_stacking', 'discount_definitions', 'grading_profile', 'leave_policy', 'absence_alert', 'transfer_finance', 'refund_policy', 'promotion', 'session_generation', 'attendance_policy', 'daily_status_derivation', 'substitution_policy', 'contract_types', 'payroll_deduction', 'automation_templates'] as $t) {
        expect($types)->toContain($t);
    }
});

it('has unique type/key pairs', function () {
    $ids = array_map(fn ($p) => $p['type'] . '/' . $p['key'], mappedPreset()['policies']);
    expect(array_unique($ids))->toHaveCount(count($ids));
});

it('preserves empty JSON objects (component_min_percent stays {})', function () {
    $grading = null;
    foreach (mappedPreset()['policies'] as $p) {
        if ($p['type'] === 'grading_profile') {
            $grading = $p['value_json'];
        }
    }
    expect($grading)->toContain('"component_min_percent":{}');
});

it('checksum is stable and changes when content is tampered with', function () {
    $a = mappedPreset();
    $b = mappedPreset();
    expect($a['preset']['checksum'])->toBe($b['preset']['checksum']);

    $tampered = json_decode((string) file_get_contents(PresetFixture::path()), false);
    $tampered->policies[0]->value = (object) ['tampered' => true];
    expect(PresetMapper::map($tampered)['preset']['checksum'])->not->toBe($a['preset']['checksum']);
});

it('checksum ignores key order (canonical JSON)', function () {
    expect(Canonical::checksum(['b' => 1, 'a' => 2]))->toBe(Canonical::checksum(['a' => 2, 'b' => 1]));
});

it('rejects duplicate, empty and badly versioned presets', function () {
    $dup = [['type' => 'x', 'key' => 'k', 'value' => 1], ['type' => 'x', 'key' => 'k', 'value' => 2]];
    expect(fn () => PresetMapper::map(presetPayload($dup)))->toThrow(InvalidArgumentException::class, 'Duplicate');
    expect(fn () => PresetMapper::map(presetPayload([])))->toThrow(InvalidArgumentException::class);
    expect(fn () => PresetMapper::map(presetPayload(null, 'v1')))->toThrow(InvalidArgumentException::class, 'Invalid preset version');
});
