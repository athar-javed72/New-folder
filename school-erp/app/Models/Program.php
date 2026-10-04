<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasUlids;

    protected $table = 'programs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => JsonDocument::class];
    }
}
