<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Support\RevenueReport;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Commission invoiced to affiliates and received from them per month (excl. VAT), counted like the Revenue page.
 */
class CommissionChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Commission per month';

    protected ?string $description = 'Excl. VAT. Invoiced counts by invoice date, received by payment date, like the Revenue page. Cancelled invoices are left out.';

    protected ?string $maxHeight = '300px';

    public ?string $filter = 'last_12_months';

    /**
     * Revenue is for admins only, like the Revenue page.
     */
    public static function canView(): bool
    {
        return auth()->user() instanceof User && auth()->user()->isAdmin();
    }

    protected function getFilters(): ?array
    {
        return [
            'last_12_months' => 'Last 12 months',
            'this_year' => 'This year ('.now()->year.')',
            'last_year' => 'Last year ('.(now()->year - 1).')',
        ];
    }

    protected function getData(): array
    {
        [$from, $until] = match ($this->filter) {
            'this_year' => [now(RevenueReport::Timezone)->startOfYear(), now(RevenueReport::Timezone)->endOfYear()],
            'last_year' => [now(RevenueReport::Timezone)->subYear()->startOfYear(), now(RevenueReport::Timezone)->subYear()->endOfYear()],
            default => [now(RevenueReport::Timezone)->startOfMonth()->subMonthsNoOverflow(11), now(RevenueReport::Timezone)->endOfMonth()],
        };

        $months = collect(range(0, (int) $from->diffInMonths($until)))
            ->map(fn (int $offset): array => RevenueReport::forMonth($from->copy()->addMonthsNoOverflow($offset)));

        return [
            'datasets' => [
                [
                    'label' => 'Invoiced',
                    'data' => $months->pluck('invoiced')->all(),
                    'backgroundColor' => 'rgba(245, 158, 11, 0.35)',
                    'borderColor' => 'rgb(245, 158, 11)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Received',
                    'data' => $months->pluck('received')->all(),
                    'backgroundColor' => 'rgba(34, 197, 94, 0.6)',
                    'borderColor' => 'rgb(34, 197, 94)',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $months->map(fn (array $month): string => Carbon::parse("{$month['month']}-01")->translatedFormat('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
