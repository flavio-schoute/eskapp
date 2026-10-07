<?php

namespace App\Filament\Resources\IntegrationErrors;

use App\Enums\Integration;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\IntegrationErrors\Pages\ManageIntegrationErrors;
use App\Models\IntegrationError;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class IntegrationErrorResource extends Resource
{
    protected static ?string $model = IntegrationError::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Integration errors';

    protected static ?int $navigationSort = 2;

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

    public static function table(Table $table): Table
    {
        return $table
            ->description('Google Drive and Slack steps that failed. The affiliate itself was always saved.')
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
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (IntegrationError $record): string => $record->message)
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
            ->recordActions([
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
