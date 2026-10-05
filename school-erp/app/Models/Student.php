<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'students';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['custom' => JsonDocument::class, 'date_of_birth' => 'date', 'admission_date' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
