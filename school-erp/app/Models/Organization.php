<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'organizations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => JsonDocument::class];
    }

    public function campuses(): HasMany
    {
        return $this->hasMany(Campus::class);
    }

    public function families(): HasMany
    {
        return $this->hasMany(Family::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }
}
