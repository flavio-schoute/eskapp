<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasDescription, HasLabel
{
    case Admin = 'admin';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return $this->name;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'primary',
            self::Viewer => 'gray',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Admin => 'Everything, including the System settings and users.',
            self::Viewer => 'Affiliates, invoices and contracts, but not the System settings.',
        };
    }
}
