<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The profile page, inside the panel under Settings. For now only the name can be changed; changing the password
 * and an avatar are shown as coming soon.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->schema([
                        $this->getNameFormComponent(),
                    ]),
                Section::make(static::comingSoon('Avatar'))
                    ->description('Upload a profile picture.')
                    ->schema([
                        FileUpload::make('avatar')
                            ->hiddenLabel()
                            ->avatar()
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                Section::make(static::comingSoon('Password'))
                    ->description('Change your password. Until then, use "Forgot password?" on the login page.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('new_password')
                            ->label('New password')
                            ->password()
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('new_password_confirmation')
                            ->label('Confirm new password')
                            ->password()
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }

    /**
     * A section heading with a "Coming soon" badge.
     */
    protected static function comingSoon(string $heading): HtmlString
    {
        return new HtmlString(e($heading).' <span style="margin-inline-start: 0.5rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; background: rgb(127 127 127 / 0.12); opacity: 0.8;">🔒 Coming soon</span>');
    }
}
