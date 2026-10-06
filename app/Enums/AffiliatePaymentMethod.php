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
}
