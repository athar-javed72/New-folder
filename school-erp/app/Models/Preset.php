<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Preset extends Model
{
    use HasUlids;

    protected $table = 'presets';

    protected $fillable = ['key', 'version', 'name', 'payload', 'checksum', 'status'];

    protected function casts(): array
    {
        return ['payload' => JsonDocument::class, 'imported_at' => 'datetime'];
    }

    public function policies(): HasMany
    {
        return $this->hasMany(SystemPolicy::class);
    }
}
