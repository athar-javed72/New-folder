<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use App\Services\Privacy\BlindIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $b_form_encrypted
 * @property ?string $b_form_hash
 * @property ?string $passport_encrypted
 * @property ?string $passport_hash
 * @property bool $has_medical_alert
 * @property bool $has_severe_allergy
 */
class Student extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'students';

    /**
     * Ciphertext and hash columns are strictly guarded from mass-assignment.
     */
    protected $guarded = [
        'b_form_encrypted',
        'b_form_hash',
        'passport_encrypted',
        'passport_hash',
    ];

    /**
     * Hide ciphertexts and blind index hashes from serialization (D-36).
     */
    protected $hidden = [
        'b_form_encrypted',
        'b_form_hash',
        'passport_encrypted',
        'passport_hash',
    ];

    protected function casts(): array
    {
        return [
            'b_form_encrypted' => 'encrypted',
            'passport_encrypted' => 'encrypted',
            'has_medical_alert' => 'boolean',
            'has_severe_allergy' => 'boolean',
            'custom' => JsonDocument::class,
            'date_of_birth' => 'date',
            'admission_date' => 'date',
        ];
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

    public function medicalProfile(): HasOne
    {
        return $this->hasOne(StudentMedicalProfile::class, 'student_id');
    }

    public function custodyOrders(): HasMany
    {
        return $this->hasMany(StudentCustodyOrder::class, 'student_id');
    }

    /**
     * Set B-Form ciphertext and blind-index hash together.
     *
     * @throws LogicException
     */
    public function setBForm(?string $value): self
    {
        if (empty($this->organization_id)) {
            throw new LogicException('Cannot set B-Form without organization_id on the model.');
        }

        $hash = BlindIndex::hash((string) $this->organization_id, $value);

        if ($hash === null) {
            $this->b_form_encrypted = null;
            $this->b_form_hash = null;
        } else {
            $this->b_form_encrypted = $value;
            $this->b_form_hash = $hash;
        }

        return $this;
    }

    /**
     * Set Passport ciphertext and blind-index hash together.
     *
     * @throws LogicException
     */
    public function setPassport(?string $value): self
    {
        if (empty($this->organization_id)) {
            throw new LogicException('Cannot set Passport without organization_id on the model.');
        }

        $hash = BlindIndex::hash((string) $this->organization_id, $value);

        if ($hash === null) {
            $this->passport_encrypted = null;
            $this->passport_hash = null;
        } else {
            $this->passport_encrypted = $value;
            $this->passport_hash = $hash;
        }

        return $this;
    }

    /**
     * Scope query to find student by blind index of B-Form within organization.
     *
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public function scopeWhereBForm(Builder $query, string $organizationId, string $plainValue): Builder
    {
        $hash = BlindIndex::hash($organizationId, $plainValue);

        if ($hash === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('organization_id', $organizationId)
            ->where('b_form_hash', $hash);
    }

    /**
     * Scope query to find student by blind index of Passport within organization.
     *
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    public function scopeWherePassport(Builder $query, string $organizationId, string $plainValue): Builder
    {
        $hash = BlindIndex::hash($organizationId, $plainValue);

        if ($hash === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('organization_id', $organizationId)
            ->where('passport_hash', $hash);
    }
}
