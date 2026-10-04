<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Campus;
use App\Models\ContractType;
use App\Models\EmploymentContract;
use App\Models\Grade;
use App\Models\Organization;
use App\Models\Program;
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
        ]);
    }
}
