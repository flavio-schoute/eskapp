<?php

namespace App\Services\Finance;

use App\Models\Finance\Category;
use App\Models\Finance\Order;
use App\Models\Finance\Transaction;

/**
 * The P&L for the dashboard's filters (year, period, compare with the year before), computed once per request.
 */
class FinanceReport
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $profitAndLoss = null;

    /**
     * @var list<string>|null
     */
    private static ?array $dataMonths = null;

    /**
     * @param  array<string, mixed>|null  $filters  {year, period, compare}
     */
    public function __construct(private ?array $filters = []) {}

    public static function flush(): void
    {
        static::$profitAndLoss = null;
        static::$dataMonths = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function profitAndLoss(): array
    {
        return static::$profitAndLoss ??= ProfitAndLoss::fromDatabase();
    }

    /**
     * Months that have any revenue or costs, sorted.
     *
     * @return list<string>
     */
    public static function dataMonths(): array
    {
        if (static::$dataMonths !== null) {
            return static::$dataMonths;
        }

        $pl = (new static)->profitAndLoss();

        $months = array_unique([...array_keys($pl['revenue']), ...array_keys($pl['costs']), ...array_keys($pl['below'])]);
        sort($months);

        return static::$dataMonths = array_values($months);
    }

    /**
     * @return list<int>
     */
    public static function years(): array
    {
        $years = array_values(array_unique(array_map(fn (string $month): int => (int) substr($month, 0, 4), static::dataMonths())));

        return $years === [] ? [(int) now()->year] : $years;
    }

    public function year(): int
    {
        return (int) ($this->filters['year'] ?? last(static::years()));
    }

    public function period(): string
    {
        return $this->filters['period'] ?? ($this->year() === (int) now()->year ? 'ytd' : 'year');
    }

    /**
     * The last month with data in the selected year, for "year to date".
     */
    public function lastMonthOfYear(): int
    {
        $months = array_filter(static::dataMonths(), fn (string $month): bool => str_starts_with($month, (string) $this->year()));

        return $months === [] ? 12 : (int) substr(last($months), 5, 2);
    }

    /**
     * @return list<string>
     */
    public function months(): array
    {
        return ProfitAndLoss::periodMonths($this->year(), $this->period(), $this->lastMonthOfYear());
    }

    /**
     * @return list<string>
     */
    public function previousMonths(): array
    {
        return ProfitAndLoss::shiftYear($this->months(), -1);
    }

    public function hasPrevious(): bool
    {
        return array_intersect(static::dataMonths(), $this->previousMonths()) !== [];
    }

    public function compares(): bool
    {
        return (bool) ($this->filters['compare'] ?? true) && $this->hasPrevious();
    }

    /**
     * @return array{current: int, previous: int}
     */
    public function total(string $series): array
    {
        $byMonth = $this->profitAndLoss()[$series];

        return ['current' => ProfitAndLoss::sumMonths($byMonth, $this->months()), 'previous' => ProfitAndLoss::sumMonths($byMonth, $this->previousMonths())];
    }

    /**
     * Costs on the ad spend categories (e.g. Adspend Facebook, Adspend Google), for the ROAS.
     *
     * @return array{current: int, previous: int}
     */
    public function adSpend(): array
    {
        $adSpendIds = Category::query()->where('is_ad_spend', true)->pluck('id')->all();
        $byMonth = [];

        foreach (static::costLines($this->profitAndLoss()) as $line) {
            if (in_array($line['id'], $adSpendIds, true)) {
                foreach ($line['byMonth'] as $month => $cents) {
                    $byMonth[$month] = ($byMonth[$month] ?? 0) + $cents;
                }
            }
        }

        return ['current' => ProfitAndLoss::sumMonths($byMonth, $this->months()), 'previous' => ProfitAndLoss::sumMonths($byMonth, $this->previousMonths())];
    }

    /**
     * Revenue per stream against the cost categories linked to that stream, for the period. Streams without revenue
     * and costs are left out.
     *
     * @return list<array{name: string, revenue: int, costs: int, margin: int, ratio: float|null, categories: list<string>}>
     */
    public function streamMargins(): array
    {
        $pl = $this->profitAndLoss();
        $months = $this->months();
        $linked = Category::query()->whereNotNull('revenue_stream_id')->get(['id', 'name', 'revenue_stream_id'])->groupBy('revenue_stream_id');
        $lines = collect(static::costLines($pl))->keyBy('id');

        return collect($pl['revenueStreams'])
            ->filter(fn (array $stream): bool => ctype_digit((string) $stream['id']))
            ->map(function (array $stream) use ($linked, $lines, $months): array {
                $categories = $linked->get((int) $stream['id'], collect());
                $revenue = ProfitAndLoss::sumMonths($stream['byMonth'], $months);
                $costs = $categories->sum(fn (Category $category): int => ProfitAndLoss::sumMonths($lines->get($category->getKey())['byMonth'] ?? [], $months));

                return [
                    'name' => $stream['name'],
                    'revenue' => $revenue,
                    'costs' => $costs,
                    'margin' => $revenue - $costs,
                    'ratio' => $revenue ? ($revenue - $costs) / $revenue : null,
                    'categories' => $categories->pluck('name')->all(),
                ];
            })
            ->filter(fn (array $row): bool => $row['revenue'] !== 0 || $row['costs'] !== 0)
            ->values()
            ->all();
    }

    /**
     * Categories with a monthly budget and what was spent on them in a month (a group counts all its lines).
     *
     * @return list<array{category: Category, label: string, budget: int, spent: int, ratio: float}>
     */
    public function budgets(string $month): array
    {
        $pl = $this->profitAndLoss();
        $groups = collect([...$pl['costGroups'], ...$pl['belowGroups']])->keyBy('id');
        $lines = collect(static::costLines($pl))->keyBy('id');

        return Category::query()
            ->with('parent')
            ->whereNotNull('monthly_budget_cents')
            ->where('monthly_budget_cents', '>', 0)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Category $category) use ($groups, $lines, $month): array {
                $source = $lines->get($category->getKey()) ?? $groups->get($category->getKey());
                $spent = (int) ($source['byMonth'][$month] ?? 0);

                return ['category' => $category, 'label' => $category->label(), 'budget' => $category->monthly_budget_cents, 'spent' => $spent, 'ratio' => $spent / $category->monthly_budget_cents];
            })
            ->sortByDesc('ratio')
            ->values()
            ->all();
    }

    /**
     * Every cost line (subcategory) of the P&L, operating and below.
     *
     * @param  array<string, mixed>  $pl
     * @return list<array{id: int, name: string, byMonth: array<string, int>}>
     */
    private static function costLines(array $pl): array
    {
        return collect([...$pl['costGroups'], ...$pl['belowGroups']])->flatMap(fn (array $group): array => $group['lines'])->values()->all();
    }

    public function unmappedRevenue(): int
    {
        $line = collect($this->profitAndLoss()['revenueStreams'])->firstWhere('id', ProfitAndLoss::UnmappedStream);

        return $line ? ProfitAndLoss::sumMonths($line['byMonth'], $this->months()) : 0;
    }

    public static function openInboxCount(): int
    {
        return Transaction::query()->open()->count();
    }

    public static function dataThrough(): string
    {
        $bank = Transaction::query()->max('booked_on');
        $orders = Order::query()->where('status', 'paid')->max('paid_on');

        return 'bank t/m '.($bank ? substr((string) $bank, 0, 10) : '—').' · orders t/m '.($orders ? substr((string) $orders, 0, 10) : '—');
    }

    /**
     * The periods to choose from.
     *
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        $names = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

        return [
            'year' => 'Heel jaar',
            'ytd' => 'Jaar tot nu (t/m '.$names[$this->lastMonthOfYear() - 1].')',
            'q1' => 'Q1', 'q2' => 'Q2', 'q3' => 'Q3', 'q4' => 'Q4',
            ...collect($names)->mapWithKeys(fn (string $name, int $index): array => ['m'.($index + 1) => "{$name} {$this->year()}"])->all(),
        ];
    }

    public static function monthLabel(string $month): string
    {
        return ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'][(int) substr($month, 5, 2) - 1];
    }

    /**
     * "+12,3% vs vorig jaar", coloured good or bad.
     *
     * @return array{text: string, good: bool}|null
     */
    public static function delta(int $current, int $previous, bool $upIsGood): ?array
    {
        if ($previous === 0) {
            return null;
        }

        $change = ($current - $previous) / abs($previous);

        return ['text' => ($change >= 0 ? '+' : '−').str_replace('.', ',', number_format(abs($change * 100), 1)).'% vs vorig jaar', 'good' => ($change >= 0) === $upIsGood];
    }
}
