<?php

declare(strict_types=1);

namespace App\Domain\Fees;

use RuntimeException;

final class UnsupportedLateFeeMethod extends RuntimeException {}
