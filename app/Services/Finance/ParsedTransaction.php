<?php

namespace App\Services\Finance;

/**
 * A bank line normalized from any supported export format.
 */
final readonly class ParsedTransaction
{
    /**
     * @param  array<string, string>  $raw
     */
    public function __construct(
        public string $bookedOn,
        public int $amountCents,
        public string $currency,
        public string $counterpartyName,
        public ?string $counterpartyIban,
        public string $description,
        public ?string $mutationCode,
        public ?string $mutationType,
        public ?string $ownIban,
        public ?int $balanceAfterCents,
        public array $raw,
    ) {}
}
