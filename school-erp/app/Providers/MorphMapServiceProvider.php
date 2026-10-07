<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Account;
use App\Models\Campus;
use App\Models\ContractType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerPeriod;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentCustodyOrder;
use App\Models\StudentMedicalProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Aliases here must match the CHECK constraint policy_overrides_scope_chk (migration 11).
 * The CHECK also allows 'course'; register that alias here in Phase 2 when the Course model exists.
 */
class MorphMapServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'organization' => Organization::class,
            'campus' => Campus::class,
            'program' => Program::class,
            'grade' => Grade::class,
            'contract_type' => ContractType::class,
            'employment_contract' => EmploymentContract::class,
            'student' => Student::class,
            'guardian' => Guardian::class,
            'employee' => Employee::class,
            'student_medical_profile' => StudentMedicalProfile::class,
            'student_custody_order' => StudentCustodyOrder::class,
            'user' => User::class,
            'account' => Account::class,
            'ledger_period' => LedgerPeriod::class,
            'journal_entry' => JournalEntry::class,
            'journal_line' => JournalLine::class,
        ]);
    }
}
