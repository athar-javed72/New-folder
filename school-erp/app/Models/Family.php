<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Family extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'families';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['address' => JsonDocument::class, 'custom' => JsonDocument::class];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(Guardian::class);
    }
}
