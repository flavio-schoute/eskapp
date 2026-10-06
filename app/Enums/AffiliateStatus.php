<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AffiliateStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Stopped = 'stopped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnHold => 'On hold',
            self::Stopped => 'Stopped',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Stopped => 'danger',
        };
    }
}
