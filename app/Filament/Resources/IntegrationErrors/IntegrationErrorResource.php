<?php

namespace App\Filament\Resources\IntegrationErrors;

use App\Enums\Integration;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\IntegrationErrors\Pages\ManageIntegrationErrors;
use App\Models\IntegrationError;
use App\Support\JsonCodeBlock;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class IntegrationErrorResource extends Resource
{
    protected static ?string $model = IntegrationError::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Integration errors';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = IntegrationError::unresolved()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Unresolved errors';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('integration')
                            ->badge(),
                        TextEntry::make('action')
                            ->label('Step'),
                        TextEntry::make('affiliate.name')
                            ->label('Affiliate')
                            ->url(fn (IntegrationError $record): ?string => $record->affiliate ? AffiliateResource::getUrl('view', ['record' => $record->affiliate]) : null)
                            ->color('primary')
                            ->placeholder('Deleted affiliate'),
                        TextEntry::make('last_occurred_at')
                            ->label('Last seen')
                            ->dateTime(),
                        TextEntry::make('occurrences')
                            ->label('Times'),
                        TextEntry::make('resolved_at')
                            ->label('Resolved')
                            ->dateTime()
                            ->placeholder('Not resolved'),
                    ]),
                TextEntry::make('error_summary')
                    ->label('Error')
                    ->state(fn (IntegrationError $record): string => $record->summary())
                    ->copyable()
                    ->copyableState(fn (IntegrationError $record): string => $record->message)
                    ->copyMessage('Full error copied')
                    ->helperText('Click to copy the full error, including the request body.')
                    ->columnSpanFull(),
                TextEntry::make('request_body')
                    ->label('Request body')
                    ->state(fn (IntegrationError $record): ?string => $record->requestBody())
                    ->formatStateUsing(fn (string $state): Htmlable => JsonCodeBlock::render($state, json_validate($state) ? 'JSON' : 'Text'))
                    ->copyable()
                    ->copyableState(fn (IntegrationError $record): ?string => $record->requestBody())
                    ->copyMessage('Request body copied')
                    ->visible(fn (IntegrationError $record): bool => $record->requestBody() !== null)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('Google Drive, Slack and Mollie steps that failed. The affiliate itself was always saved. Click an error to copy it, or open Details to see everything.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('affiliate'))
            ->defaultSort('last_occurred_at', 'desc')
            ->columns([
                IconColumn::make('resolved_at')
                    ->label('Resolved')
                    ->boolean()
                    ->state(fn (IntegrationError $record): bool => $record->isResolved()),
                TextColumn::make('last_occurred_at')
                    ->label('Last seen')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('integration')
                    ->badge(),
                TextColumn::make('action')
                    ->label('Step'),
                TextColumn::make('affiliate.name')
                    ->label('Affiliate')
                    ->url(fn (IntegrationError $record): ?string => $record->affiliate ? AffiliateResource::getUrl('view', ['record' => $record->affiliate]) : null)
                    ->color('primary')
                    ->placeholder('Deleted affiliate'),
                TextColumn::make('message')
                    ->label('Error')
                    ->formatStateUsing(fn (IntegrationError $record): string => $record->summary())
                    ->wrap()
                    ->lineClamp(2)
                    ->copyable()
                    ->copyableState(fn (IntegrationError $record): string => $record->message)
                    ->copyMessage('Full error copied')
                    ->searchable(),
                TextColumn::make('occurrences')
                    ->label('Times')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('resolved')
                    ->label('Status')
                    ->placeholder('All errors')
                    ->trueLabel('Resolved')
                    ->falseLabel('Unresolved')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('resolved_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('resolved_at'),
                    )
                    ->default(false),
                SelectFilter::make('integration')
                    ->options(Integration::class),
            ])
            ->recordAction('view')
            ->recordActions([
                ViewAction::make()
                    ->label('Details')
                    ->modalHeading(fn (IntegrationError $record): string => "{$record->integration->getLabel()} · {$record->action} failed")
                    ->modalWidth(Width::FourExtraLarge),
                Action::make('resolve')
                    ->label('Mark resolved')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->hidden(fn (IntegrationError $record): bool => $record->isResolved())
                    ->action(function (IntegrationError $record): void {
                        $record->update(['resolved_at' => now()]);

                        Notification::make()
                            ->success()
                            ->title('Error marked as resolved')
                            ->send();
                    }),
                DeleteAction::make()
                    ->successNotificationTitle('Error deleted'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('resolveSelected')
                        ->label('Mark resolved')
                        ->icon(Heroicon::OutlinedCheck)
                        ->color('success')
                        ->action(function (Collection $records): void {
                            IntegrationError::whereKey($records->modelKeys())->update(['resolved_at' => now()]);

                            Notification::make()
                                ->success()
                                ->title('Selected errors marked as resolved')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Selected errors deleted'),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('No integration errors')
            ->emptyStateDescription('Google Drive and Slack are syncing without problems.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIntegrationErrors::route('/'),
        ];
    }
}
