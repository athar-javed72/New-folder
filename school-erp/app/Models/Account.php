<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Chart of accounts row (D-48). Services find accounts by system_key, never by code. */
class Account extends Model
{
    use HasUlids;

    protected $table = 'accounts';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'type',
        'system_key',
        'requires_family',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_family' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeSystemKey(Builder $query, string $key): Builder
    {
        return $query->where('system_key', $key);
    }
}
