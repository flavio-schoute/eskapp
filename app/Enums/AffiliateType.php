<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AffiliateType: string implements HasLabel
{
    case Affiliate = 'affiliate';
    case Partnership = 'partnership';
    case Software = 'software';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Affiliate => 'Affiliate',
            self::Partnership => 'Partnership',
            self::Software => 'Software',
            self::Other => 'Other',
        };
    }
}
