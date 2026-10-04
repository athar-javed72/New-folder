<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campus extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'campuses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['address' => JsonDocument::class, 'settings' => JsonDocument::class];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function academicCalendars(): HasMany
    {
        return $this->hasMany(AcademicCalendar::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
