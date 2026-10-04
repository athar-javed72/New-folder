<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Tenant override of a policy. `scopeable` is a morph (organization, campus, program, grade, course,
 * contract_type, employment_contract). Register the morph map (README section 6) and add organization scoping.
 */
class PolicyOverride extends Model
{
    use HasUlids;

    protected $table = 'policy_overrides';

    protected $fillable = [
        'organization_id', 'policy_type', 'policy_key', 'scopeable_type', 'scopeable_id', 'merge_mode', 'value',
        'effective_from', 'effective_to', 'version', 'status', 'base_preset_version', 'reason',
        'created_by', 'approved_by', 'approved_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => JsonDocument::class,
            'effective_from' => 'date', 'effective_to' => 'date',
            'approved_at' => 'datetime', 'published_at' => 'datetime',
        ];
    }

    public function scopeable(): MorphTo
    {
        return $this->morphTo();
    }
}
