<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ContractStatus: string implements HasColor, HasIcon, HasLabel
{
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Signed = 'signed';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sent => 'Sent, waiting for signature',
            self::Viewed => 'Opened, waiting for signature',
            self::Signed => 'Signed',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sent, self::Viewed => 'warning',
            self::Signed => 'success',
            self::Declined, self::Withdrawn => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Sent => Heroicon::OutlinedPaperAirplane,
            self::Viewed => Heroicon::OutlinedEye,
            self::Signed => Heroicon::OutlinedCheckBadge,
            self::Declined => Heroicon::OutlinedHandThumbDown,
            self::Withdrawn => Heroicon::OutlinedXCircle,
        };
    }

    /**
     * Whether the contract is still out for signature, so its status can still change.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Sent, self::Viewed], true);
    }
}
