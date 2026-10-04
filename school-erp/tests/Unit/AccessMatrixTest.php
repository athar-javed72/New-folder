<?php

declare(strict_types=1);

/**
 * @return array{
 *     _meta: array<string, mixed>,
 *     permissions: list<array{code: string, module: string, is_sensitive: bool, is_delegable: bool}>,
 *     roles: list<array{key: string, name: string, permissions: list<array{permission: string, scope: string, with_grant: bool}>}>,
 *     sod_rules: list<array{permission_a: string, permission_b: string, record_type: string}>
 * }
 */
function loadAccessMatrix(): array
{
    $path = __DIR__.'/../../database/data/access_matrix.json';
    if (! file_exists($path)) {
        throw new RuntimeException("Access matrix file not found at: {$path}");
    }

    /** @var array{_meta: array<string, mixed>, permissions: list<array{code: string, module: string, is_sensitive: bool, is_delegable: bool}>, roles: list<array{key: string, name: string, permissions: list<array{permission: string, scope: string, with_grant: bool}>}>, sod_rules: list<array{permission_a: string, permission_b: string, record_type: string}>} $data */
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $data;
}

it('ensures every permission code matches module.resource.action', function () {
    $matrix = loadAccessMatrix();

    expect($matrix['permissions'])->not->toBeEmpty();

    foreach ($matrix['permissions'] as $p) {
        expect($p['code'])->toMatch('/^[a-z_]+\.[a-z_]+\.[a-z_]+$/');
        $parts = explode('.', $p['code']);
        expect($parts)->toHaveCount(3);
        expect($parts[0])->toBe($p['module']);
    }
});

it('ensures permission codes are unique', function () {
    $matrix = loadAccessMatrix();
    $codes = array_column($matrix['permissions'], 'code');

    expect($codes)->toHaveCount(count(array_unique($codes)));
});

it('ensures every role permission references an existing code', function () {
    $matrix = loadAccessMatrix();
    $validCodes = array_flip(array_column($matrix['permissions'], 'code'));

    foreach ($matrix['roles'] as $role) {
        foreach ($role['permissions'] as $rp) {
            expect(isset($validCodes[$rp['permission']]))
                ->toBeTrue("Role '{$role['key']}' references non-existent permission '{$rp['permission']}'");
        }
    }
});

it('ensures scopes are only org, campus, assigned, or self', function () {
    $matrix = loadAccessMatrix();
    $allowedScopes = ['org', 'campus', 'assigned', 'self'];

    foreach ($matrix['roles'] as $role) {
        foreach ($role['permissions'] as $rp) {
            expect($allowedScopes)->toContain($rp['scope']);
        }
    }
});

it('ensures no sensitive permission has with_grant true', function () {
    $matrix = loadAccessMatrix();
    $sensitiveMap = [];
    foreach ($matrix['permissions'] as $p) {
        $sensitiveMap[$p['code']] = $p['is_sensitive'];
    }

    foreach ($matrix['roles'] as $role) {
        foreach ($role['permissions'] as $rp) {
            if ($sensitiveMap[$rp['permission']]) {
                expect($rp['with_grant'])
                    ->toBeFalse("Sensitive permission '{$rp['permission']}' in role '{$role['key']}' must not have with_grant true");
            }
        }
    }
});

it('ensures every sod_rules permission exists and permission_a < permission_b', function () {
    $matrix = loadAccessMatrix();
    $validCodes = array_flip(array_column($matrix['permissions'], 'code'));

    expect($matrix['sod_rules'])->not->toBeEmpty();

    foreach ($matrix['sod_rules'] as $rule) {
        expect(isset($validCodes[$rule['permission_a']]))
            ->toBeTrue("sod_rule references missing permission_a: {$rule['permission_a']}");
        expect(isset($validCodes[$rule['permission_b']]))
            ->toBeTrue("sod_rule references missing permission_b: {$rule['permission_b']}");

        expect(strcmp($rule['permission_a'], $rule['permission_b']))
            ->toBeLessThan(0, "sod_rule pair not strictly sorted: {$rule['permission_a']} must be < {$rule['permission_b']}");
    }
});

it('contains exactly the 13 expected role keys', function () {
    $matrix = loadAccessMatrix();
    $expectedRoles = [
        'campus_admin',
        'principal',
        'vice_principal',
        'academic_coordinator',
        'class_teacher',
        'subject_teacher',
        'admissions_officer',
        'accountant',
        'cashier',
        'hr_manager',
        'nurse_counsellor',
        'parent',
        'student',
    ];

    $actualRoles = array_column($matrix['roles'], 'key');

    sort($expectedRoles);
    sort($actualRoles);

    expect($actualRoles)->toBe($expectedRoles);
});

it('ensures principal with_grant rows are only in modules academics, attendance, exams, comms, reports', function () {
    $matrix = loadAccessMatrix();
    $allowedModules = ['academics', 'attendance', 'exams', 'comms', 'reports'];

    $principal = null;
    foreach ($matrix['roles'] as $role) {
        if ($role['key'] === 'principal') {
            $principal = $role;
            break;
        }
    }

    expect($principal)->not->toBeNull('Principal role not found in access matrix');
    assert($principal !== null);

    $hasGrantRows = false;
    foreach ($principal['permissions'] as $rp) {
        if ($rp['with_grant']) {
            $hasGrantRows = true;
            $module = explode('.', $rp['permission'])[0];
            expect($allowedModules)->toContain($module);
        }
    }

    expect($hasGrantRows)->toBeTrue('Principal role should have at least one with_grant permission');
});
