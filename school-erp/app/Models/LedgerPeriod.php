<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Organization-wide ledger period (D-45). A closed period is final (database trigger).
 *
 * @property Carbon|null $closed_at
 */
class LedgerPeriod extends Model
{
    use HasUlids;

    protected $table = 'ledger_periods';

    protected $fillable = [
        'organization_id',
        'name',
        'starts_on',
        'ends_on',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'period_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
