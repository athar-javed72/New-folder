<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use Carbon\CarbonInterface;

final readonly class PostingRequest
{
    /**
     * @param  list<PostingLine>  $lines
     */
    public function __construct(
        public string $organizationId,
        public string $campusId,
        public CarbonInterface $entryDate,
        public string $sourceType,
        public string $sourceId,
        public string $createdBy,
        public ?string $memo,
        public array $lines,
    ) {}
}
