<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Finance\FinanceReport;

class RevenueAndCostsChart extends FinanceBarChart
{
    public function getHeading(): string
    {
        return 'Omzet en kosten per maand, '.$this->report()->year();
    }

    protected function series(FinanceReport $report): array
    {
        $pl = $report->profitAndLoss();

        return [
            ['label' => 'Omzet', 'color' => self::Palette[0], 'byMonth' => $pl['revenue']],
            ['label' => 'Kosten', 'color' => self::Palette[1], 'byMonth' => $pl['costs']],
        ];
    }
}
