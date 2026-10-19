<?php

namespace App\Filament\Resources\Finance\Categories;

use App\Enums\Finance\CategoryKind;
use App\Filament\Concerns\FinanceOnly;
use App\Filament\Resources\Finance\Categories\Pages\ManageCategories;
use App\Models\Finance\Category;
use App\Models\Finance\RevenueStream;
use App\Services\Finance\Money;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Main groups with their subcategories. Lines are always booked on a subcategory; the P&L adds them up per group.
 */
class CategoryResource extends Resource
{
    use FinanceOnly;

    protected static ?string $model = Category::class;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Categorieën';

    protected static ?string $modelLabel = 'categorie';

    protected static ?string $pluralModelLabel = 'categorieën';

    protected static ?string $slug = 'finance/categories';

    /**
     * VAT rates to choose from.
     *
     * @var array<string, string>
     */
    public const VatRates = ['0.21' => '21%', '0.09' => '9%', '0' => '0%'];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Naam')
                    ->required()
                    ->maxLength(80),
                Textarea::make('description')
                    ->label('Uitleg (optioneel)')
                    ->placeholder('Wanneer gebruik je deze categorie, en telt hij mee in de W&V?')
                    ->helperText('Zichtbaar als je met de muis over het ⓘ-icoon gaat, en onder de categoriekeuze.')
                    ->rows(2)
                    ->autosize()
                    ->maxLength(1000),
                Select::make('parent_id')
                    ->label('Hoofdgroep')
                    ->placeholder('Geen: dit is zelf een hoofdgroep')
                    ->options(fn (?Category $record, string $operation): array => Category::query()->whereNull('parent_id')->when($operation === 'edit', fn ($query) => $query->whereKeyNot($record?->getKey()))->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->disabled(fn (?Category $record, string $operation): bool => $operation === 'edit' && (bool) $record?->children()->exists())
                    ->helperText(fn (?Category $record, string $operation): ?string => $operation === 'edit' && $record?->children()->exists() ? 'This group has subcategories, so it stays a main group.' : null)
                    ->live(),
                Grid::make(2)->schema([
                    Select::make('kind')
                        ->label('Soort')
                        ->options(CategoryKind::class)
                        ->default(CategoryKind::Expense)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live(),
                    TextInput::make('code')
                        ->label('Code (optioneel)')
                        ->placeholder('bijv. 130002')
                        ->unique(ignoreRecord: true)
                        ->maxLength(20),
                    Select::make('default_vat_rate')
                        ->label('Standaard btw')
                        ->options(self::VatRates)
                        ->placeholder('Geen')
                        ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : (string) (float) $state)
                        ->visible(fn (Get $get): bool => in_array($get('kind') instanceof CategoryKind ? $get('kind')->value : $get('kind'), ['expense', 'revenue'], true)),
                    Select::make('fixed_variable')
                        ->label('Vast / variabel')
                        ->options(['vast' => 'Vast', 'variabel' => 'Variabel'])
                        ->placeholder('—'),
                ]),
                ColorPicker::make('color')
                    ->label('Kleur'),
                Toggle::make('below_operating')
                    ->label('Tonen onder de operationele winst (zoals fiscale kosten)'),
                Grid::make(2)
                    ->visible(fn (Get $get): bool => ($get('kind') instanceof CategoryKind ? $get('kind')->value : $get('kind')) === 'expense')
                    ->schema([
                        TextInput::make('monthly_budget_cents')
                            ->label('Maandbudget (optioneel)')
                            ->prefix('€')
                            ->numeric()
                            ->minValue(0)
                            ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : (string) ($state / 100))
                            ->dehydrateStateUsing(fn (mixed $state): ?int => filled($state) ? Money::round((float) $state * 100) : null)
                            ->helperText('Melding in #finance als je er in een maand overheen gaat.'),
                        Select::make('revenue_stream_id')
                            ->label('Hoort bij omzetstroom')
                            ->options(fn (): array => RevenueStream::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                            ->placeholder('Geen')
                            ->helperText('Voor de marge per omzetstroom op het dashboard.'),
                        Toggle::make('is_ad_spend')
                            ->label('Telt als adspend (voor de ROAS)')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
