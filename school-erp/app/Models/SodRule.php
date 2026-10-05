<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SodRule extends Model
{
    use HasUlids;

    protected $table = 'sod_rules';

    protected $fillable = [
        'organization_id',
        'permission_a',
        'permission_b',
        'record_type',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<SodRule>  $query
     */
    public function scopeSystem(Builder $query): void
    {
        $query->whereNull('organization_id');
    }

    /**
     * @param  Builder<SodRule>  $query
     */
    public function scopeForOrganization(Builder $query, string $organizationId): void
    {
        $query->where(function (Builder $q) use ($organizationId) {
            $q->whereNull('organization_id')
                ->orWhere('organization_id', $organizationId);
        });
    }

    /**
     * @param  Builder<SodRule>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
