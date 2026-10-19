<?php

namespace App\Filament\Resources\Finance\Accounts;

use App\Enums\Finance\AccountType;
use App\Filament\Concerns\FinanceOnly;
use App\Filament\Resources\Finance\Accounts\Pages\ManageAccounts;
use App\Models\Finance\Account;
use App\Services\Finance\BankCsvParser;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The bank accounts and credit cards whose exports are imported.
 */
class AccountResource extends Resource
{
    use FinanceOnly;

    protected static ?string $model = Account::class;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Rekeningen';

    protected static ?string $modelLabel = 'rekening';

    protected static ?string $pluralModelLabel = 'rekeningen';

    protected static ?string $slug = 'finance/accounts';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Naam')
                    ->required()
                    ->maxLength(80),
                Select::make('type')
                    ->label('Soort')
                    ->options(AccountType::class)
                    ->default(AccountType::Bank)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('iban')
                    ->label('IBAN')
                    ->placeholder('NL00BANK0123456789')
                    ->helperText('Used to warn when an export belongs to another account.')
                    ->maxLength(40)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(preg_replace('/\s/', '', $state)) : null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('transactions'))
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->description(fn (Account $record): ?string => $record->iban)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Soort')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('bank_format')
                    ->label('Formaat')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? (BankCsvParser::Formats[$state] ?? $state) : null)
                    ->placeholder('-'),
                TextColumn::make('transactions_count')
                    ->label('Transacties')
                    ->sortable(),
                TextColumn::make('archived')
                    ->label('Status')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Gearchiveerd' : 'Actief')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'gray' : 'success'),
            ])
            ->filters([
                TernaryFilter::make('archived')
                    ->label('Gearchiveerd')
                    ->default(false),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('archive')
                    ->label(fn (Account $record): string => $record->archived ? 'Herstellen' : 'Archiveren')
                    ->icon(fn (Account $record): Heroicon => $record->archived ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->action(fn (Account $record) => $record->update(['archived' => ! $record->archived])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccounts::route('/'),
        ];
    }
}
