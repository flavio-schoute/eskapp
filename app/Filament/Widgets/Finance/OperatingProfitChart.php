<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Finance\FinanceReport;

class OperatingProfitChart extends FinanceBarChart
{
    public function getHeading(): string
    {
        return 'Operationele winst per maand, '.$this->report()->year();
    }

    protected function series(FinanceReport $report): array
    {
        return [['label' => 'Winst', 'color' => self::Palette[0], 'byMonth' => $report->profitAndLoss()['operating']]];
    }
}
