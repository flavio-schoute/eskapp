<?php

namespace App\Filament\Resources\SlackMembers;

use App\Filament\Resources\SlackMembers\Pages\ManageSlackMembers;
use App\Models\SlackMember;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class SlackMemberResource extends Resource
{
    protected static ?string $model = SlackMember::class;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Slack members';

    protected static ?string $modelLabel = 'Slack member';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slack_user_id')
                    ->label('Slack member ID')
                    ->helperText('In Slack: open the person\'s profile → ⋮ (more) → Copy member ID.')
                    ->placeholder('U0926AJE52T')
                    ->required()
                    ->regex('/^\s*[UW][A-Z0-9]{8,}\s*$/i')
                    ->validationMessages(['regex' => 'A Slack member ID starts with U or W, followed by capital letters and numbers.'])
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (string $state): string => strtoupper(trim($state))),
                Toggle::make('is_active')
                    ->label('Add to new affiliate channels')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('These people are invited to every new private affiliate channel.')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slack_user_id')
                    ->label('Slack member ID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),
                ToggleColumn::make('is_active')
                    ->label('Add to new channels')
                    ->afterStateUpdated(function (SlackMember $record, bool $state): void {
                        Notification::make()
                            ->success()
                            ->title($state
                                ? "{$record->name} will be added to new channels"
                                : "{$record->name} will no longer be added to new channels")
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->successNotificationTitle(fn (SlackMember $record): string => "Slack member {$record->name} saved"),
                DeleteAction::make()
                    ->successNotificationTitle(fn (SlackMember $record): string => "Slack member {$record->name} removed"),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Selected Slack members removed'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSlackMembers::route('/'),
        ];
    }
}
