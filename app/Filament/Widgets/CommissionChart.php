<?php

namespace App\Filament\Widgets;

use App\Models\AffiliateInvoice;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Commission invoiced to affiliates per month (excl. VAT), and how much of it has been paid.
 */
class CommissionChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Commission per month';

    protected ?string $description = 'Invoiced to affiliates, excl. VAT, by invoice period. Cancelled invoices are left out.';

    protected ?string $maxHeight = '300px';

    public ?string $filter = 'last_12_months';

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
            'this_year' => [now()->startOfYear(), now()->endOfYear()],
            'last_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            default => [now()->startOfMonth()->subMonthsNoOverflow(11), now()->endOfMonth()],
        };

        $invoices = AffiliateInvoice::query()
            ->whereBetween('period', [$from->toDateString(), $until->toDateString()])
            ->where('status', '!=', 'cancelled')
            ->get(['period', 'amount', 'status'])
            ->groupBy(fn (AffiliateInvoice $invoice): string => $invoice->period->format('Y-m'));

        $months = collect(range(0, (int) $from->diffInMonths($until)))
            ->map(fn (int $offset): Carbon => $from->copy()->addMonthsNoOverflow($offset));

        $sum = fn (string $month, bool $paidOnly): float => round((float) ($invoices->get($month) ?? collect())
            ->filter(fn (AffiliateInvoice $invoice): bool => ! $paidOnly || $invoice->isPaid())
            ->sum('amount'), 2);

        return [
            'datasets' => [
                [
                    'label' => 'Invoiced',
                    'data' => $months->map(fn (Carbon $month): float => $sum($month->format('Y-m'), false))->all(),
                    'backgroundColor' => 'rgba(245, 158, 11, 0.35)',
                    'borderColor' => 'rgb(245, 158, 11)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Paid',
                    'data' => $months->map(fn (Carbon $month): float => $sum($month->format('Y-m'), true))->all(),
                    'backgroundColor' => 'rgba(34, 197, 94, 0.6)',
                    'borderColor' => 'rgb(34, 197, 94)',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $months->map(fn (Carbon $month): string => $month->translatedFormat('M Y'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
