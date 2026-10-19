<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Finance\Cashflow;
use App\Services\Finance\FinanceReport;
use App\Services\Finance\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * The bank balance at each month end and where it goes in the next three months at the recent pace.
 */
class CashflowForecastChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    /**
     * @var array{balance: int|null, balanceOn: string|null, monthlyFlow: int|null, history: array<string, int>, forecast: array<string, int>}|null
     */
    private ?array $cashflow = null;

    public function getHeading(): string
    {
        return 'Cashflow-prognose';
    }

    public function getDescription(): ?string
    {
        $cashflow = $this->cashflow();

        if ($cashflow['balance'] === null) {
            return 'Nog geen saldo: importeer een bankexport met de kolom saldo na mutatie.';
        }

        $flow = $cashflow['monthlyFlow'] === null ? '' : ' · gemiddeld '.($cashflow['monthlyFlow'] >= 0 ? '+' : '').Money::format($cashflow['monthlyFlow'], decimals: false).' per maand (laatste 3 volle maanden)';

        return 'Saldo '.Money::format($cashflow['balance'], decimals: false)." op {$cashflow['balanceOn']}{$flow}";
    }

    protected function getData(): array
    {
        $cashflow = $this->cashflow();
        $months = [...array_keys($cashflow['history']), ...array_keys($cashflow['forecast'])];
        $current = array_key_last($cashflow['history']);

        return [
            'datasets' => [
                [
                    'label' => 'Saldo',
                    'data' => array_map(fn (string $month): ?float => isset($cashflow['history'][$month]) ? $cashflow['history'][$month] / 100 : null, $months),
                    'borderColor' => '#2563EB',
                    'backgroundColor' => '#2563EB',
                    'pointRadius' => 3,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Prognose',
                    // Starts at the current balance, so the dashed line continues the solid one.
                    'data' => array_map(fn (string $month): ?float => match (true) {
                        isset($cashflow['forecast'][$month]) => $cashflow['forecast'][$month] / 100,
                        $month === $current && $cashflow['forecast'] !== [] => $cashflow['history'][$month] / 100,
                        default => null,
                    }, $months),
                    'borderColor' => '#A39E93',
                    'backgroundColor' => '#A39E93',
                    'borderDash' => [6, 4],
                    'pointRadius' => 3,
                    'tension' => 0.3,
                ],
            ],
            'labels' => array_map(fn (string $month): string => FinanceReport::monthLabel($month).' '.substr($month, 2, 2), $months),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                animation: { duration: 350, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                spanGaps: false,
                plugins: {
                    financeHoverBand: { enabled: true },
                    legend: { display: true, position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, boxHeight: 7, padding: 14, font: { size: 12 } } },
                    tooltip: { enabled: false, external: (context) => window.financeChartTooltip?.(context) },
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 12 } } },
                    y: {
                        border: { display: false },
                        grid: { color: 'rgba(127, 127, 127, 0.14)', drawTicks: false },
                        ticks: {
                            padding: 8,
                            maxTicksLimit: 5,
                            font: { size: 12 },
                            callback: (value) => Math.abs(value) >= 1000 ? '€' + Math.round(value / 1000) + 'K' : '€' + value,
                        },
                    },
                },
            }
        JS);
    }

    /**
     * @return array{balance: int|null, balanceOn: string|null, monthlyFlow: int|null, history: array<string, int>, forecast: array<string, int>}
     */
    private function cashflow(): array
    {
        return $this->cashflow ??= Cashflow::build();
    }
}
