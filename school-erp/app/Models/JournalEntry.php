<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Append-only (database trigger, D-46). Never update or delete: post a reversal instead. */
class JournalEntry extends Model
{
    use HasUlids;

    const UPDATED_AT = null;

    protected $table = 'journal_entries';

    protected $fillable = [
        'organization_id',
        'campus_id',
        'period_id',
        'entry_date',
        'source_type',
        'source_id',
        'kind',
        'reversal_of',
        'line_count',
        'total_minor',
        'lines_hash',
        'memo',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Journal entries are append-only; post a reversal instead.');
        });
        static::deleting(function (): void {
            throw new LogicException('Journal entries are append-only; post a reversal instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'line_count' => 'integer',
            'total_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(LedgerPeriod::class, 'period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
