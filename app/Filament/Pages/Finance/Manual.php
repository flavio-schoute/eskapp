<?php

namespace App\Filament\Pages\Finance;

use App\Filament\Concerns\FinanceOnly;
use App\Filament\Resources\Finance\Categories\CategoryResource;
use App\Filament\Resources\Finance\Rules\RuleResource;
use App\Filament\Resources\Finance\Transactions\TransactionResource;
use App\Filament\Resources\SlackMembers\SlackMemberResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The SOP: how the books in Finance are kept up to date.
 */
class Manual extends Page
{
    use FinanceOnly;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Handleiding';

    protected static ?string $title = 'Handleiding';

    protected static ?string $slug = 'finance/handleiding';

    protected string $view = 'filament.finance.manual';

    public function getSubheading(): ?string
    {
        return 'SOP: zo houd je de financiële administratie in deze app bij.';
    }

    /**
     * @return array<string, string>
     */
    protected function getViewData(): array
    {
        return [
            'import' => Import::getUrl(),
            'inbox' => Inbox::getUrl(),
            'products' => RevenueStreams::getUrl(),
            'dashboard' => FinanceDashboard::getUrl(),
            'transactions' => TransactionResource::getUrl(),
            'rules' => RuleResource::getUrl(),
            'signals' => Signals::getUrl(),
            'categories' => CategoryResource::getUrl(),
            'slackSettings' => SlackMemberResource::getUrl(),
        ];
    }
}
