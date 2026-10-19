<?php

namespace App\Filament\Pages\Finance;

use App\Filament\Concerns\FinanceOnly;
use App\Filament\Widgets\Finance\CashflowForecastChart;
use App\Filament\Widgets\Finance\CostsByGroupChart;
use App\Filament\Widgets\Finance\FinanceNotice;
use App\Filament\Widgets\Finance\FinanceStats;
use App\Filament\Widgets\Finance\OperatingProfitChart;
use App\Filament\Widgets\Finance\ProfitAndLossTable;
use App\Filament\Widgets\Finance\RevenueAndCostsChart;
use App\Filament\Widgets\Finance\RevenueByStreamChart;
use App\Filament\Widgets\Finance\StreamMarginTable;
use App\Services\Finance\FinanceReport;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Profit & loss on a cash basis, excl. VAT: tiles, charts and the P&L per month, for a year and period.
 */
class FinanceDashboard extends Dashboard
{
    use FinanceOnly;
    use HasFiltersForm;

    protected static string $routePath = 'finance';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Finance dashboard';

    public static function getNavigationLabel(): string
    {
        return 'Dashboard';
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedPresentationChartBar;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Winst & verlies op kasbasis, excl. btw · '.FinanceReport::dataThrough();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(['md' => 3, 'xl' => 4])
            ->components([
                Select::make('year')
                    ->hiddenLabel()
                    ->options(fn (): array => collect(FinanceReport::years())->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all())
                    ->default(fn (): int => last(FinanceReport::years()))
                    ->selectablePlaceholder(false)
                    ->afterStateUpdated(fn (mixed $state, Set $set) => (int) $state !== (int) now()->year ? $set('period', 'year') : $set('period', 'ytd')),
                Select::make('period')
                    ->hiddenLabel()
                    ->options(fn (Get $get): array => (new FinanceReport(['year' => $get('year')]))->periodOptions())
                    ->default(fn (): string => (new FinanceReport)->period())
                    ->selectablePlaceholder(false),
                Toggle::make('compare')
                    ->label(fn (Get $get): string => 'Vergelijk met '.(((int) ($get('year') ?? now()->year)) - 1))
                    ->default(true)
                    ->inline()
                    ->disabled(fn (Get $get): bool => ! (new FinanceReport(['year' => $get('year'), 'period' => $get('period')]))->hasPrevious()),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            FinanceNotice::class,
            FinanceStats::class,
            RevenueAndCostsChart::class,
            OperatingProfitChart::class,
            RevenueByStreamChart::class,
            CostsByGroupChart::class,
            CashflowForecastChart::class,
            StreamMarginTable::class,
            ProfitAndLossTable::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'xl' => 2];
    }
}
