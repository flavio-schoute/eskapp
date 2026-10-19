<?php

namespace App\Filament\Resources\Finance\Transactions\Pages;

use App\Filament\Resources\Finance\Transactions\TransactionResource;
use App\Models\Finance\Transaction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [TransactionResource::addInvoiceAction()];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return number_format(Transaction::query()->count(), 0, ',', '.').' regels';
    }
}
