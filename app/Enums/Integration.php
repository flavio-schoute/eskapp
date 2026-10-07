<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Integration: string implements HasColor, HasLabel
{
    case GoogleDrive = 'google_drive';
    case Slack = 'slack';
    case Mollie = 'mollie';

    public function getLabel(): string
    {
        return match ($this) {
            self::GoogleDrive => 'Google Drive',
            self::Slack => 'Slack',
            self::Mollie => 'Mollie',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::GoogleDrive => 'info',
            self::Slack => 'primary',
            self::Mollie => 'success',
        };
    }
}
