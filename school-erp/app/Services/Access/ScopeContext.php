<?php

declare(strict_types=1);

namespace App\Services\Access;

readonly class ScopeContext
{
    public function __construct(
        public ?string $organizationId = null,
        public ?string $campusId = null,
        public ?string $programId = null,
        public ?string $gradeId = null,
        public ?string $sectionId = null,
        public ?string $sessionId = null,
    ) {}
}
