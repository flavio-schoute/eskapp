<?php

namespace App\Filament\Concerns;

use App\Models\User;

/**
 * Limits a resource or page to admins: viewers only work with affiliates, not with the System settings.
 */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin();
    }
}
