<?php

namespace App\Filament\Widgets\Finance;

use App\Filament\Resources\Finance\Categories\CategoryResource;
use App\Services\Finance\FinanceReport;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Revenue per stream against the costs linked to that stream (under Categorieën), for the selected period.
 */
class StreamMarginTable extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.finance.stream-margins';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'rows' => (new FinanceReport($this->pageFilters))->streamMargins(),
            'categoriesUrl' => CategoryResource::getUrl(),
        ];
    }
}
