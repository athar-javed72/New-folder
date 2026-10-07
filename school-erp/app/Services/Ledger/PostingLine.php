<?php

declare(strict_types=1);

namespace App\Services\Ledger;

final readonly class PostingLine
{
    public function __construct(
        public string $account,
        public mixed $debitMinor,
        public mixed $creditMinor,
        public ?string $familyId = null,
        public ?string $description = null,
    ) {}

    public static function debit(string $account, mixed $amountMinor, ?string $familyId = null, ?string $description = null): self
    {
        return new self(
            account: $account,
            debitMinor: $amountMinor,
            creditMinor: 0,
            familyId: $familyId,
            description: $description,
        );
    }

    public static function credit(string $account, mixed $amountMinor, ?string $familyId = null, ?string $description = null): self
    {
        return new self(
            account: $account,
            debitMinor: 0,
            creditMinor: $amountMinor,
            familyId: $familyId,
            description: $description,
        );
    }
}
