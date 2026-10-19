<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Finance\FinanceReport;

class CostsByGroupChart extends FinanceBarChart
{
    protected bool $stacked = true;

    public function getHeading(): string
    {
        return 'Kosten per groep, '.$this->report()->year();
    }

    protected function series(FinanceReport $report): array
    {
        return $this->foldSeries($report->profitAndLoss()['costGroups'], $report->months());
    }
}
