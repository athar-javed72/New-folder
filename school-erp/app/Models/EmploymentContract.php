<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmploymentContract extends Model
{
    use HasUlids;

    protected $table = 'employment_contracts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['working_pattern' => JsonDocument::class, 'policy_overrides' => JsonDocument::class, 'start_date' => 'date', 'end_date' => 'date', 'probation_ends_on' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class, 'contract_type_id');
    }

    public function leaveLedger(): HasMany
    {
        return $this->hasMany(LeaveLedger::class, 'contract_id');
    }
}
