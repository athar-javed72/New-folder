<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Family;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentCustodyOrder;
use App\Models\StudentMedicalProfile;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

function createTestFamily(string $orgId): Family
{
    $family = new Family;
    $family->id = F::id();
    $family->organization_id = $orgId;
    $family->family_code = 'F'.substr($family->id, -8);
    $family->display_name = 'Test Family';
    $family->save();

    return $family;
}

it('ensures raw database columns are encrypted and never equal plain values', function () {
    $orgId = F::org();
    $family = createTestFamily($orgId);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'R'.substr($student->id, -8);
    $student->first_name = 'Ali';
    $student->setBForm('11111-1111111-1');
    $student->setPassport('A11111111');
    $student->save();

    $rawStudent = DB::table('students')->where('id', $student->id)->first();
    expect($rawStudent)->not->toBeNull()
        ->and($rawStudent->b_form_encrypted)->not->toBe('11111-1111111-1')
        ->and($rawStudent->b_form_encrypted)->not->toContain('11111-1111111-1')
        ->and($rawStudent->passport_encrypted)->not->toBe('A11111111')
        ->and($rawStudent->passport_encrypted)->not->toContain('A11111111')
        ->and($rawStudent->b_form_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($rawStudent->passport_hash)->toMatch('/^[0-9a-f]{64}$/');

    $guardian = new Guardian;
    $guardian->id = F::id();
    $guardian->organization_id = $orgId;
    $guardian->family_id = $family->id;
    $guardian->relation = 'father';
    $guardian->full_name = 'Tariq Khan';
    $guardian->setNationalId('22222-2222222-2');
    $guardian->save();

    $rawGuardian = DB::table('guardians')->where('id', $guardian->id)->first();
    expect($rawGuardian)->not->toBeNull()
        ->and($rawGuardian->national_id_encrypted)->not->toBe('22222-2222222-2')
        ->and($rawGuardian->national_id_encrypted)->not->toContain('22222-2222222-2')
        ->and($rawGuardian->national_id_hash)->toMatch('/^[0-9a-f]{64}$/');

    $employee = new Employee;
    $employee->id = F::id();
    $employee->organization_id = $orgId;
    $employee->employee_code = 'E'.substr($employee->id, -8);
    $employee->full_name = 'Sara Ahmed';
    $employee->setNationalId('33333-3333333-3');
    $employee->save();

    $rawEmployee = DB::table('employees')->where('id', $employee->id)->first();
    expect($rawEmployee)->not->toBeNull()
        ->and($rawEmployee->national_id_encrypted)->not->toBe('33333-3333333-3')
        ->and($rawEmployee->national_id_encrypted)->not->toContain('33333-3333333-3')
        ->and($rawEmployee->national_id_hash)->toMatch('/^[0-9a-f]{64}$/');

    $medical = StudentMedicalProfile::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'allergies' => 'Peanut allergy test note',
        'conditions' => 'Asthma condition test note',
        'medications' => 'Inhaler medication test note',
        'doctor_notes' => 'Dr Smith clinic notes',
    ]);

    $rawMedical = DB::table('student_medical_profiles')->where('id', $medical->id)->first();
    expect($rawMedical)->not->toBeNull()
        ->and($rawMedical->allergies)->not->toBe('Peanut allergy test note')
        ->and($rawMedical->allergies)->not->toContain('Peanut allergy test note')
        ->and($rawMedical->conditions)->not->toContain('Asthma condition test note')
        ->and($rawMedical->medications)->not->toContain('Inhaler medication test note')
        ->and($rawMedical->doctor_notes)->not->toContain('Dr Smith clinic notes');

    $custody = StudentCustodyOrder::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'details' => 'Family court sole custody order details',
    ]);

    $rawCustody = DB::table('student_custody_orders')->where('id', $custody->id)->first();
    expect($rawCustody)->not->toBeNull()
        ->and($rawCustody->details)->not->toBe('Family court sole custody order details')
        ->and($rawCustody->details)->not->toContain('Family court sole custody order details');
});

it('ensures round trip retrieval returns the exact plain values', function () {
    $orgId = F::org();
    $family = createTestFamily($orgId);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'R'.substr($student->id, -8);
    $student->first_name = 'Ali';
    $student->setBForm('11111-1111111-1');
    $student->setPassport('A11111111');
    $student->save();

    $freshStudent = Student::findOrFail($student->id);
    expect($freshStudent->b_form_encrypted)->toBe('11111-1111111-1')
        ->and($freshStudent->passport_encrypted)->toBe('A11111111');

    $guardian = new Guardian;
    $guardian->id = F::id();
    $guardian->organization_id = $orgId;
    $guardian->family_id = $family->id;
    $guardian->relation = 'father';
    $guardian->full_name = 'Tariq Khan';
    $guardian->setNationalId('22222-2222222-2');
    $guardian->save();

    $freshGuardian = Guardian::findOrFail($guardian->id);
    expect($freshGuardian->national_id_encrypted)->toBe('22222-2222222-2');

    $employee = new Employee;
    $employee->id = F::id();
    $employee->organization_id = $orgId;
    $employee->employee_code = 'E'.substr($employee->id, -8);
    $employee->full_name = 'Sara Ahmed';
    $employee->setNationalId('33333-3333333-3');
    $employee->save();

    $freshEmployee = Employee::findOrFail($employee->id);
    expect($freshEmployee->national_id_encrypted)->toBe('33333-3333333-3');

    $medical = StudentMedicalProfile::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'allergies' => 'Peanut allergy test note',
        'conditions' => 'Asthma condition test note',
        'medications' => 'Inhaler medication test note',
        'doctor_notes' => 'Dr Smith clinic notes',
    ]);

    $freshMedical = StudentMedicalProfile::findOrFail($medical->id);
    expect($freshMedical->allergies)->toBe('Peanut allergy test note')
        ->and($freshMedical->conditions)->toBe('Asthma condition test note')
        ->and($freshMedical->medications)->toBe('Inhaler medication test note')
        ->and($freshMedical->doctor_notes)->toBe('Dr Smith clinic notes');

    $custody = StudentCustodyOrder::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'details' => 'Family court sole custody order details',
    ]);

    $freshCustody = StudentCustodyOrder::findOrFail($custody->id);
    expect($freshCustody->details)->toBe('Family court sole custody order details');
});

it('finds the same row when looking up by differently formatted identifiers', function () {
    $orgId = F::org();
    $family = createTestFamily($orgId);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'R'.substr($student->id, -8);
    $student->first_name = 'Ali';
    $student->setBForm('11111-1111111-1');
    $student->setPassport('A11111111');
    $student->save();

    $guardian = new Guardian;
    $guardian->id = F::id();
    $guardian->organization_id = $orgId;
    $guardian->family_id = $family->id;
    $guardian->relation = 'father';
    $guardian->full_name = 'Tariq Khan';
    $guardian->setNationalId('22222-2222222-2');
    $guardian->save();

    $employee = new Employee;
    $employee->id = F::id();
    $employee->organization_id = $orgId;
    $employee->employee_code = 'E'.substr($employee->id, -8);
    $employee->full_name = 'Sara Ahmed';
    $employee->setNationalId('33333-3333333-3');
    $employee->save();

    // Look up with spaces
    $foundStudentBForm = Student::whereBForm($orgId, '11111 1111111 1')->first();
    expect($foundStudentBForm)->not->toBeNull()
        ->and($foundStudentBForm->id)->toBe($student->id);

    // Look up with lowercase passport
    $foundStudentPassport = Student::wherePassport($orgId, 'a11111111')->first();
    expect($foundStudentPassport)->not->toBeNull()
        ->and($foundStudentPassport->id)->toBe($student->id);

    // Look up digits only
    $foundGuardian = Guardian::whereNationalId($orgId, '2222222222222')->first();
    expect($foundGuardian)->not->toBeNull()
        ->and($foundGuardian->id)->toBe($guardian->id);

    // Look up with spaces & mixed dashes
    $foundEmployee = Employee::whereNationalId($orgId, ' 33333 - 3333333 - 3 ')->first();
    expect($foundEmployee)->not->toBeNull()
        ->and($foundEmployee->id)->toBe($employee->id);
});

it('does not find the record when searching in another organization', function () {
    $orgA = F::org();
    $orgB = F::org();
    $familyA = createTestFamily($orgA);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgA;
    $student->family_id = $familyA->id;
    $student->registration_number = 'R'.substr($student->id, -8);
    $student->first_name = 'Ali';
    $student->setBForm('11111-1111111-1');
    $student->setPassport('A11111111');
    $student->save();

    $guardian = new Guardian;
    $guardian->id = F::id();
    $guardian->organization_id = $orgA;
    $guardian->family_id = $familyA->id;
    $guardian->relation = 'father';
    $guardian->full_name = 'Tariq Khan';
    $guardian->setNationalId('22222-2222222-2');
    $guardian->save();

    $employee = new Employee;
    $employee->id = F::id();
    $employee->organization_id = $orgA;
    $employee->employee_code = 'E'.substr($employee->id, -8);
    $employee->full_name = 'Sara Ahmed';
    $employee->setNationalId('33333-3333333-3');
    $employee->save();

    expect(Student::whereBForm($orgB, '11111-1111111-1')->first())->toBeNull()
        ->and(Student::wherePassport($orgB, 'A11111111')->first())->toBeNull()
        ->and(Guardian::whereNationalId($orgB, '22222-2222222-2')->first())->toBeNull()
        ->and(Employee::whereNationalId($orgB, '33333-3333333-3')->first())->toBeNull();
});

it('matches nothing when lookup value is empty or dashes-only', function () {
    $orgId = F::org();
    $family = createTestFamily($orgId);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'R'.substr($student->id, -8);
    $student->first_name = 'Ali';
    $student->setBForm('11111-1111111-1');
    $student->save();

    expect(Student::whereBForm($orgId, '')->get())->toBeEmpty()
        ->and(Student::whereBForm($orgId, '---')->get())->toBeEmpty()
        ->and(Student::wherePassport($orgId, '   ')->get())->toBeEmpty()
        ->and(Guardian::whereNationalId($orgId, '- - -')->get())->toBeEmpty()
        ->and(Employee::whereNationalId($orgId, '')->get())->toBeEmpty();
});

it('ensures toArray and toJson contain no ciphertext or hash keys', function () {
    $orgId = F::org();
    $family = createTestFamily($orgId);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'R'.substr($student->id, -8);
    $student->first_name = 'Ali';
    $student->setBForm('11111-1111111-1');
    $student->setPassport('A11111111');
    $student->save();

    $studentArray = $student->toArray();
    $studentJson = (string) $student->toJson();

    expect(array_key_exists('b_form_encrypted', $studentArray))->toBeFalse()
        ->and(array_key_exists('b_form_hash', $studentArray))->toBeFalse()
        ->and(array_key_exists('passport_encrypted', $studentArray))->toBeFalse()
        ->and(array_key_exists('passport_hash', $studentArray))->toBeFalse()
        ->and($studentJson)->not->toContain('b_form_encrypted')
        ->and($studentJson)->not->toContain('b_form_hash')
        ->and($studentJson)->not->toContain('passport_encrypted')
        ->and($studentJson)->not->toContain('passport_hash');

    $guardian = new Guardian;
    $guardian->id = F::id();
    $guardian->organization_id = $orgId;
    $guardian->family_id = $family->id;
    $guardian->relation = 'father';
    $guardian->full_name = 'Tariq Khan';
    $guardian->setNationalId('22222-2222222-2');
    $guardian->save();

    $guardianArray = $guardian->toArray();
    $guardianJson = (string) $guardian->toJson();

    expect(array_key_exists('national_id_encrypted', $guardianArray))->toBeFalse()
        ->and(array_key_exists('national_id_hash', $guardianArray))->toBeFalse()
        ->and($guardianJson)->not->toContain('national_id_encrypted')
        ->and($guardianJson)->not->toContain('national_id_hash');

    $employee = new Employee;
    $employee->id = F::id();
    $employee->organization_id = $orgId;
    $employee->employee_code = 'E'.substr($employee->id, -8);
    $employee->full_name = 'Sara Ahmed';
    $employee->setNationalId('33333-3333333-3');
    $employee->save();

    $employeeArray = $employee->toArray();
    $employeeJson = (string) $employee->toJson();

    expect(array_key_exists('national_id_encrypted', $employeeArray))->toBeFalse()
        ->and(array_key_exists('national_id_hash', $employeeArray))->toBeFalse()
        ->and($employeeJson)->not->toContain('national_id_encrypted')
        ->and($employeeJson)->not->toContain('national_id_hash');

    $medical = StudentMedicalProfile::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'allergies' => 'Peanut allergy test note',
        'conditions' => 'Asthma condition test note',
        'medications' => 'Inhaler medication test note',
        'doctor_notes' => 'Dr Smith clinic notes',
    ]);

    $medicalArray = $medical->toArray();
    $medicalJson = (string) $medical->toJson();

    expect(array_key_exists('allergies', $medicalArray))->toBeFalse()
        ->and(array_key_exists('conditions', $medicalArray))->toBeFalse()
        ->and(array_key_exists('medications', $medicalArray))->toBeFalse()
        ->and(array_key_exists('doctor_notes', $medicalArray))->toBeFalse()
        ->and($medicalJson)->not->toContain('allergies')
        ->and($medicalJson)->not->toContain('conditions')
        ->and($medicalJson)->not->toContain('medications')
        ->and($medicalJson)->not->toContain('doctor_notes');

    $custody = StudentCustodyOrder::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'details' => 'Family court sole custody order details',
    ]);

    $custodyArray = $custody->toArray();
    $custodyJson = (string) $custody->toJson();

    expect(array_key_exists('details', $custodyArray))->toBeFalse()
        ->and($custodyJson)->not->toContain('details');
});

it('clears both ciphertext and hash columns when setting null or empty value', function () {
    $orgId = F::org();
    $family = createTestFamily($orgId);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'R'.substr($student->id, -8);
    $student->first_name = 'Ali';
    $student->setBForm('11111-1111111-1');
    $student->setPassport('A11111111');
    $student->save();

    // Clear B-Form with null
    $student->setBForm(null);
    $student->save();

    $rawStudent = DB::table('students')->where('id', $student->id)->first();
    expect($rawStudent->b_form_encrypted)->toBeNull()
        ->and($rawStudent->b_form_hash)->toBeNull();

    // Clear Passport with dashes-only (normalizes to empty)
    $student->setPassport('---');
    $student->save();

    $rawStudent2 = DB::table('students')->where('id', $student->id)->first();
    expect($rawStudent2->passport_encrypted)->toBeNull()
        ->and($rawStudent2->passport_hash)->toBeNull();

    $guardian = new Guardian;
    $guardian->id = F::id();
    $guardian->organization_id = $orgId;
    $guardian->family_id = $family->id;
    $guardian->relation = 'father';
    $guardian->full_name = 'Tariq Khan';
    $guardian->setNationalId('22222-2222222-2');
    $guardian->save();

    $guardian->setNationalId(null);
    $guardian->save();

    $rawGuardian = DB::table('guardians')->where('id', $guardian->id)->first();
    expect($rawGuardian->national_id_encrypted)->toBeNull()
        ->and($rawGuardian->national_id_hash)->toBeNull();

    $employee = new Employee;
    $employee->id = F::id();
    $employee->organization_id = $orgId;
    $employee->employee_code = 'E'.substr($employee->id, -8);
    $employee->full_name = 'Sara Ahmed';
    $employee->setNationalId('33333-3333333-3');
    $employee->save();

    $employee->setNationalId('   ');
    $employee->save();

    $rawEmployee = DB::table('employees')->where('id', $employee->id)->first();
    expect($rawEmployee->national_id_encrypted)->toBeNull()
        ->and($rawEmployee->national_id_hash)->toBeNull();
});

it('throws LogicException without leaking plain value when calling a helper without organization_id', function () {
    $plainValue = '99999-9999999-9';

    $student = new Student;
    try {
        $student->setBForm($plainValue);
        test()->fail('Expected LogicException was not thrown');
    } catch (LogicException $e) {
        expect($e->getMessage())->not->toContain($plainValue);
    }

    try {
        $student->setPassport($plainValue);
        test()->fail('Expected LogicException was not thrown');
    } catch (LogicException $e) {
        expect($e->getMessage())->not->toContain($plainValue);
    }

    $guardian = new Guardian;
    try {
        $guardian->setNationalId($plainValue);
        test()->fail('Expected LogicException was not thrown');
    } catch (LogicException $e) {
        expect($e->getMessage())->not->toContain($plainValue);
    }

    $employee = new Employee;
    try {
        $employee->setNationalId($plainValue);
        test()->fail('Expected LogicException was not thrown');
    } catch (LogicException $e) {
        expect($e->getMessage())->not->toContain($plainValue);
    }
});

it('ignores mass-assignment of ciphertext and hash columns via fill', function () {
    $student = new Student;
    $student->fill([
        'b_form_encrypted' => 'fake-ciphertext-value',
        'b_form_hash' => str_repeat('a', 64),
        'passport_encrypted' => 'fake-passport-ciphertext',
        'passport_hash' => str_repeat('b', 64),
    ]);

    expect($student->b_form_encrypted)->toBeNull()
        ->and($student->b_form_hash)->toBeNull()
        ->and($student->passport_encrypted)->toBeNull()
        ->and($student->passport_hash)->toBeNull();

    $guardian = new Guardian;
    $guardian->fill([
        'national_id_encrypted' => 'fake-ciphertext-value',
        'national_id_hash' => str_repeat('c', 64),
    ]);

    expect($guardian->national_id_encrypted)->toBeNull()
        ->and($guardian->national_id_hash)->toBeNull();

    $employee = new Employee;
    $employee->fill([
        'national_id_encrypted' => 'fake-ciphertext-value',
        'national_id_hash' => str_repeat('d', 64),
    ]);

    expect($employee->national_id_encrypted)->toBeNull()
        ->and($employee->national_id_hash)->toBeNull();
});

it('verifies relationships between Student, StudentMedicalProfile, and StudentCustodyOrder', function () {
    $orgId = F::org();
    $family = createTestFamily($orgId);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'R'.substr($student->id, -8);
    $student->first_name = 'Ali';
    $student->save();

    $medical = StudentMedicalProfile::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'allergies' => 'Peanut allergy test note',
    ]);

    $custody1 = StudentCustodyOrder::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'details' => 'Order 1 details test',
    ]);

    $custody2 = StudentCustodyOrder::create([
        'organization_id' => $orgId,
        'student_id' => $student->id,
        'details' => 'Order 2 details test',
    ]);

    $reloaded = Student::findOrFail($student->id);
    expect($reloaded->medicalProfile)->not->toBeNull()
        ->and($reloaded->medicalProfile->id)->toBe($medical->id)
        ->and($reloaded->custodyOrders)->toHaveCount(2)
        ->and($medical->student->id)->toBe($student->id)
        ->and($custody1->student->id)->toBe($student->id);
});
