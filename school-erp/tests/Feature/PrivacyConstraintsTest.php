<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

/**
 * Helpers for creating plain DB rows bypassing Eloquent.
 */
function createPrivacyStudent(string $org, array $over = []): string
{
    $family = F::id();
    DB::table('families')->insert([
        'id' => $family,
        'organization_id' => $org,
        'family_code' => 'F'.substr($family, -8),
        'display_name' => 'Test Family',
    ]);

    $id = F::id();
    DB::table('students')->insert($over + [
        'id' => $id,
        'organization_id' => $org,
        'family_id' => $family,
        'registration_number' => 'R'.substr($id, -8),
        'first_name' => 'Ali',
        'last_name' => 'Khan',
    ]);

    return $id;
}

function createPrivacyGuardian(string $org, array $over = []): string
{
    $family = F::id();
    DB::table('families')->insert([
        'id' => $family,
        'organization_id' => $org,
        'family_code' => 'F'.substr($family, -8),
        'display_name' => 'Test Family',
    ]);

    $id = F::id();
    DB::table('guardians')->insert($over + [
        'id' => $id,
        'organization_id' => $org,
        'family_id' => $family,
        'relation' => 'father',
        'full_name' => 'Tariq Khan',
    ]);

    return $id;
}

function createPrivacyEmployee(string $org, array $over = []): string
{
    $id = F::id();
    DB::table('employees')->insert($over + [
        'id' => $id,
        'organization_id' => $org,
        'employee_code' => 'E'.substr($id, -8),
        'full_name' => 'Sara Ahmed',
    ]);

    return $id;
}

// ---------- students.medical removal & alert flags ----------

it('confirms the students.medical column is gone from information_schema', function () {
    $exists = DB::table('information_schema.columns')
        ->where('table_name', 'students')
        ->where('column_name', 'medical')
        ->exists();

    expect($exists)->toBeFalse();
});

it('defaults has_medical_alert and has_severe_allergy to false on students', function () {
    $org = F::org();
    $studentId = createPrivacyStudent($org);

    $row = DB::table('students')->where('id', $studentId)->first();
    expect($row)->not->toBeNull()
        ->and($row->has_medical_alert)->toBeFalse()
        ->and($row->has_severe_allergy)->toBeFalse();
});

it('allows student insert without any privacy columns', function () {
    $org = F::org();
    $studentId = createPrivacyStudent($org);

    $row = DB::table('students')->where('id', $studentId)->first();
    expect($row)->not->toBeNull()
        ->and($row->b_form_encrypted)->toBeNull()
        ->and($row->b_form_hash)->toBeNull()
        ->and($row->passport_encrypted)->toBeNull()
        ->and($row->passport_hash)->toBeNull();
});

// ---------- b_form_hash constraints ----------

it('rejects duplicate b_form_hash in the same organization', function () {
    $org = F::org();
    $fakeHash = str_repeat('a', 64);
    createPrivacyStudent($org, ['b_form_hash' => $fakeHash]);

    $e = F::exception(fn () => createPrivacyStudent($org, ['b_form_hash' => $fakeHash]));
    expect($e)->not->toBeNull()
        ->and($e->getMessage())->toContain('students_b_form_hash_uq');
});

it('allows the same b_form_hash in another organization', function () {
    $orgA = F::org();
    $orgB = F::org();
    $fakeHash = str_repeat('a', 64);

    $sA = createPrivacyStudent($orgA, ['b_form_hash' => $fakeHash]);
    $sB = createPrivacyStudent($orgB, ['b_form_hash' => $fakeHash]);

    expect($sA)->toBeString()
        ->and($sB)->toBeString();
});

it('allows many NULL b_form_hash values in the same organization', function () {
    $org = F::org();
    createPrivacyStudent($org, ['b_form_hash' => null]);
    createPrivacyStudent($org, ['b_form_hash' => null]);
    createPrivacyStudent($org, ['b_form_hash' => null]);

    expect(DB::table('students')->where('organization_id', $org)->whereNull('b_form_hash')->count())->toBe(3);
});

it('frees the b_form_hash when a student is soft-deleted so a second live student can reuse it', function () {
    $org = F::org();
    $fakeHash = str_repeat('a', 64);

    $s1 = createPrivacyStudent($org, ['b_form_hash' => $fakeHash]);
    DB::table('students')->where('id', $s1)->update(['deleted_at' => now()]);

    $s2 = createPrivacyStudent($org, ['b_form_hash' => $fakeHash]);
    expect($s2)->toBeString();
});

// ---------- passport_hash constraints ----------

it('rejects duplicate passport_hash in the same organization', function () {
    $org = F::org();
    $fakeHash = str_repeat('b', 64);
    createPrivacyStudent($org, ['passport_hash' => $fakeHash]);

    $e = F::exception(fn () => createPrivacyStudent($org, ['passport_hash' => $fakeHash]));
    expect($e)->not->toBeNull()
        ->and($e->getMessage())->toContain('students_passport_hash_uq');
});

it('allows the same passport_hash in another organization', function () {
    $orgA = F::org();
    $orgB = F::org();
    $fakeHash = str_repeat('b', 64);

    $sA = createPrivacyStudent($orgA, ['passport_hash' => $fakeHash]);
    $sB = createPrivacyStudent($orgB, ['passport_hash' => $fakeHash]);

    expect($sA)->toBeString()
        ->and($sB)->toBeString();
});

it('allows many NULL passport_hash values in the same organization', function () {
    $org = F::org();
    createPrivacyStudent($org, ['passport_hash' => null]);
    createPrivacyStudent($org, ['passport_hash' => null]);

    expect(DB::table('students')->where('organization_id', $org)->whereNull('passport_hash')->count())->toBe(2);
});

it('frees the passport_hash when a student is soft-deleted so a second live student can reuse it', function () {
    $org = F::org();
    $fakeHash = str_repeat('b', 64);

    $s1 = createPrivacyStudent($org, ['passport_hash' => $fakeHash]);
    DB::table('students')->where('id', $s1)->update(['deleted_at' => now()]);

    $s2 = createPrivacyStudent($org, ['passport_hash' => $fakeHash]);
    expect($s2)->toBeString();
});

// ---------- format validation (check constraints) ----------

it('rejects malformed hashes that are uppercase, too short, or non-hex', function () {
    $org = F::org();

    // Uppercase on b_form_hash
    $e1 = F::exception(fn () => createPrivacyStudent($org, ['b_form_hash' => str_repeat('A', 64)]));
    expect($e1)->not->toBeNull()
        ->and($e1->getMessage())->toContain('students_b_form_hash_chk');

    // Too short on passport_hash (63 chars)
    $e2 = F::exception(fn () => createPrivacyStudent($org, ['passport_hash' => str_repeat('b', 63)]));
    expect($e2)->not->toBeNull()
        ->and($e2->getMessage())->toContain('students_passport_hash_chk');

    // Non-hex characters on guardian national_id_hash
    $e3 = F::exception(fn () => createPrivacyGuardian($org, ['national_id_hash' => str_repeat('z', 64)]));
    expect($e3)->not->toBeNull()
        ->and($e3->getMessage())->toContain('guardians_national_id_hash_chk');

    // Uppercase on employee national_id_hash
    $e4 = F::exception(fn () => createPrivacyEmployee($org, ['national_id_hash' => str_repeat('F', 64)]));
    expect($e4)->not->toBeNull()
        ->and($e4->getMessage())->toContain('employees_national_id_hash_chk');
});

// ---------- guardian & employee duplicate hash allowance ----------

it('allows duplicate national_id_hash for guardians in the same organization', function () {
    $org = F::org();
    $fakeHash = str_repeat('c', 64);

    $g1 = createPrivacyGuardian($org, ['national_id_hash' => $fakeHash]);
    $g2 = createPrivacyGuardian($org, ['national_id_hash' => $fakeHash]);

    expect($g1)->toBeString()
        ->and($g2)->toBeString()
        ->and(DB::table('guardians')->where('organization_id', $org)->where('national_id_hash', $fakeHash)->count())->toBe(2);
});

it('allows duplicate national_id_hash for employees in the same organization', function () {
    $org = F::org();
    $fakeHash = str_repeat('d', 64);

    $e1 = createPrivacyEmployee($org, ['national_id_hash' => $fakeHash]);
    $e2 = createPrivacyEmployee($org, ['national_id_hash' => $fakeHash]);

    expect($e1)->toBeString()
        ->and($e2)->toBeString()
        ->and(DB::table('employees')->where('organization_id', $org)->where('national_id_hash', $fakeHash)->count())->toBe(2);
});

// ---------- medical & custody profiles constraints ----------

it('rejects a second medical profile for the same student', function () {
    $org = F::org();
    $studentId = createPrivacyStudent($org);

    DB::table('student_medical_profiles')->insert([
        'id' => F::id(),
        'organization_id' => $org,
        'student_id' => $studentId,
        'allergies' => 'ciphertext-placeholder',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $e = F::exception(fn () => DB::table('student_medical_profiles')->insert([
        'id' => F::id(),
        'organization_id' => $org,
        'student_id' => $studentId,
        'allergies' => 'ciphertext-placeholder-2',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($e)->not->toBeNull()
        ->and($e->getMessage())->toContain('student_medical_profiles_organization_id_student_id_unique');
});

it('rejects a medical profile for a student of another organization', function () {
    $orgA = F::org();
    $orgB = F::org();
    $studentA = createPrivacyStudent($orgA);

    $e = F::exception(fn () => DB::table('student_medical_profiles')->insert([
        'id' => F::id(),
        'organization_id' => $orgB,
        'student_id' => $studentA,
        'allergies' => 'ciphertext-placeholder',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($e)->not->toBeNull()
        ->and($e->getCode())->toBe('23503');
});

it('rejects a custody order for a student of another organization', function () {
    $orgA = F::org();
    $orgB = F::org();
    $studentA = createPrivacyStudent($orgA);

    $e = F::exception(fn () => DB::table('student_custody_orders')->insert([
        'id' => F::id(),
        'organization_id' => $orgB,
        'student_id' => $studentA,
        'details' => 'ciphertext-placeholder',
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    expect($e)->not->toBeNull()
        ->and($e->getCode())->toBe('23503');
});
