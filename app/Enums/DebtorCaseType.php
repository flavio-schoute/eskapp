<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum DebtorCaseType: string implements HasColor, HasIcon, HasLabel
{
    /**
     * An invoice that was never paid: payment status open with a final invoice in Plug&Pay.
     */
    case Unpaid = 'unpaid';

    /**
     * A payment that was charged back ("gestorneerd"): payment status reversed in Plug&Pay.
     */
    case ChargedBack = 'charged_back';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unpaid => 'Onbetaald',
            self::ChargedBack => 'Gestorneerd',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unpaid => 'warning',
            self::ChargedBack => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Unpaid => Heroicon::OutlinedClock,
            self::ChargedBack => Heroicon::OutlinedArrowUturnLeft,
        };
    }
}
