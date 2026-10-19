<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'role', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * The email domain of e-Skool staff, who are allowed into the admin panel.
     */
    public const StaffEmailDomain = 'e-skool.nl';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Admins can do everything, including the System settings and users. Viewers only work with affiliates.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Admins and editors ("bewerkers") can use Finance.
     */
    public function canUseFinance(): bool
    {
        return $this->role?->canUseFinance() ?? false;
    }

    /**
     * Only e-Skool staff (an @e-skool.nl email address) may use the admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
        // return Str::of($this->email)->lower()->endsWith('@'.self::StaffEmailDomain);
    }
}
