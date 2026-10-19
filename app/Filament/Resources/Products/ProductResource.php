<?php

namespace App\Filament\Resources\Products;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The products in Plug&Pay: which ones e-Skool uses, and which can be removed.
 */
class ProductResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Product::class;

    protected static string|UnitEnum|null $navigationGroup = 'Debiteurenbeheer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Producten';

    protected static ?string $modelLabel = 'product';

    protected static ?string $pluralModelLabel = 'producten';

    protected static ?string $slug = 'debtors/products';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('deleted_in_plug_and_pay_at'))
            ->defaultSort('orders_count', 'desc')
            ->columns([
                TextColumn::make('plug_and_pay_product_id')
                    ->label('Product ID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyMessage('Product ID copied')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->description(fn (Product $record): ?string => $record->public_title !== $record->title ? $record->public_title : null)
                    ->searchable(['title', 'public_title', 'sku'])
                    ->wrap()
                    ->sortable(),
                TextColumn::make('type')
                    ->formatStateUsing(fn (Product $record): ?string => $record->typeLabel())
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->sortable()
                    ->color(fn (int $state): ?string => $state === 0 ? 'danger' : null)
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Never ordered' : (string) $state),
                TextColumn::make('last_ordered_at')
                    ->label('Last ordered')
                    ->date('j M Y')
                    ->description(fn (Product $record): ?string => (! $record->in_use && $record->isRecentlyOrdered())
                        ? '⚠️ Still ordered recently, check before removing'
                        : $record->last_ordered_at?->diffForHumans())
                    ->color(fn (Product $record): ?string => (! $record->in_use && $record->isRecentlyOrdered()) ? 'warning' : null)
                    ->placeholder('-')
                    ->sortable(),
                ToggleColumn::make('in_use')
                    ->label('In use')
                    ->afterStateUpdated(fn (Product $record, bool $state) => Notification::make()
                        ->success()
                        ->title($state ? "{$record->plug_and_pay_product_id} marked as in use" : "{$record->plug_and_pay_product_id} marked as not in use")
                        ->send())
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('in_use')
                    ->label('In use')
                    ->trueLabel('In use')
                    ->falseLabel('Not in use'),
                TernaryFilter::make('ordered')
                    ->label('Orders')
                    ->trueLabel('Ordered at least once')
                    ->falseLabel('Never ordered')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('orders_count', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('orders_count', 0),
                    ),
                SelectFilter::make('type')
                    ->options(['one_off' => 'One-off', 'installments' => 'Installments', 'subscription' => 'Subscription']),
            ])
            ->recordActions([
                Action::make('plugAndPay')
                    ->label('Open in Plug&Pay')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (Product $record): string => $record->plugAndPayUrl(), shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
        ];
    }
}
