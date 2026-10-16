<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How well a customer in Plug&Pay matches a customer in Mollie.
 */
enum CustomerMatch: string implements HasColor, HasDescription, HasLabel
{
    /**
     * The Mollie customer is linked to the customer's Plug&Pay payments, and name and email agree.
     */
    case Linked = 'linked';

    /**
     * Linked or found by email, but the name or email differs between Plug&Pay and Mollie.
     */
    case Mismatch = 'mismatch';

    /**
     * Found in Mollie by email address only.
     */
    case Email = 'email';

    case PlugAndPayOnly = 'plug_and_pay_only';

    case MollieOnly = 'mollie_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::Linked => 'Matched',
            self::Mismatch => 'Check details',
            self::Email => 'Matched by email',
            self::PlugAndPayOnly => 'Only Plug&Pay',
            self::MollieOnly => 'Only Mollie',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Linked => 'success',
            self::Mismatch => 'warning',
            self::Email => 'info',
            self::PlugAndPayOnly, self::MollieOnly => 'gray',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Linked => 'The Mollie customer is used for this customer\'s Plug&Pay payments, and name and email agree.',
            self::Mismatch => 'The name or email address differs between Plug&Pay and Mollie.',
            self::Email => 'A Mollie customer with the same email address, not (yet) used for Plug&Pay payments.',
            self::PlugAndPayOnly => 'No Mollie customer, e.g. paid by bank transfer or not paid yet.',
            self::MollieOnly => 'Only in Mollie, not in Plug&Pay.',
        };
    }
}
