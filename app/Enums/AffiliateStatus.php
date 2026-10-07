<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum AffiliateStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pipeline = 'pipeline';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Stopped = 'stopped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pipeline => 'In pipeline',
            self::Active => 'Active',
            self::OnHold => 'On hold',
            self::Stopped => 'Stopped',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pipeline => 'info',
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Stopped => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pipeline => Heroicon::OutlinedSparkles,
            self::Active => Heroicon::OutlinedCheckCircle,
            self::OnHold => Heroicon::OutlinedPauseCircle,
            self::Stopped => Heroicon::OutlinedXCircle,
        };
    }
}
