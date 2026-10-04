<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Append-only (database trigger). Never update or delete: post a reversal entry instead. */
class LeaveLedger extends Model
{
    use HasUlids;

    protected $table = 'leave_ledgers';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:2', 'entry_date' => 'date', 'created_at' => 'datetime'];
    }
}
