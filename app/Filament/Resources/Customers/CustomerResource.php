<?php

namespace App\Filament\Resources\Customers;

use App\Enums\CustomerMatch;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\DebtorCasesRelationManager;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * e-Skool's customers from Plug&Pay, matched with Mollie, with their contact details and debtor cases.
 */
class CustomerResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Customer::class;

    protected static string|UnitEnum|null $navigationGroup = 'Debiteurenbeheer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Klanten';

    protected static ?string $modelLabel = 'klant';

    protected static ?string $pluralModelLabel = 'klanten';

    protected static ?string $slug = 'debtors/customers';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->helperText(fn (Customer $record): ?string => $record->company)
                            ->placeholder('-'),
                        TextEntry::make('email')
                            ->url(fn (string $state): string => "mailto:{$state}")
                            ->copyable(),
                        TextEntry::make('phone')
                            ->url(fn (?string $state): ?string => $state ? 'tel:'.preg_replace('/[^\d+]/', '', $state) : null)
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('address')
                            ->state(fn (Customer $record): ?string => $record->addressLine())
                            ->copyable()
                            ->placeholder('-')
                            ->columnSpan(2),
                        TextEntry::make('vat_number')
                            ->label('VAT number')
                            ->placeholder('-'),
                    ]),
                Section::make('Plug&Pay')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('orders_count')
                            ->label('Orders'),
                        TextEntry::make('total_amount')
                            ->label('Total')
                            ->money('EUR', locale: 'nl'),
                        TextEntry::make('open_amount')
                            ->label('Open')
                            ->money('EUR', locale: 'nl')
                            ->color(fn (Customer $record): ?string => (float) $record->open_amount > 0 ? 'danger' : null),
                        TextEntry::make('last_order_at')
                            ->label('Last order')
                            ->date('j M Y')
                            ->helperText(fn (Customer $record): ?string => $record->customer_since ? 'Customer since '.$record->customer_since->translatedFormat('j M Y') : null)
                            ->placeholder('-'),
                    ]),
                Section::make('Mollie')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('match_status')
                            ->label('Match')
                            ->badge()
                            ->helperText(fn (Customer $record): string => $record->match_status->getDescription()),
                        TextEntry::make('mollie_name')
                            ->label('Name in Mollie')
                            ->helperText(fn (Customer $record): ?string => $record->mollie_email)
                            ->placeholder('-'),
                        TextEntry::make('mollie_customer_ids')
                            ->label('Mollie customers')
                            ->state(fn (Customer $record): ?HtmlString => $record->mollie_customer_ids
                                ? new HtmlString(collect($record->mollie_customer_ids)
                                    ->map(fn (string $id): string => '<a href="'.e($record->mollieDashboardUrl($id)).'" target="_blank" style="color: var(--color-primary-600); font-family: monospace;">'.e($id).'</a>')
                                    ->implode('<br>'))
                                : null)
                            ->placeholder('-'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->description(fn (): string => 'From Plug&Pay, matched with Mollie. Synced every night'.(($synced = Customer::query()->max('synced_at')) ? ', last on '.Carbon::parse($synced)->timezone('Europe/Amsterdam')->translatedFormat('j M Y H:i') : '').'.')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Customer $record): ?string => $record->company)
                    ->searchable(['name', 'company'])
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('city')
                    ->description(fn (Customer $record): ?string => $record->country)
                    ->searchable(['city', 'postal_code', 'street'])
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('EUR', locale: 'nl')
                    ->sortable(),
                TextColumn::make('open_amount')
                    ->label('Open')
                    ->money('EUR', locale: 'nl')
                    ->color(fn (Customer $record): ?string => (float) $record->open_amount > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('match_status')
                    ->label('Mollie')
                    ->badge()
                    ->tooltip(fn (Customer $record): string => $record->match_status->getDescription()),
            ])
            ->filters([
                SelectFilter::make('match_status')
                    ->label('Mollie match')
                    ->options(CustomerMatch::class),
                TernaryFilter::make('open')
                    ->label('Open amount')
                    ->trueLabel('Has an open amount')
                    ->falseLabel('Nothing open')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('open_amount', '>', 0),
                        false: fn (Builder $query): Builder => $query->where('open_amount', '<=', 0),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DebtorCasesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
