<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only (database trigger, D-47). Never update or delete: post a reversal instead. */
class JournalLine extends Model
{
    use HasUlids;

    const UPDATED_AT = null;

    protected $table = 'journal_lines';

    protected $fillable = [
        'organization_id',
        'entry_id',
        'line_no',
        'account_id',
        'family_id',
        'debit_minor',
        'credit_minor',
        'description',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Journal lines are append-only; post a reversal instead.');
        });
        static::deleting(function (): void {
            throw new LogicException('Journal lines are append-only; post a reversal instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
