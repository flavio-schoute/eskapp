<?php

namespace App\Filament\Concerns;

use App\Models\User;

/**
 * Limits a resource or page to people who can use Finance: admins and editors ("bewerkers").
 */
trait FinanceOnly
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canUseFinance();
    }
}
