<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentMedicalProfile;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\ScopeContext;
use Illuminate\Support\Facades\DB;

/**
 * @method static StudentMedicalProfile save(User $actor, Student $student, array $data, ScopeContext $scope, bool $severeAllergy = false)
 */
final class StudentMedicalService
{
    /**
     * Upsert a student's medical profile and update medical alert flags atomically.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PiiAccessDenied
     */
    public function save(
        User $actor,
        Student $student,
        array $data,
        ScopeContext $scope,
        bool $severeAllergy = false,
    ): StudentMedicalProfile {
        $studentOrgId = (string) $student->getAttribute('organization_id');

        // 1. Cross-organization check (Decision 2): student's organization_id must match actor's
        if (! $actor->is_super_admin && ($actor->organization_id === null || $studentOrgId !== (string) $actor->organization_id)) {
            throw new PiiAccessDenied('Access to PII denied.');
        }

        // 2. Permission check (Decision 2 & D-34)
        if (! AccessResolver::can($actor, 'pastoral.medical.edit', $scope)) {
            throw new PiiAccessDenied('Access to PII denied.');
        }

        // 3. Extract and normalize allowed keys only (Decision 3)
        $clean = static function (mixed $value): ?string {
            if ($value === null || ! is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        };

        $allergies = array_key_exists('allergies', $data) ? $clean($data['allergies']) : null;
        $conditions = array_key_exists('conditions', $data) ? $clean($data['conditions']) : null;
        $medications = array_key_exists('medications', $data) ? $clean($data['medications']) : null;
        $doctorNotes = array_key_exists('doctor_notes', $data) ? $clean($data['doctor_notes']) : null;

        // 4. Alert flags (Decision 5): has_medical_alert is true if any of allergies, conditions, medications is non-empty
        $hasMedicalAlert = ($allergies !== null || $conditions !== null || $medications !== null);

        // 5. Transactional write of profile, student flags, and audit log (Decisions 4 & 6)
        return DB::transaction(function () use (
            $actor,
            $student,
            $studentOrgId,
            $allergies,
            $conditions,
            $medications,
            $doctorNotes,
            $hasMedicalAlert,
            $severeAllergy,
        ): StudentMedicalProfile {
            /** @var StudentMedicalProfile $profile */
            $profile = StudentMedicalProfile::query()->updateOrCreate(
                [
                    'organization_id' => $studentOrgId,
                    'student_id' => (string) $student->getKey(),
                ],
                [
                    'allergies' => $allergies,
                    'conditions' => $conditions,
                    'medications' => $medications,
                    'doctor_notes' => $doctorNotes,
                    'updated_by' => $actor->id,
                ],
            );

            Student::query()
                ->where('organization_id', $studentOrgId)
                ->where('id', (string) $student->getKey())
                ->update([
                    'has_medical_alert' => $hasMedicalAlert,
                    'has_severe_allergy' => $severeAllergy,
                ]);

            $student->has_medical_alert = $hasMedicalAlert;
            $student->has_severe_allergy = $severeAllergy;

            AuditLog::query()->create([
                'organization_id' => $studentOrgId,
                'actor_id' => $actor->id,
                'action' => 'pii.updated',
                'subject_type' => $profile->getMorphClass(),
                'subject_id' => (string) $profile->getKey(),
                'meta' => [
                    'field_group' => 'medical',
                ],
            ]);

            return $profile;
        });
    }

    /**
     * Handle static calls gracefully.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return (new self)->$method(...$arguments);
    }
}
