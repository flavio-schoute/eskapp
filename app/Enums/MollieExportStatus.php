<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MollieExportStatus: string implements HasColor, HasLabel
{
    case NotExported = 'not_exported';
    case ChangedSinceExport = 'changed_since_export';
    case Exported = 'exported';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotExported => 'Not exported',
            self::ChangedSinceExport => 'Changed since export',
            self::Exported => 'Exported',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotExported => 'gray',
            self::ChangedSinceExport => 'warning',
            self::Exported => 'success',
        };
    }
}
