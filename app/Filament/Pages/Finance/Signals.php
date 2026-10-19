<?php

namespace App\Filament\Pages\Finance;

use App\Filament\Concerns\FinanceOnly;
use App\Filament\Resources\Finance\Categories\CategoryResource;
use App\Filament\Resources\Finance\Transactions\TransactionResource;
use App\Models\Finance\Alert;
use App\Services\Finance\FinanceReport;
use App\Services\Finance\Signals as FinanceSignals;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Budgets this month, the subscriptions found in the bank lines and payments that may be a mistake.
 */
class Signals extends Page
{
    use FinanceOnly;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Signalen';

    protected static ?string $title = 'Signalen';

    protected static ?string $slug = 'finance/signalen';

    protected string $view = 'filament.finance.signals';

    /**
     * Signals sent in the last 30 days that nobody dismissed yet.
     */
    public static function getNavigationBadge(): ?string
    {
        $open = Alert::query()->whereNull('dismissed_at')->where('created_at', '>=', now()->subDays(30))->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public function getSubheading(): ?string
    {
        return 'Bankbetalingen zonder betaalde order, budgetten, abonnementen en betalingen die misschien niet kloppen. Nieuwe signalen gaan elke ochtend naar #finance in Slack.';
    }

    /**
     * "Klopt": hide a signal on this page and stop it from being sent.
     */
    public function dismiss(string $key, string $type, string $message): void
    {
        Alert::query()->firstOrCreate(['key' => $key], ['type' => $type, 'message' => $message])
            ->update(['dismissed_at' => now(), 'dismissed_by' => auth()->id()]);

        Notification::make()->success()->title('Gemarkeerd als in orde')->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $dismissed = Alert::query()->whereNotNull('dismissed_at')->pluck('key')->flip();

        return [
            'month' => now()->translatedFormat('F Y'),
            'budgets' => (new FinanceReport)->budgets(now()->format('Y-m')),
            'subscriptions' => FinanceSignals::subscriptions(),
            'bankPayments' => array_values(array_filter(FinanceSignals::bankPayments(), fn (array $payment): bool => ! $dismissed->has($payment['key']))),
            'anomalies' => array_values(array_filter(FinanceSignals::anomalies(), fn (array $anomaly): bool => ! $dismissed->has($anomaly['key']))),
            'dismissed' => $dismissed,
            'categoriesUrl' => CategoryResource::getUrl(),
            'transactionsUrl' => TransactionResource::getUrl(),
        ];
    }
}
