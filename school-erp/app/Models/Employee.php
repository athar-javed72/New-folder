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
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $national_id_encrypted
 * @property ?string $national_id_hash
 */
class Employee extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'employees';

    /**
     * Ciphertext and hash columns are strictly guarded from mass-assignment.
     */
    protected $guarded = [
        'national_id_encrypted',
        'national_id_hash',
    ];

    /**
     * Hide ciphertexts and blind index hashes from serialization (D-36).
     */
    protected $hidden = [
        'national_id_encrypted',
        'national_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'national_id_encrypted' => 'encrypted',
            'custom' => JsonDocument::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class, 'employee_id');
    }

    /**
     * Set National ID (CNIC) ciphertext and blind-index hash together.
     *
     * @throws LogicException
     */
    public function setNationalId(?string $value): self
    {
        if (empty($this->organization_id)) {
            throw new LogicException('Cannot set National ID without organization_id on the model.');
        }

        $hash = BlindIndex::hash((string) $this->organization_id, $value);

        if ($hash === null) {
            $this->national_id_encrypted = null;
            $this->national_id_hash = null;
        } else {
            $this->national_id_encrypted = $value;
            $this->national_id_hash = $hash;
        }

        return $this;
    }

    /**
     * Scope query to find employee by blind index of National ID (CNIC) within organization.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeWhereNationalId(Builder $query, string $organizationId, string $plainValue): Builder
    {
        $hash = BlindIndex::hash($organizationId, $plainValue);

        if ($hash === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('organization_id', $organizationId)
            ->where('national_id_hash', $hash);
    }
}
