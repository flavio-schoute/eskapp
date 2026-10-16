<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Jobs\SyncCustomers;
use App\Services\PlugAndPay;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync with Plug&Pay and Mollie')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => ! app(PlugAndPay::class)->isConfigured())
                ->action(function (): void {
                    SyncCustomers::dispatch();

                    Notification::make()
                        ->success()
                        ->title('Customers are being synced')
                        ->body('This takes about a minute in the background. Refresh the page afterwards.')
                        ->send();
                }),
        ];
    }
}
