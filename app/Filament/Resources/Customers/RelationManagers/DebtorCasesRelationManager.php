<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\DebtorCases\DebtorCaseResource;
use App\Models\DebtorCase;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The customer's unpaid and charged back invoices, open and resolved.
 */
class DebtorCasesRelationManager extends RelationManager
{
    protected static string $relationship = 'debtorCases';

    protected static ?string $title = 'Debtor cases';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('invoice_date', 'desc')
            ->recordUrl(fn (DebtorCase $record): string => DebtorCaseResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->description(fn (DebtorCase $record): ?string => $record->product),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('amount')
                    ->label('Amount excl. VAT')
                    ->money('EUR', locale: 'nl'),
                TextColumn::make('invoice_date')
                    ->label('Invoice date')
                    ->date('j M Y'),
                TextColumn::make('resolved_at')
                    ->label('Status')
                    ->state(fn (DebtorCase $record): string => $record->resolved_at ? 'Resolved' : 'Open')
                    ->badge()
                    ->color(fn (DebtorCase $record): string => $record->resolved_at ? 'success' : 'danger'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Bekijk dossier')
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->url(fn (DebtorCase $record): string => DebtorCaseResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
