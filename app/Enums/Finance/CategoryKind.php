<?php

namespace App\Enums\Finance;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a category is for. Only costs and revenue count in the P&L; transfers, financing, tax and excluded lines never do.
 */
enum CategoryKind: string implements HasColor, HasLabel
{
    case Expense = 'expense';
    case Revenue = 'revenue';
    case Transfer = 'transfer';
    case Financing = 'financing';
    case Tax = 'tax';
    case Excluded = 'excluded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Expense => 'Kosten',
            self::Revenue => 'Omzet',
            self::Transfer => 'Interne overboeking',
            self::Financing => 'Financiering',
            self::Tax => 'Belasting',
            self::Excluded => 'Uitgesloten',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Expense => 'gray',
            self::Revenue => 'success',
            default => 'gray',
        };
    }

    /**
     * Whether lines in this kind of category count in the P&L (and so carry VAT).
     */
    public function countsInProfitAndLoss(): bool
    {
        return in_array($this, [self::Expense, self::Revenue], true);
    }
}
