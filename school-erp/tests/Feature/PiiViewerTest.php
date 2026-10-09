<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Family;
use App\Models\Guardian;
use App\Models\Permission;
use App\Models\PermissionGrant;
use App\Models\Student;
use App\Models\StudentCustodyOrder;
use App\Models\StudentMedicalProfile;
use App\Models\User;
use App\Services\Access\ScopeContext;
use App\Services\Privacy\PiiAccessDenied;
use App\Services\Privacy\PiiViewer;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Tests\Support\DbFactory as F;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function createViewerTestFamily(string $orgId): Family
{
    $family = new Family;
    $family->id = F::id();
    $family->organization_id = $orgId;
    $family->family_code = 'F'.substr($family->id, -8);
    $family->display_name = 'Test Family';
    $family->save();

    return $family;
}

function createViewerTestUser(string $orgId, bool $isSuperAdmin = false): User
{
    $user = new User;
    $user->id = F::id();
    $user->organization_id = $isSuperAdmin ? null : $orgId;
    $user->name = 'Test User';
    $user->email = 'test.'.strtolower($user->id).'@example.test';
    $user->password = 'secret';
    $user->forceFill(['is_super_admin' => $isSuperAdmin])->save();

    return $user;
}

function grantViewerPermission(User $user, string $permissionCode, string $scopeType = 'org', ?string $scopeId = null): PermissionGrant
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

it('allows authorized user to view student identifiers and records an audit log', function () {
    $orgId = F::org();
    $family = createViewerTestFamily($orgId);
    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'students.ids.view', 'org');

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->setBForm('00000-0000001-1');
    $student->setPassport('PK0000001');
    $student->save();

    $viewer = app(PiiViewer::class);
    $result = $viewer->studentIds($actor, $student);

    expect($result)->toBe([
        'b_form' => '00000-0000001-1',
        'passport' => 'PK0000001',
    ]);

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->firstOrFail();
    expect(AuditLog::query()->count())->toBe(1)
        ->and($audit->action)->toBe('pii.viewed')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->organization_id)->toBe($orgId)
        ->and($audit->subject_type)->toBe('student')
        ->and($audit->subject_id)->toBe($student->id)
        ->and($audit->meta)->toBe(['field_group' => 'student_ids']);
});

it('allows authorized user to view guardian national identifier and records an audit log', function () {
    $orgId = F::org();
    $family = createViewerTestFamily($orgId);
    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'students.ids.view', 'org');

    $guardian = new Guardian;
    $guardian->id = F::id();
    $guardian->organization_id = $orgId;
    $guardian->family_id = $family->id;
    $guardian->relation = 'father';
    $guardian->full_name = 'Guardian Name';
    $guardian->setNationalId('00000-0000002-2');
    $guardian->save();

    $viewer = app(PiiViewer::class);
    $result = $viewer->guardianNationalId($actor, $guardian);

    expect($result)->toBe('00000-0000002-2');

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->firstOrFail();
    expect(AuditLog::query()->count())->toBe(1)
        ->and($audit->action)->toBe('pii.viewed')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->organization_id)->toBe($orgId)
        ->and($audit->subject_type)->toBe('guardian')
        ->and($audit->subject_id)->toBe($guardian->id)
        ->and($audit->meta)->toBe(['field_group' => 'guardian_national_id']);
});

it('allows authorized user to view employee national identifier and records an audit log', function () {
    $orgId = F::org();
    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'hr.employee.view', 'org');

    $employee = new Employee;
    $employee->id = F::id();
    $employee->organization_id = $orgId;
    $employee->employee_code = 'EMP'.substr($employee->id, -8);
    $employee->full_name = 'Employee Name';
    $employee->email = 'emp.'.strtolower($employee->id).'@example.test';
    $employee->setNationalId('00000-0000003-3');
    $employee->save();

    $viewer = app(PiiViewer::class);
    $result = $viewer->employeeNationalId($actor, $employee);

    expect($result)->toBe('00000-0000003-3');

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->firstOrFail();
    expect(AuditLog::query()->count())->toBe(1)
        ->and($audit->action)->toBe('pii.viewed')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->organization_id)->toBe($orgId)
        ->and($audit->subject_type)->toBe('employee')
        ->and($audit->subject_id)->toBe($employee->id)
        ->and($audit->meta)->toBe(['field_group' => 'employee_national_id']);
});

it('allows authorized user to view student medical profile and records an audit log', function () {
    $orgId = F::org();
    $family = createViewerTestFamily($orgId);
    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'pastoral.medical.view', 'org');

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->save();

    $profile = new StudentMedicalProfile;
    $profile->id = F::id();
    $profile->organization_id = $orgId;
    $profile->student_id = $student->id;
    $profile->allergies = 'Peanut allergy';
    $profile->conditions = 'Mild asthma';
    $profile->medications = 'Inhaler as needed';
    $profile->doctor_notes = 'Carry inhaler in backpack';
    $profile->save();

    $viewer = app(PiiViewer::class);
    $result = $viewer->medical($actor, $profile);

    expect($result)->toBe([
        'allergies' => 'Peanut allergy',
        'conditions' => 'Mild asthma',
        'medications' => 'Inhaler as needed',
        'doctor_notes' => 'Carry inhaler in backpack',
    ]);

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->firstOrFail();
    expect(AuditLog::query()->count())->toBe(1)
        ->and($audit->action)->toBe('pii.viewed')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->organization_id)->toBe($orgId)
        ->and($audit->subject_type)->toBe('student_medical_profile')
        ->and($audit->subject_id)->toBe($profile->id)
        ->and($audit->meta)->toBe(['field_group' => 'medical']);
});

it('allows authorized user to view student custody order and records an audit log', function () {
    $orgId = F::org();
    $family = createViewerTestFamily($orgId);
    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'pastoral.safeguarding.view', 'org');

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->save();

    $order = new StudentCustodyOrder;
    $order->id = F::id();
    $order->organization_id = $orgId;
    $order->student_id = $student->id;
    $order->details = 'Sole legal custody granted to mother';
    $order->save();

    $viewer = app(PiiViewer::class);
    $result = $viewer->custody($actor, $order);

    expect($result)->toBe('Sole legal custody granted to mother');

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->firstOrFail();
    expect(AuditLog::query()->count())->toBe(1)
        ->and($audit->action)->toBe('pii.viewed')
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->organization_id)->toBe($orgId)
        ->and($audit->subject_type)->toBe('student_custody_order')
        ->and($audit->subject_id)->toBe($order->id)
        ->and($audit->meta)->toBe(['field_group' => 'custody']);
});

it('denies user without permission and writes zero audit rows', function () {
    $orgId = F::org();
    $family = createViewerTestFamily($orgId);
    $actor = createViewerTestUser($orgId); // No permissions granted

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->setBForm('00000-0000004-4');
    $student->save();

    $viewer = app(PiiViewer::class);

    expect(fn () => $viewer->studentIds($actor, $student))
        ->toThrow(PiiAccessDenied::class);

    expect(AuditLog::query()->count())->toBe(0);
});

it('denies user from another organization and writes zero audit rows', function () {
    $orgA = F::org();
    $orgB = F::org();
    $familyA = createViewerTestFamily($orgA);

    $actorFromOrgB = createViewerTestUser($orgB);
    grantViewerPermission($actorFromOrgB, 'students.ids.view', 'org');

    $studentInOrgA = new Student;
    $studentInOrgA->id = F::id();
    $studentInOrgA->organization_id = $orgA;
    $studentInOrgA->family_id = $familyA->id;
    $studentInOrgA->registration_number = 'REG'.substr($studentInOrgA->id, -8);
    $studentInOrgA->first_name = 'Student';
    $studentInOrgA->setBForm('00000-0000005-5');
    $studentInOrgA->save();

    $viewer = app(PiiViewer::class);

    expect(fn () => $viewer->studentIds($actorFromOrgB, $studentInOrgA))
        ->toThrow(PiiAccessDenied::class);

    expect(AuditLog::query()->count())->toBe(0);
});

it('ensures audit log meta contains only field group and never plain values', function () {
    $orgId = F::org();
    $family = createViewerTestFamily($orgId);
    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'students.ids.view', 'org');

    $fakeBForm = '00000-0000006-6';
    $fakePassport = 'PK0000006';

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->setBForm($fakeBForm);
    $student->setPassport($fakePassport);
    $student->save();

    $viewer = app(PiiViewer::class);
    $viewer->studentIds($actor, $student);

    /** @var AuditLog $audit */
    $audit = AuditLog::query()->firstOrFail();

    // Verify meta structure strictly
    expect($audit->meta)->toBe(['field_group' => 'student_ids'])
        ->and(array_keys((array) $audit->meta))->toBe(['field_group'])
        ->and(json_encode($audit->meta))->not->toContain($fakeBForm)
        ->and(json_encode($audit->meta))->not->toContain($fakePassport);
});

it('denies campus-scoped permission holder when accessing record for another campus', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);
    $campusB = F::campus($orgId);
    $family = createViewerTestFamily($orgId);

    $actor = createViewerTestUser($orgId);
    // Grant permission scoped only to campus A
    grantViewerPermission($actor, 'students.ids.view', 'campus', $campusA);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->setBForm('00000-0000007-7');
    $student->save();

    $viewer = app(PiiViewer::class);

    // Scope context for Campus B
    $scopeForCampusB = new ScopeContext(organizationId: $orgId, campusId: $campusB);

    expect(fn () => $viewer->studentIds($actor, $student, $scopeForCampusB))
        ->toThrow(PiiAccessDenied::class);

    expect(AuditLog::query()->count())->toBe(0);
});

it('allows campus-scoped permission holder when accessing record for matching campus', function () {
    $orgId = F::org();
    $campusA = F::campus($orgId);
    $family = createViewerTestFamily($orgId);

    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'students.ids.view', 'campus', $campusA);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->setBForm('00000-0000008-8');
    $student->save();

    $viewer = app(PiiViewer::class);
    $scopeForCampusA = new ScopeContext(organizationId: $orgId, campusId: $campusA);

    $result = $viewer->studentIds($actor, $student, $scopeForCampusA);

    expect($result)->toBe([
        'b_form' => '00000-0000008-8',
        'passport' => null,
    ]);

    expect(AuditLog::query()->count())->toBe(1);
});

it('ensures denial exception message contains no plain value or record id', function () {
    $orgId = F::org();
    $family = createViewerTestFamily($orgId);
    $actor = createViewerTestUser($orgId); // No permissions

    $fakeCnic = '00000-0000009-9';
    $guardian = new Guardian;
    $guardian->id = F::id();
    $guardian->organization_id = $orgId;
    $guardian->family_id = $family->id;
    $guardian->relation = 'mother';
    $guardian->full_name = 'Guardian Mother';
    $guardian->setNationalId($fakeCnic);
    $guardian->save();

    $viewer = app(PiiViewer::class);

    try {
        $viewer->guardianNationalId($actor, $guardian);
        $this->fail('Expected PiiAccessDenied exception was not thrown.');
    } catch (PiiAccessDenied $e) {
        $msg = $e->getMessage();
        expect($msg)->not->toContain($fakeCnic)
            ->and($msg)->not->toContain($guardian->id)
            ->and($msg)->not->toContain($guardian->full_name)
            ->and($msg)->toBe('Access to PII denied.');
    }

    expect(AuditLog::query()->count())->toBe(0);
});

it('aborts value reveal if audit log insertion fails within transaction', function () {
    $orgId = F::org();
    $family = createViewerTestFamily($orgId);
    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'students.ids.view', 'org');

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->setBForm('00000-0000010-0');
    $student->save();

    // Simulate an audit log insertion failure (e.g. mock or trigger error)
    // We can simulate this by temporarily making audit_logs fail or using a model event
    AuditLog::creating(function () {
        throw new RuntimeException('Simulated database audit write failure.');
    });

    $viewer = app(PiiViewer::class);

    expect(fn () => $viewer->studentIds($actor, $student))
        ->toThrow(RuntimeException::class, 'Simulated database audit write failure.');

    // Nothing was committed
    expect(AuditLog::query()->count())->toBe(0);
});

it('denies campus-scoped permission holder when scope context is null', function () {
    $orgId = F::org();
    $campus = F::campus($orgId);
    $family = createViewerTestFamily($orgId);

    $actor = createViewerTestUser($orgId);
    grantViewerPermission($actor, 'students.ids.view', 'campus', $campus);

    $student = new Student;
    $student->id = F::id();
    $student->organization_id = $orgId;
    $student->family_id = $family->id;
    $student->registration_number = 'REG'.substr($student->id, -8);
    $student->first_name = 'Student';
    $student->setBForm('00000-0000011-1');
    $student->save();

    $viewer = app(PiiViewer::class);

    expect(fn () => $viewer->studentIds($actor, $student, null))
        ->toThrow(PiiAccessDenied::class);

    expect(AuditLog::query()->count())->toBe(0);
});
