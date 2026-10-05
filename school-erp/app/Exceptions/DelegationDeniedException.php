<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Access\DelegationDeniedException as BaseDelegationDeniedException;

class DelegationDeniedException extends BaseDelegationDeniedException {}
