<?php

namespace App\Filament\Widgets\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Category;
use App\Models\Finance\Order;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionSplit;
use App\Services\Finance\FinanceReport;
use App\Services\Finance\Money;
use App\Services\Finance\ProfitAndLoss;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * The P&L per month for the selected period: revenue per stream, operating costs per group (expand for the lines),
 * operating profit, costs below the operating profit and the profit after all costs. Click an amount to see the
 * transactions behind it.
 */
class ProfitAndLossTable extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithPageFilters;
    use InteractsWithSchemas {
        InteractsWithSchemas::__get insteadof InteractsWithPageFilters;
    }

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.finance.profit-and-loss';

    /**
     * Show amounts as a percentage of revenue instead of euros.
     */
    public bool $percentOfRevenue = false;

    /**
     * The categories whose transactions are shown under their row.
     *
     * @var list<int>
     */
    public array $expandedLines = [];

    /**
     * The revenue streams whose products are shown under their row.
     *
     * @var list<string>
     */
    public array $expandedStreams = [];

    public function toggleStream(string $streamId): void
    {
        $this->expandedStreams = in_array($streamId, $this->expandedStreams, true)
            ? array_values(array_diff($this->expandedStreams, [$streamId]))
            : [...$this->expandedStreams, $streamId];
    }

    public function toggleLine(int $categoryId): void
    {
        $this->expandedLines = in_array($categoryId, $this->expandedLines, true)
            ? array_values(array_diff($this->expandedLines, [$categoryId]))
            : [...$this->expandedLines, $categoryId];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = new FinanceReport($this->pageFilters);
        $pl = $report->profitAndLoss();
        $months = $report->months();
        $previous = $report->compares() ? $report->previousMonths() : null;

        $cell = function (array $byMonth, array $period) use ($pl): string {
            $cents = ProfitAndLoss::sumMonths($byMonth, $period);

            if ($cents === 0) {
                return '–';
            }

            if ($this->percentOfRevenue) {
                $revenue = ProfitAndLoss::sumMonths($pl['revenue'], $period);

                return $revenue ? str_replace('.', ',', number_format($cents / $revenue * 100, 1)).'%' : '–';
            }

            return Money::format($cents, decimals: false);
        };

        return [
            'pl' => $pl,
            'months' => $months,
            'previous' => $previous,
            'cell' => $cell,
            'monthLabel' => FinanceReport::monthLabel(...),
            'streamProducts' => collect($this->expandedStreams)->mapWithKeys(fn (string $streamId): array => [$streamId => $this->streamProducts($streamId, $months[0], end($months))])->all(),
            'lineTransactions' => collect($this->expandedLines)->mapWithKeys(fn (int $categoryId): array => [$categoryId => $this->drilldownRows($categoryId, $months[0], end($months))])->all(),
        ];
    }

    public function drilldownAction(): Action
    {
        return Action::make('drilldown')
            ->modalHeading(fn (array $arguments): string => (Category::with('parent')->find($arguments['category'] ?? 0)?->label() ?? 'Transacties').' · '.$this->periodLabel($arguments))
            ->modalWidth('4xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten')
            ->schema(fn (array $arguments): array => [
                TextEntry::make('lines')->hiddenLabel()->state($this->drilldownHtml((int) ($arguments['category'] ?? 0), $arguments['from'] ?? '', $arguments['to'] ?? '')),
            ]);
    }

    /**
     * The transactions (and split parts) behind one cell, newest first. Amounts are costs positive, excl. VAT.
     */
    private function drilldownHtml(int $categoryId, string $from, string $to): Htmlable
    {
        $rows = $this->drilldownRows($categoryId, $from, $to);

        if ($rows->isEmpty()) {
            return new HtmlString('<p style="opacity: 0.7;">Geen transacties.</p>');
        }

        $body = $rows->map(fn (array $row): string => '<tr style="border-bottom: 1px solid rgb(127 127 127 / 0.15);">'
            .'<td style="padding: 0.375rem 0.75rem 0.375rem 0; white-space: nowrap; opacity: 0.6; font-family: monospace;">'.$row['date'].'</td>'
            .'<td style="padding: 0.375rem 0.75rem 0.375rem 0;"><div style="font-weight: 500;">'.e($row['name']).($row['split'] ? ' <span style="opacity: 0.6;">(deel)</span>' : '').($row['auto'] ? ' <span style="color: #2563EB;">· voorgesteld</span>' : '').'</div><div style="opacity: 0.6; font-size: 0.8rem;">'.e(str($row['description'])->limit(90)).($row['note'] ? ' · 📝 '.e($row['note']) : '').'</div></td>'
            .'<td style="padding: 0.375rem 0; text-align: right; white-space: nowrap;">'.Money::format($row['cents']).'</td></tr>')->implode('');

        return new HtmlString('<table style="width: 100%; font-size: 0.875rem;"><tbody>'.$body.'</tbody><tfoot><tr><td></td><td style="padding-top: 0.5rem; font-weight: 600;">Totaal ('.$rows->count().')</td><td style="padding-top: 0.5rem; text-align: right; font-weight: 600;">'.Money::format($rows->sum('cents')).'</td></tr></tfoot></table>');
    }

    /**
     * The paid order lines of one revenue stream in the period, totalled per product, biggest first.
     *
     * @return Collection<int, array{name: string, sku: ?string, count: int, cents: int}>
     */
    private function streamProducts(string $streamId, string $from, string $to): Collection
    {
        return Order::query()
            ->with('product:id,plug_and_pay_product_id,revenue_stream_id')
            ->where('status', 'paid')
            ->whereBetween('paid_on', [Carbon::parse("{$from}-01")->startOfMonth(), Carbon::parse("{$to}-01")->endOfMonth()])
            ->get(['paid_on', 'plug_and_pay_product_id', 'sku', 'product_name', 'subtotal_ex_vat_cents'])
            ->filter(fn (Order $order): bool => $streamId === ProfitAndLoss::UnmappedStream
                ? $order->product?->revenue_stream_id === null
                : (string) $order->product?->revenue_stream_id === $streamId)
            ->groupBy(fn (Order $order): string => $order->product_name ?: 'Zonder product')
            ->map(fn (Collection $orders, string $name): array => ['name' => $name, 'sku' => $orders->first()->sku, 'count' => $orders->count(), 'cents' => (int) $orders->sum('subtotal_ex_vat_cents')])
            ->sortByDesc('cents')
            ->values();
    }

    /**
     * @return Collection<int, array{date: string, name: ?string, description: ?string, cents: int, note: ?string, split: bool, auto: bool}>
     */
    private function drilldownRows(int $categoryId, string $from, string $to): Collection
    {
        $fromDate = Carbon::parse("{$from}-01")->startOfMonth();
        $toDate = Carbon::parse("{$to}-01")->endOfMonth();
        $inPeriod = fn ($query) => $query->where(fn ($query) => $query
            ->whereBetween('period_on', [$fromDate, $toDate])
            ->orWhere(fn ($query) => $query->whereNull('period_on')->whereBetween('booked_on', [$fromDate, $toDate])));

        $whole = Transaction::query()
            ->where('category_id', $categoryId)
            ->whereIn('status', [TransactionStatus::Auto, TransactionStatus::Confirmed])
            ->whereDoesntHave('splits')
            ->where($inPeriod)
            ->orderByDesc('booked_on')
            ->limit(500)
            ->get()
            ->map(fn (Transaction $transaction): array => ['date' => $transaction->booked_on->format('Y-m-d'), 'name' => $transaction->counterparty_name, 'description' => $transaction->description, 'cents' => -($transaction->amount_ex_vat_cents ?? $transaction->amount_cents), 'note' => $transaction->note, 'split' => false, 'auto' => $transaction->status === TransactionStatus::Auto]);

        $parts = TransactionSplit::query()
            ->where('category_id', $categoryId)
            ->whereHas('transaction', fn ($query) => $query->where('status', '!=', TransactionStatus::Excluded)->where($inPeriod))
            ->with('transaction')
            ->limit(500)
            ->get()
            ->map(fn (TransactionSplit $split): array => ['date' => $split->transaction->booked_on->format('Y-m-d'), 'name' => $split->transaction->counterparty_name, 'description' => $split->transaction->description, 'cents' => -$split->amount_ex_vat_cents, 'note' => $split->note, 'split' => true, 'auto' => false]);

        return collect($whole->concat($parts)->sortByDesc('date')->values()->all());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function periodLabel(array $arguments): string
    {
        $from = $arguments['from'] ?? '';
        $to = $arguments['to'] ?? '';
        $label = fn (string $month): string => FinanceReport::monthLabel($month).' '.substr($month, 0, 4);

        return $from === $to ? $label($from) : $label($from).' – '.$label($to);
    }
}
