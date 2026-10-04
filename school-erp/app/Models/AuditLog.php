<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Append-only (database trigger). */
class AuditLog extends Model
{
    use HasUlids;

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => JsonDocument::class, 'after' => JsonDocument::class, 'meta' => JsonDocument::class, 'created_at' => 'datetime'];
    }
}
