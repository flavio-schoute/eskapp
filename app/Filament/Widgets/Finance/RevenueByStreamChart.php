<?php

namespace App\Filament\Widgets\Finance;

use App\Services\Finance\FinanceReport;

class RevenueByStreamChart extends FinanceBarChart
{
    protected bool $stacked = true;

    public function getHeading(): string
    {
        return 'Omzet per stroom, '.$this->report()->year();
    }

    protected function series(FinanceReport $report): array
    {
        return $this->foldSeries($report->profitAndLoss()['revenueStreams'], $report->months());
    }
}
