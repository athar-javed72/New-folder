<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ContractType extends Model
{
    use HasUlids;

    protected $table = 'contract_types';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['leave_rules' => JsonDocument::class, 'pay_rules' => JsonDocument::class, 'benefits' => JsonDocument::class];
    }
}
