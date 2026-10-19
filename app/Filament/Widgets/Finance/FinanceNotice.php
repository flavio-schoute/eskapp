<?php

namespace App\Filament\Widgets\Finance;

use App\Filament\Pages\Finance\Inbox;
use App\Filament\Pages\Finance\RevenueStreams;
use App\Services\Finance\FinanceReport;
use App\Services\Finance\Money;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * "Nog niet compleet": lines waiting in the inbox and revenue from products without a revenue stream.
 */
class FinanceNotice extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.finance.notice';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = new FinanceReport($this->pageFilters);
        $unmapped = $report->unmappedRevenue();

        return [
            'openInbox' => FinanceReport::openInboxCount(),
            'inboxUrl' => Inbox::getUrl(),
            'unmapped' => $unmapped !== 0 ? Money::format($unmapped, decimals: false) : null,
            'productsUrl' => RevenueStreams::getUrl(),
        ];
    }
}
