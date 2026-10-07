<?php

namespace App\Filament\Resources\Affiliates\Widgets;

use App\Enums\AffiliateStatus;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Models\Affiliate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class PipelineAffiliates extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    #[On('affiliate-status-updated')]
    public function refreshPipeline(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->heading('In the pipeline')
            ->description('Partners we are talking to but are not live yet.')
            ->query(fn (): Builder => Affiliate::query()->where('status', AffiliateStatus::Pipeline))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Affiliate $record): string => AffiliateResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Partner name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge(),
                SelectColumn::make('status')
                    ->options(AffiliateStatus::class)
                    ->selectablePlaceholder(false)
                    ->afterStateUpdated(function (Affiliate $record): void {
                        $this->dispatch('affiliate-status-updated');

                        Notification::make()
                            ->success()
                            ->title("{$record->name} is now {$record->status->getLabel()}")
                            ->send();
                    }),
                TextColumn::make('payment_method')
                    ->label('Payment')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->since()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('addToPipeline')
                    ->label('Add to pipeline')
                    ->icon(Heroicon::OutlinedPlus)
                    ->outlined()
                    ->url(fn (): string => AffiliateResource::getUrl('create', ['status' => AffiliateStatus::Pipeline->value])),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label('Make active')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Affiliate $record): string => "{$record->name} will move to the active affiliates.")
                    ->action(function (Affiliate $record): void {
                        $record->update(['status' => AffiliateStatus::Active]);

                        $this->dispatch('affiliate-status-updated');

                        Notification::make()
                            ->success()
                            ->title("{$record->name} is now active")
                            ->send();
                    }),
            ])
            ->emptyStateIcon(AffiliateStatus::Pipeline->getIcon())
            ->emptyStateHeading('Nothing in the pipeline')
            ->emptyStateDescription('Add a partner with the status "In pipeline" to track it here.')
            ->paginated([5, 10, 25]);
    }
}
