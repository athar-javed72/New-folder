<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use HasUlids;

    protected $table = 'leave_types';

    protected $guarded = [];
}
