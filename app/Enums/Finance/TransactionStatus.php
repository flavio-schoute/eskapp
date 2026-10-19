<?php

namespace App\Enums\Finance;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransactionStatus: string implements HasColor, HasLabel
{
    /**
     * Not categorized yet: waits in the inbox.
     */
    case Uncategorized = 'uncategorized';

    /**
     * Categorized by a rule: counts in the P&L, but waits in the inbox for someone to confirm.
     */
    case Auto = 'auto';

    /**
     * Categorized or confirmed by a person; rules never change it again.
     */
    case Confirmed = 'confirmed';

    /**
     * Left out of the P&L on purpose.
     */
    case Excluded = 'excluded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Uncategorized => 'Open',
            self::Auto => 'Voorgesteld',
            self::Confirmed => 'Bevestigd',
            self::Excluded => 'Uitgesloten',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Uncategorized => 'warning',
            self::Auto => 'info',
            self::Confirmed => 'success',
            self::Excluded => 'gray',
        };
    }

    /**
     * Whether the line still waits in the inbox.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Uncategorized, self::Auto], true);
    }
}
