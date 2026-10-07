<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The email domain of e-Skool staff, who are allowed into the admin panel.
     */
    public const StaffEmailDomain = 'e-skool.nl';

    /**
     * The only user who may change the invoice settings, such as creating invoices via the Mollie API.
     */
    public const InvoiceSettingsManagerEmail = 'flavio@e-skool.nl';

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
        ];
    }

    /**
     * Only e-Skool staff (an @e-skool.nl email address) may use the admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return Str::of($this->email)->lower()->endsWith('@'.self::StaffEmailDomain);
    }

    public function canManageInvoiceSettings(): bool
    {
        return Str::lower($this->email) === self::InvoiceSettingsManagerEmail;
    }
}
