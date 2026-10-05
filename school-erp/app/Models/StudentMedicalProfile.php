<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentMedicalProfile extends Model
{
    use HasUlids;

    protected $table = 'student_medical_profiles';

    protected $fillable = [
        'organization_id',
        'student_id',
        'allergies',
        'conditions',
        'medications',
        'doctor_notes',
        'updated_by',
    ];

    /**
     * Hide encrypted medical details from serialization (D-36).
     */
    protected $hidden = [
        'allergies',
        'conditions',
        'medications',
        'doctor_notes',
    ];

    protected function casts(): array
    {
        return [
            'allergies' => 'encrypted',
            'conditions' => 'encrypted',
            'medications' => 'encrypted',
            'doctor_notes' => 'encrypted',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
