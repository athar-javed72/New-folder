<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemPolicy extends Model
{
    use HasUlids;

    protected $table = 'system_policies';

    protected $fillable = ['preset_id', 'type', 'key', 'value', 'override_levels', 'editable_by', 'schema_version', 'checksum'];

    protected function casts(): array
    {
        return ['value' => JsonDocument::class, 'override_levels' => JsonDocument::class, 'editable_by' => JsonDocument::class];
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(Preset::class);
    }
}
