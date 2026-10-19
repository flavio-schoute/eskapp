<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasDescription, HasLabel
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Editor => 'Bewerker',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Whether this role can use Finance (the books: bank transactions, rules, categories and the P&L).
     */
    public function canUseFinance(): bool
    {
        return in_array($this, [self::Admin, self::Editor], true);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'primary',
            self::Editor => 'info',
            self::Viewer => 'gray',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Admin => 'Everything, including the System settings and users.',
            self::Editor => 'Affiliates, invoices, contracts and Finance, but not the System settings.',
            self::Viewer => 'Affiliates, invoices and contracts, but not the System settings.',
        };
    }
}
