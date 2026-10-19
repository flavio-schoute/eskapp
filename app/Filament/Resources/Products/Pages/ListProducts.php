<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Jobs\SyncProducts;
use App\Models\Product;
use App\Services\PlugAndPay;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $synced = Product::query()->max('synced_at');

        $recentlyOrderedUnused = Product::query()->whereNull('deleted_in_plug_and_pay_at')->recentlyOrderedButNotInUse()->count();

        return 'Products in Plug&Pay with how often they were ordered (invoiced orders). Mark the products you use; the rest can be removed in Plug&Pay.'
            .($recentlyOrderedUnused > 0 ? " ⚠️ {$recentlyOrderedUnused} products marked as not in use were still ordered in the last 3 months: check those before removing them." : '')
            .($synced ? ' Synced '.Carbon::parse($synced)->diffForHumans().'.' : '');
    }

    public function getTabs(): array
    {
        return [
            'in_use' => Tab::make('In use')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->badge(fn (): int => Product::query()->whereNull('deleted_in_plug_and_pay_at')->where('in_use', true)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('in_use', true)),
            'not_in_use' => Tab::make('Not in use')
                ->icon(Heroicon::OutlinedTrash)
                ->badge(fn (): int => Product::query()->whereNull('deleted_in_plug_and_pay_at')->where('in_use', false)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('in_use', false)),
            'never_ordered' => Tab::make('Never ordered')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->badge(fn (): int => Product::query()->whereNull('deleted_in_plug_and_pay_at')->where('orders_count', 0)->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('orders_count', 0)),
            'all' => Tab::make('All')
                ->badge(fn (): int => Product::query()->whereNull('deleted_in_plug_and_pay_at')->count()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync with Plug&Pay')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => ! app(PlugAndPay::class)->isConfigured())
                ->action(function (): void {
                    SyncProducts::dispatch();

                    Notification::make()
                        ->success()
                        ->title('Products are being synced')
                        ->body('Counting the orders per product takes about a minute in the background. Refresh the page afterwards.')
                        ->send();
                }),
        ];
    }
}
