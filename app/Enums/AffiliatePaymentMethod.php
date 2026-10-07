<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AffiliatePaymentMethod: string implements HasLabel
{
    case Automatic = 'automatic';
    case Invoice = 'invoice';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Automatic => 'Automatic',
            self::Invoice => 'Invoice',
            self::Other => 'Other',
        };
    }

    /**
     * Whether affiliates paid this way need invoice details (everything except automatic payouts).
     */
    public function requiresInvoiceDetails(): bool
    {
        return $this !== self::Automatic;
    }

    /**
     * Resolve a form or database value to a payment method.
     */
    public static function fromState(self|string|null $state): ?self
    {
        return $state instanceof self ? $state : self::tryFrom((string) $state);
    }
}
