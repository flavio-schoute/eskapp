<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use UnitEnum;

class UserResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = User::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Radio::make('role')
                    ->options(UserRole::class)
                    ->default(UserRole::Viewer)
                    ->required()
                    ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                    ->helperText(fn (?User $record): ?string => $record?->is(auth()->user()) ? 'You cannot change your own role.' : null),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->rule(PasswordRule::defaults())
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (string $operation): string => $operation === 'create'
                        ? 'Share it in person, or use "Send password reset link" so they choose their own.'
                        : 'Leave empty to keep the current password.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->heading('Users')
            ->description('Who can sign in. Admins can do everything; viewers work with affiliates, invoices and contracts, but cannot open the System settings.')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $record): ?string => $record->is(auth()->user()) ? 'You' : null),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('role')
                    ->badge()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date('j M Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(UserRole::class),
            ])
            ->recordActions([
                Action::make('sendPasswordReset')
                    ->label('Send password reset link')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record): string => "{$record->email} receives an email with a link to choose a new password.")
                    ->action(function (User $record): void {
                        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                            ['email' => $record->email],
                            function (User $user, string $token): void {
                                $notification = app(ResetPassword::class, ['token' => $token]);
                                $notification->url = Filament::getResetPasswordUrl($token, $user);

                                $user->notify($notification);
                            },
                        );

                        $status === Password::RESET_LINK_SENT
                            ? Notification::make()->success()->title("Password reset link sent to {$record->email}")->send()
                            : Notification::make()->warning()->title(__($status))->send();
                    }),
                EditAction::make()
                    ->successNotificationTitle(fn (User $record): string => "User {$record->name} saved"),
                DeleteAction::make()
                    ->hidden(fn (User $record): bool => $record->is(auth()->user()) || static::isLastAdmin($record))
                    ->successNotificationTitle(fn (User $record): string => "User {$record->name} removed"),
            ]);
    }

    /**
     * There must always be an admin left who can manage users and settings.
     */
    public static function isLastAdmin(User $user): bool
    {
        return $user->isAdmin() && User::query()->where('role', UserRole::Admin)->count() <= 1;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
