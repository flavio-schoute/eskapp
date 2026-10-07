<?php

namespace App\Filament\Resources\Affiliates\Tables;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Models\Affiliate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class AffiliatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('payment_method')
            ->recordUrl(fn (Affiliate $record): string => AffiliateResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Partner name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                SelectColumn::make('status')
                    ->options(AffiliateStatus::class)
                    ->selectablePlaceholder(false)
                    ->afterStateUpdated(function (Component $livewire, Affiliate $record): void {
                        $livewire->dispatch('affiliate-status-updated');

                        Notification::make()
                            ->success()
                            ->title("{$record->name} is now {$record->status->getLabel()}")
                            ->send();
                    })
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Payment')
                    ->placeholder('-')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw(
                            'case payment_method when ? then 0 when ? then 1 when ? then 2 else 3 end '.($direction === 'desc' ? 'desc' : 'asc'),
                            [AffiliatePaymentMethod::Invoice->value, AffiliatePaymentMethod::Other->value, AffiliatePaymentMethod::Automatic->value],
                        )
                        ->orderBy('name')),
                TextColumn::make('agreement')
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('login_url')
                    ->label('Login URL')
                    ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(AffiliateStatus::class)
                    ->default(AffiliateStatus::Active->value),
                SelectFilter::make('type')
                    ->options(AffiliateType::class),
                SelectFilter::make('payment_method')
                    ->label('Payment')
                    ->options(AffiliatePaymentMethod::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->successNotificationTitle(fn (Affiliate $record): string => "Affiliate {$record->name} deleted"),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotificationTitle('Selected affiliates deleted'),
                ]),
            ]);
    }
}
