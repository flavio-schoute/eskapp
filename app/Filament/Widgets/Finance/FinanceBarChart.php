<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Finance\FinanceReport;
use App\Services\Finance\ProfitAndLoss;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * A bar chart of the selected year per month. Months outside the selected period are shown lighter; stacked charts
 * keep the 7 largest series and fold the rest into "Overig".
 */
abstract class FinanceBarChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    /**
     * The series palette, by position; the 8th colour is "Overig".
     *
     * @var list<string>
     */
    public const Palette = ['#2563EB', '#E4572E', '#16A34A', '#D97706', '#DB2777', '#7C3AED', '#0E7490'];

    public const Other = '#A39E93';

    protected bool $stacked = false;

    /**
     * @return list<array{label: string, color: string, byMonth: array<string, int>}>
     */
    abstract protected function series(FinanceReport $report): array;

    protected function report(): FinanceReport
    {
        return new FinanceReport($this->pageFilters);
    }

    protected function getData(): array
    {
        $report = $this->report();
        $months = ProfitAndLoss::monthsOf($report->year());
        $highlight = array_flip($report->months());
        $series = $this->series($report);
        $last = count($series) - 1;

        return [
            'datasets' => collect($series)->map(fn (array $item, int $index): array => [
                'label' => $item['label'],
                'data' => array_map(fn (string $month): float => ($item['byMonth'][$month] ?? 0) / 100, $months),
                'backgroundColor' => array_map(fn (string $month): string => $item['color'].(isset($highlight[$month]) ? '' : '59'), $months),
                'hoverBackgroundColor' => array_map(fn (string $month): string => $item['color'], $months),
                // Only the top of a bar (or of a stack) is rounded.
                'borderRadius' => ! $this->stacked || $index === $last ? ['topLeft' => 4, 'topRight' => 4] : 0,
                'borderSkipped' => 'bottom',
                'borderWidth' => 0,
                'maxBarThickness' => 22,
                'categoryPercentage' => 0.72,
                'barPercentage' => $this->stacked ? 0.9 : 0.86,
                'stack' => $this->stacked ? 'stack' : null,
            ])->all(),
            'labels' => array_map(FinanceReport::monthLabel(...), $months),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        $stacked = $this->stacked ? 'true' : 'false';

        return RawJs::make(<<<JS
            {
                maintainAspectRatio: false,
                animation: { duration: 350, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    financeHoverBand: { enabled: true },
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, boxHeight: 7, padding: 14, font: { size: 12 } },
                    },
                    tooltip: { enabled: false, external: (context) => window.financeChartTooltip?.(context) },
                },
                scales: {
                    x: { stacked: {$stacked}, grid: { display: false }, border: { display: false }, ticks: { font: { size: 12 } } },
                    y: {
                        stacked: {$stacked},
                        border: { display: false },
                        grid: { color: 'rgba(127, 127, 127, 0.14)', drawTicks: false },
                        ticks: {
                            padding: 8,
                            maxTicksLimit: 5,
                            font: { size: 12 },
                            callback: (value) => Math.abs(value) >= 1000000 ? '€' + (value / 1000000).toFixed(1).replace('.', ',') + 'M' : (Math.abs(value) >= 1000 ? '€' + Math.round(value / 1000) + 'K' : '€' + value),
                        },
                    },
                },
            }
        JS);
    }

    /**
     * Series for stacked charts: each item keeps its palette colour by position; the rest fold into "Overig".
     *
     * @param  list<array{id: int|string, name: string, byMonth: array<string, int>}>  $items
     * @return list<array{label: string, color: string, byMonth: array<string, int>}>
     */
    protected function foldSeries(array $items, array $months): array
    {
        $series = [];
        $other = [];

        foreach ($items as $index => $item) {
            if ($index < count(self::Palette)) {
                if (ProfitAndLoss::sumMonths($item['byMonth'], $months) !== 0 || count($items) <= count(self::Palette)) {
                    $series[] = ['label' => $item['name'], 'color' => self::Palette[$index], 'byMonth' => $item['byMonth']];
                }

                continue;
            }

            foreach ($item['byMonth'] as $month => $cents) {
                $other[$month] = ($other[$month] ?? 0) + $cents;
            }
        }

        if ($other !== []) {
            $series[] = ['label' => 'Overig', 'color' => self::Other, 'byMonth' => $other];
        }

        return $series;
    }
}
