<?php

namespace App\Enums\Finance;

use Filament\Support\Contracts\HasLabel;

enum AccountType: string implements HasLabel
{
    case Bank = 'bank';
    case CreditCard = 'creditcard';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bank => 'Bankrekening',
            self::CreditCard => 'Creditcard',
            self::Manual => 'Handmatig',
        };
    }
}
