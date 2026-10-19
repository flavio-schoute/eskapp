<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Finance\FinanceReport;
use App\Services\Finance\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Revenue, operating costs, operating profit and margin for the period, compared with the year before.
 */
class FinanceStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getDescription(): ?string
    {
        $report = new FinanceReport($this->pageFilters);
        $belowGroups = $report->profitAndLoss()['belowGroups'];

        if ($belowGroups === []) {
            return null;
        }

        $names = collect($belowGroups)->pluck('name')->map(fn (string $name): string => mb_strtolower($name))->implode(', ');

        return "Na {$names} (".Money::format($report->total('below')['current'], decimals: false).'): winst '.Money::format($report->total('net')['current'], decimals: false);
    }

    protected function getColumns(): int|array
    {
        return ['md' => 3, 'xl' => 5];
    }

    protected function getStats(): array
    {
        $report = new FinanceReport($this->pageFilters);
        $revenue = $report->total('revenue');
        $costs = $report->total('costs');
        $operating = $report->total('operating');
        $margin = $revenue['current'] ? $operating['current'] / $revenue['current'] : 0;
        $previousMargin = $revenue['previous'] ? $operating['previous'] / $revenue['previous'] : 0;
        $compare = $report->compares();

        $stat = function (string $label, string $value, ?array $delta): Stat {
            $stat = Stat::make($label, $value);

            return $delta ? $stat->description(($delta['good'] ? '▲ ' : '▼ ').$delta['text'])->color($delta['good'] ? 'success' : 'danger') : $stat;
        };

        $adSpend = $report->adSpend();
        $roas = $adSpend['current'] ? $revenue['current'] / $adSpend['current'] : null;
        $previousRoas = $adSpend['previous'] ? $revenue['previous'] / $adSpend['previous'] : null;
        $times = fn (float $value): string => str_replace('.', ',', number_format($value, 1)).'×';

        $pct = fn (float $value): string => str_replace('.', ',', number_format($value * 100, 1)).'%';

        return [
            $stat('Omzet (betaald)', Money::format($revenue['current'], decimals: false), $compare ? FinanceReport::delta($revenue['current'], $revenue['previous'], true) : null),
            $stat('Operationele kosten', Money::format($costs['current'], decimals: false), $compare ? FinanceReport::delta($costs['current'], $costs['previous'], false) : null),
            $stat('Operationele winst', Money::format($operating['current'], decimals: false), $compare ? FinanceReport::delta($operating['current'], $operating['previous'], true) : null),
            $stat('Marge', $pct($margin), $compare ? ['text' => ($margin >= $previousMargin ? '+' : '−').str_replace('.', ',', number_format(abs(($margin - $previousMargin) * 100), 1)).' pp', 'good' => $margin >= $previousMargin] : null),
            // Revenue per euro ad spend.
            Stat::make('ROAS', $roas === null ? '–' : $times($roas))
                ->description($adSpend['current'] ? 'adspend '.Money::format($adSpend['current'], decimals: false).($compare && $previousRoas !== null && $roas !== null ? ' · vorig jaar '.$times($previousRoas) : '') : 'Geen adspend in deze periode')
                ->color($compare && $previousRoas !== null && $roas !== null ? ($roas >= $previousRoas ? 'success' : 'danger') : null),
        ];
    }
}
