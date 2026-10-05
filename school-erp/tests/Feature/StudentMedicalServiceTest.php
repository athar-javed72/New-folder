<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Family;
use App\Models\Permission;
use App\Models\PermissionGrant;
use App\Models\Student;
use App\Models\StudentMedicalProfile;
use App\Models\User;
use App\Services\Access\ScopeContext;
use App\Services\Privacy\PiiAccessDenied;
use App\Services\Privacy\StudentMedicalService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function createMedicalTestFamily(string $orgId): Family
{
    $family = new Family;
    $family->id = F::id();
    $family->organization_id = $orgId;
    $family->family_code = 'F'.substr($family->id, -8);
    $family->display_name = 'Test Family';
    $family->save();

    return $family;
}

function createMedicalTestStudent(string $orgId, ?string $familyId = null): Student
{
    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $familyId ?? createMedicalTestFamily($orgId)->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->has_medical_alert = false;
    $student->has_severe_allergy = false;
    $student->save();

    return $student;
}

function createMedicalTestUser(string $orgId, bool $isSuperAdmin = false): User
{
    $user = new User;
    $user->id = F::id();
    $user->organization_id = $isSuperAdmin ? null : $orgId;
    $user->name = 'Medical User';
    $user->email = 'med.'.strtolower($user->id).'@example.test';
    $user->password = 'secret';
    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

function grantMedicalPermission(User $user, string $permissionCode, string $scopeType = 'org', ?string $scopeId = null): PermissionGrant
{
    /** @var Permission $permission */
    $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();

    return PermissionGrant::query()->create([
        'organization_id' => $user->organization_id,
        'user_id' => $user->id,
        'permission_id' => $permission->id,
        'scope_type' => $scopeType,
        'scope_id' => $scopeId,
        'status' => 'active',
        'with_grant' => false,
        'starts_at' => now()->subMinute(),
        'ends_at' => null,
    ]);
}

it('creates a profile and sets medical alert and severe allergy flags on first save', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId);
    grantMedicalPermission($actor, 'pastoral.medical.edit', 'org');

    $scope = new ScopeContext(organizationId: $orgId);
    $data = [
        'allergies' => 'Peanut and tree nut allergy',
        'conditions' => 'Mild childhood asthma',
        'medications' => 'Daily inhaler',
        'doctor_notes' => 'Keep emergency inhaler available',
    ];

    $service = new StudentMedicalService;
    $profile = $service->save($actor, $student, $data, $scope, true);

    expect(StudentMedicalProfile::query()->count())->toBe(1)
        ->and($profile->student_id)->toBe($student->id)
        ->and($profile->organization_id)->toBe($orgId)
        ->and($profile->allergies)->toBe('Peanut and tree nut allergy')
        ->and($profile->conditions)->toBe('Mild childhood asthma')
        ->and($profile->medications)->toBe('Daily inhaler')
        ->and($profile->doctor_notes)->toBe('Keep emergency inhaler available')
        ->and($profile->updated_by)->toBe($actor->id);

    $freshStudent = Student::findOrFail($student->id);
    expect($freshStudent->has_medical_alert)->toBeTrue()
        ->and($freshStudent->has_severe_allergy)->toBeTrue();

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->firstOrFail();
    expect(AuditLog::query()->count())->toBe(1)
        ->and($audit->action)->toBe('pii.updated')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->organization_id)->toBe($orgId)
        ->and($audit->subject_type)->toBe('student_medical_profile')
        ->and($audit->subject_id)->toBe($profile->id)
        ->and($audit->meta)->toBe(['field_group' => 'medical']);
});

it('turns medical alert flag off when medical details are cleared', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId);
    grantMedicalPermission($actor, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgId);

    $service = new StudentMedicalService;
    $service->save($actor, $student, ['allergies' => 'Seasonal pollen'], $scope, false);

    $freshStudent1 = Student::findOrFail($student->id);
    expect($freshStudent1->has_medical_alert)->toBeTrue();

    // Now clear details with null, empty, and whitespace
    $clearedData = [
        'allergies' => '',
        'conditions' => '   ',
        'medications' => null,
        'doctor_notes' => 'Routine checkup completed',
    ];
    $profile = $service->save($actor, $student, $clearedData, $scope, false);

    expect($profile->allergies)->toBeNull()
        ->and($profile->conditions)->toBeNull()
        ->and($profile->medications)->toBeNull()
        ->and($profile->doctor_notes)->toBe('Routine checkup completed');

    $freshStudent2 = Student::findOrFail($student->id);
    expect($freshStudent2->has_medical_alert)->toBeFalse()
        ->and($freshStudent2->has_severe_allergy)->toBeFalse();
});

it('updates severe allergy flag according to argument in both directions', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId);
    grantMedicalPermission($actor, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgId);

    $service = new StudentMedicalService;

    // True
    $service->save($actor, $student, ['allergies' => 'Shellfish'], $scope, true);
    expect(Student::findOrFail($student->id)->has_severe_allergy)->toBeTrue();

    // False
    $service->save($actor, $student, ['allergies' => 'Shellfish'], $scope, false);
    expect(Student::findOrFail($student->id)->has_severe_allergy)->toBeFalse();
});

it('denies user without permission and writes nothing', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId); // No permissions
    $scope = new ScopeContext(organizationId: $orgId);

    $service = new StudentMedicalService;

    expect(fn () => $service->save($actor, $student, ['allergies' => 'Dust'], $scope))
        ->toThrow(PiiAccessDenied::class);

    expect(StudentMedicalProfile::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    $freshStudent = Student::findOrFail($student->id);
    expect($freshStudent->has_medical_alert)->toBeFalse()
        ->and($freshStudent->has_severe_allergy)->toBeFalse();
});

it('denies user from another organization and writes nothing', function () {
    $orgA = F::org();
    $orgB = F::org();
    $studentInOrgA = createMedicalTestStudent($orgA);

    $actorFromOrgB = createMedicalTestUser($orgB);
    grantMedicalPermission($actorFromOrgB, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgA);

    $service = new StudentMedicalService;

    expect(fn () => $service->save($actorFromOrgB, $studentInOrgA, ['allergies' => 'Dairy'], $scope))
        ->toThrow(PiiAccessDenied::class);

    expect(StudentMedicalProfile::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('updates the existing row on second save without creating a duplicate', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor1 = createMedicalTestUser($orgId);
    $actor2 = createMedicalTestUser($orgId);
    grantMedicalPermission($actor1, 'pastoral.medical.edit', 'org');
    grantMedicalPermission($actor2, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgId);

    $service = new StudentMedicalService;

    $profile1 = $service->save($actor1, $student, ['allergies' => 'Bee sting'], $scope, true);
    expect(StudentMedicalProfile::query()->count())->toBe(1);

    $profile2 = $service->save($actor2, $student, ['allergies' => 'Wasp sting', 'conditions' => 'Asthma'], $scope, true);

    expect(StudentMedicalProfile::query()->count())->toBe(1)
        ->and($profile2->id)->toBe($profile1->id)
        ->and($profile2->allergies)->toBe('Wasp sting')
        ->and($profile2->conditions)->toBe('Asthma')
        ->and($profile2->updated_by)->toBe($actor2->id)
        ->and(AuditLog::query()->count())->toBe(2);
});

it('ensures audit row meta contains only field group and never plain values', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId);
    grantMedicalPermission($actor, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgId);

    $fakeAllergy = 'Extreme latex sensitivity';
    $fakeCondition = 'Chronic respiratory weakness';

    $service = new StudentMedicalService;
    $service->save($actor, $student, [
        'allergies' => $fakeAllergy,
        'conditions' => $fakeCondition,
    ], $scope);

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->firstOrFail();

    expect($audit->meta)->toBe(['field_group' => 'medical'])
        ->and(array_keys((array) $audit->meta))->toBe(['field_group'])
        ->and(json_encode($audit->meta))->not->toContain($fakeAllergy)
        ->and(json_encode($audit->meta))->not->toContain($fakeCondition);
});

it('stores medical profile details as ciphertext in raw database columns', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId);
    grantMedicalPermission($actor, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgId);

    $plainAllergies = 'Severe peanut allergy';
    $plainConditions = 'Childhood asthma';
    $plainMeds = 'Ventolin inhaler 100mcg';
    $plainNotes = 'Doctor clinic phone: 555-0199';

    $service = new StudentMedicalService;
    $profile = $service->save($actor, $student, [
        'allergies' => $plainAllergies,
        'conditions' => $plainConditions,
        'medications' => $plainMeds,
        'doctor_notes' => $plainNotes,
    ], $scope);

    $raw = DB::table('student_medical_profiles')->where('id', $profile->id)->first();

    expect($raw)->not->toBeNull()
        ->and($raw->allergies)->not->toBe($plainAllergies)
        ->and($raw->allergies)->not->toContain($plainAllergies)
        ->and($raw->conditions)->not->toBe($plainConditions)
        ->and($raw->conditions)->not->toContain($plainConditions)
        ->and($raw->medications)->not->toBe($plainMeds)
        ->and($raw->medications)->not->toContain($plainMeds)
        ->and($raw->doctor_notes)->not->toBe($plainNotes)
        ->and($raw->doctor_notes)->not->toContain($plainNotes);
});

it('leaves medical alert flag false when doctor notes alone are provided', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId);
    grantMedicalPermission($actor, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgId);

    $service = new StudentMedicalService;
    $profile = $service->save($actor, $student, [
        'doctor_notes' => 'Annual medical checkup completed without concerns',
    ], $scope, false);

    expect($profile->doctor_notes)->toBe('Annual medical checkup completed without concerns')
        ->and($profile->allergies)->toBeNull()
        ->and($profile->conditions)->toBeNull()
        ->and($profile->medications)->toBeNull();

    $freshStudent = Student::findOrFail($student->id);
    expect($freshStudent->has_medical_alert)->toBeFalse()
        ->and($freshStudent->has_severe_allergy)->toBeFalse();
});

it('ignores unknown keys in data payload', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId);
    grantMedicalPermission($actor, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgId);

    $data = [
        'allergies' => 'Dust allergy',
        'unknown_field' => 'should be ignored',
        'is_admin' => true,
        'organization_id' => 'malicious_org_override',
        'student_id' => 'malicious_student_override',
    ];

    $service = new StudentMedicalService;
    $profile = $service->save($actor, $student, $data, $scope);

    expect($profile->organization_id)->toBe($orgId)
        ->and($profile->student_id)->toBe($student->id)
        ->and($profile->allergies)->toBe('Dust allergy');

    $freshStudent = Student::findOrFail($student->id);
    expect($freshStudent->has_medical_alert)->toBeTrue();
});

it('rolls back profile and student flag changes if audit logging fails', function () {
    $orgId = F::org();
    $student = createMedicalTestStudent($orgId);
    $actor = createMedicalTestUser($orgId);
    grantMedicalPermission($actor, 'pastoral.medical.edit', 'org');
    $scope = new ScopeContext(organizationId: $orgId);

    AuditLog::creating(function () {
        throw new RuntimeException('Simulated database audit log failure');
    });

    $service = new StudentMedicalService;

    expect(fn () => $service->save($actor, $student, ['allergies' => 'Soy'], $scope, true))
        ->toThrow(RuntimeException::class, 'Simulated database audit log failure');

    // Assert that nothing was committed due to transaction rollback
    expect(StudentMedicalProfile::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    $freshStudent = Student::findOrFail($student->id);
    expect($freshStudent->has_medical_alert)->toBeFalse()
        ->and($freshStudent->has_severe_allergy)->toBeFalse();
});
