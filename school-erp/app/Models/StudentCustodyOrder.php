<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentCustodyOrder extends Model
{
    use HasUlids;

    protected $table = 'student_custody_orders';

    protected $fillable = [
        'organization_id',
        'student_id',
        'details',
        'created_by',
    ];

    /**
     * Hide encrypted custody details from serialization (D-36).
     */
    protected $hidden = [
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'encrypted',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
