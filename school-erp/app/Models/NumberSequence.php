<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Gap-free counter (D-49). Advanced only by NumberSequenceService::next(); never deleted (database trigger). */
class NumberSequence extends Model
{
    use HasUlids;

    protected $table = 'number_sequences';

    protected $fillable = [
        'organization_id',
        'campus_id',
        'key',
        'fiscal_year',
        'last_number',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Number sequences cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'last_number' => 'integer',
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
}
