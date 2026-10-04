<?php

declare(strict_types=1);

use App\Models\AcademicCalendar;
use App\Models\Campus;
use App\Models\Enrollment;
use App\Models\Family;
use App\Models\Grade;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Student;

it('creates organization, campus, family, student and enrollment through Eloquent and reloads them', function () {
    // 1. Organization
    $org = Organization::create([
        'name' => 'The City School',
        'slug' => 'the-city-school',
        'status' => 'active',
    ]);
    expect($org->id)->toBeString()->not->toBeEmpty();

    // 2. Campus
    $campus = Campus::create([
        'organization_id' => $org->id,
        'name' => 'Gulberg Campus',
        'code' => 'GUL-01',
        'status' => 'active',
    ]);
    expect($campus->id)->toBeString()->not->toBeEmpty();

    // Academic dependencies for Enrollment
    $program = Program::create([
        'organization_id' => $org->id,
        'name' => 'Matriculation',
        'key' => 'matric',
    ]);

    $calendar = AcademicCalendar::create([
        'organization_id' => $org->id,
        'campus_id' => $campus->id,
        'program_id' => $program->id,
        'name' => 'Academic Year 2026-2027',
        'starts_on' => '2026-04-01',
        'ends_on' => '2027-03-31',
        'status' => 'active',
    ]);

    $grade = Grade::create([
        'organization_id' => $org->id,
        'program_id' => $program->id,
        'name' => 'Grade 9',
        'key' => 'g9',
        'level' => 9,
    ]);

    // 3. Family
    $family = Family::create([
        'organization_id' => $org->id,
        'family_code' => 'FAM-1001',
        'display_name' => 'Ahmed Family',
    ]);
    expect($family->id)->toBeString()->not->toBeEmpty();

    // 4. Student
    $student = Student::create([
        'organization_id' => $org->id,
        'family_id' => $family->id,
        'registration_number' => 'REG-2026-001',
        'first_name' => 'Bilal',
        'last_name' => 'Ahmed',
        'date_of_birth' => '2011-05-15',
        'gender' => 'male',
        'lifecycle_status' => 'active',
    ]);
    expect($student->id)->toBeString()->not->toBeEmpty();

    // 5. Enrollment
    $enrollment = Enrollment::create([
        'organization_id' => $org->id,
        'student_id' => $student->id,
        'campus_id' => $campus->id,
        'academic_calendar_id' => $calendar->id,
        'grade_id' => $grade->id,
        'status' => 'active',
        'start_date' => '2026-04-01',
    ]);
    expect($enrollment->id)->toBeString()->not->toBeEmpty();

    // Reload all models from the database
    $reloadedOrg = $org->fresh(['campuses', 'families', 'students', 'enrollments']);
    $reloadedCampus = $campus->fresh(['organization']);
    $reloadedFamily = $family->fresh(['students', 'organization']);
    $reloadedStudent = $student->fresh(['family', 'enrollments', 'organization']);
    $reloadedEnrollment = $enrollment->fresh(['student', 'campus', 'grade', 'academicCalendar', 'organization']);

    // Assert reloaded values and relations
    expect($reloadedOrg)->not->toBeNull()
        ->and($reloadedOrg->name)->toBe('The City School')
        ->and($reloadedOrg->slug)->toBe('the-city-school')
        ->and($reloadedOrg->campuses)->toHaveCount(1)
        ->and($reloadedOrg->campuses->first()->id)->toBe($campus->id);

    expect($reloadedCampus)->not->toBeNull()
        ->and($reloadedCampus->name)->toBe('Gulberg Campus')
        ->and($reloadedCampus->organization_id)->toBe($org->id)
        ->and($reloadedCampus->organization->id)->toBe($org->id);

    expect($reloadedFamily)->not->toBeNull()
        ->and($reloadedFamily->family_code)->toBe('FAM-1001')
        ->and($reloadedFamily->organization->id)->toBe($org->id)
        ->and($reloadedFamily->students)->toHaveCount(1)
        ->and($reloadedFamily->students->first()->id)->toBe($student->id);

    expect($reloadedStudent)->not->toBeNull()
        ->and($reloadedStudent->first_name)->toBe('Bilal')
        ->and($reloadedStudent->organization->id)->toBe($org->id)
        ->and($reloadedStudent->family->id)->toBe($family->id)
        ->and($reloadedStudent->enrollments)->toHaveCount(1)
        ->and($reloadedStudent->enrollments->first()->id)->toBe($enrollment->id);

    expect($reloadedEnrollment)->not->toBeNull()
        ->and($reloadedEnrollment->status)->toBe('active')
        ->and($reloadedEnrollment->organization->id)->toBe($org->id)
        ->and($reloadedEnrollment->student->id)->toBe($student->id)
        ->and($reloadedEnrollment->campus->id)->toBe($campus->id)
        ->and($reloadedEnrollment->grade->id)->toBe($grade->id)
        ->and($reloadedEnrollment->academicCalendar->id)->toBe($calendar->id);
});
