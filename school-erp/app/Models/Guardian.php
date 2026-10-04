<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guardian extends Model
{
    use HasUlids;

    protected $table = 'guardians';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['custom' => JsonDocument::class];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
