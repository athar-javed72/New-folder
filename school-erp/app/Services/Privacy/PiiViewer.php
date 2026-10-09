<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentCustodyOrder;
use App\Models\StudentMedicalProfile;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\ScopeContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class PiiViewer
{
    /**
     * Reveal student identification numbers (B-Form and Passport).
     *
     * @return array{b_form: ?string, passport: ?string}
     */
    public function studentIds(User $actor, Student $student, ?ScopeContext $scope = null): array
    {
        return $this->reveal(
            actor: $actor,
            record: $student,
            permission: 'students.ids.view',
            fieldGroup: 'student_ids',
            scope: $scope,
            resolver: fn (): array => [
                'b_form' => $student->b_form_encrypted,
                'passport' => $student->passport_encrypted,
            ],
        );
    }

    /**
     * Reveal guardian national ID (CNIC).
     */
    public function guardianNationalId(User $actor, Guardian $guardian, ?ScopeContext $scope = null): ?string
    {
        return $this->reveal(
            actor: $actor,
            record: $guardian,
            permission: 'students.ids.view',
            fieldGroup: 'guardian_national_id',
            scope: $scope,
            resolver: fn (): ?string => $guardian->national_id_encrypted,
        );
    }

    /**
     * Reveal employee national ID (CNIC).
     */
    public function employeeNationalId(User $actor, Employee $employee, ?ScopeContext $scope = null): ?string
    {
        return $this->reveal(
            actor: $actor,
            record: $employee,
            permission: 'hr.employee.view',
            fieldGroup: 'employee_national_id',
            scope: $scope,
            resolver: fn (): ?string => $employee->national_id_encrypted,
        );
    }

    /**
     * Reveal student medical profile details.
     *
     * @return array{allergies: ?string, conditions: ?string, medications: ?string, doctor_notes: ?string}
     */
    public function medical(User $actor, StudentMedicalProfile $profile, ?ScopeContext $scope = null): array
    {
        return $this->reveal(
            actor: $actor,
            record: $profile,
            permission: 'pastoral.medical.view',
            fieldGroup: 'medical',
            scope: $scope,
            resolver: fn (): array => [
                'allergies' => $profile->allergies,
                'conditions' => $profile->conditions,
                'medications' => $profile->medications,
                'doctor_notes' => $profile->doctor_notes,
            ],
        );
    }

    /**
     * Reveal student custody order details.
     */
    public function custody(User $actor, StudentCustodyOrder $order, ?ScopeContext $scope = null): string
    {
        return $this->reveal(
            actor: $actor,
            record: $order,
            permission: 'pastoral.safeguarding.view',
            fieldGroup: 'custody',
            scope: $scope,
            resolver: fn (): string => (string) $order->details,
        );
    }

    /**
     * Centralized authorization, multi-tenancy verification, and transactional audit logging.
     *
     * @template T
     *
     * @param  callable(): T  $resolver
     * @return T
     *
     * @throws PiiAccessDenied
     */
    protected function reveal(
        User $actor,
        Model $record,
        string $permission,
        string $fieldGroup,
        ?ScopeContext $scope,
        callable $resolver,
    ): mixed {
        $recordOrgId = (string) $record->getAttribute('organization_id');

        // 1. Cross-organization check (Decision 2): record's organization_id must match actor's
        if (! $actor->is_super_admin && ($actor->organization_id === null || $recordOrgId !== (string) $actor->organization_id)) {
            throw new PiiAccessDenied('Access to PII denied.');
        }

        // 2. Permission check (Decision 1 & D-33)
        if (! AccessResolver::can($actor, $permission, $scope)) {
            throw new PiiAccessDenied('Access to PII denied.');
        }

        // 3. Atomically write exactly one audit row and read decrypted value (Decisions 4 & 6)
        return DB::transaction(function () use ($actor, $record, $recordOrgId, $fieldGroup, $resolver) {
            AuditLog::query()->create([
                'organization_id' => $recordOrgId,
                'actor_id' => $actor->id,
                'action' => 'pii.viewed',
                'subject_type' => $record->getMorphClass(),
                'subject_id' => (string) $record->getKey(),
                'meta' => [
                    'field_group' => $fieldGroup,
                ],
            ]);

            return $resolver();
        });
    }
}
