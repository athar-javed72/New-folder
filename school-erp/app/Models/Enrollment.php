<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'enrollments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['custom' => JsonDocument::class, 'start_date' => 'date', 'end_date' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function academicCalendar(): BelongsTo
    {
        return $this->belongsTo(AcademicCalendar::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function previousEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'previous_enrollment_id');
    }
}
