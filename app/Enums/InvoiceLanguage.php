<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvoiceLanguage: string implements HasLabel
{
    case Dutch = 'nl';
    case English = 'en';
    case French = 'fr';
    case German = 'de';

    public function getLabel(): string
    {
        return $this->name;
    }

    /**
     * Suggest the invoice language for a country; English when there is no better match.
     */
    public static function suggestedFor(?string $countryCode): self
    {
        return match ($countryCode) {
            'NL', 'BE', 'SR' => self::Dutch,
            'FR', 'LU', 'MC' => self::French,
            'DE', 'AT', 'CH', 'LI' => self::German,
            default => self::English,
        };
    }

    /**
     * Get the Mollie locale for this language, using the regional variant for the country when Mollie has one.
     */
    public function mollieLocale(?string $countryCode): string
    {
        return match ($this) {
            self::Dutch => $countryCode === 'BE' ? 'nl_BE' : 'nl_NL',
            self::French => $countryCode === 'BE' ? 'fr_BE' : 'fr_FR',
            self::German => match ($countryCode) {
                'AT' => 'de_AT',
                'CH' => 'de_CH',
                default => 'de_DE',
            },
            self::English => $countryCode === 'GB' ? 'en_GB' : 'en_US',
        };
    }
}
