<?php

namespace App\Services\Finance;

use App\Enums\Finance\CategoryKind;
use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Category;
use App\Models\Finance\Order;
use App\Models\Finance\RevenueStream;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionSplit;
use Illuminate\Support\Collection;

/**
 * The profit & loss on a cash basis, excl. VAT, per month ("YYYY-MM").
 *
 * - Paid Plug&Pay orders give revenue per stream, on their payment date; products without a stream land in
 *   "Nog niet ingedeeld".
 * - Bank lines in revenue categories add revenue (money in is revenue).
 * - Expense categories are costs; a category or its group marked "below operating" is shown under the operating
 *   profit (e.g. fiscal costs).
 * - Transfers, financing, tax and excluded lines never count.
 */
class ProfitAndLoss
{
    public const UnmappedStream = '__unmapped__';

    /**
     * @param  list<array{month: string, category_id: int, ex_vat_cents: int}>  $costs  Costs positive (money out).
     * @param  list<array{month: string, stream_id: int|null, ex_vat_cents: int}>  $revenue
     * @param  Collection<int, Category>  $categories  Keyed by ID.
     * @param  Collection<int, RevenueStream>|list<array{id: int|string, name: string}>  $streams
     * @return array{revenueStreams: list<array<string, mixed>>, costGroups: list<array<string, mixed>>, belowGroups: list<array<string, mixed>>, revenue: array<string, int>, costs: array<string, int>, operating: array<string, int>, below: array<string, int>, net: array<string, int>}
     */
    public static function build(array $costs, array $revenue, Collection $categories, iterable $streams): array
    {
        $streamLines = [];
        $streamIds = [];

        $streamLine = function (string $id, string $name, ?string $code = null) use (&$streamLines): string {
            $streamLines[$id] ??= ['id' => $id, 'name' => $name, 'code' => $code, 'byMonth' => []];

            return $id;
        };

        foreach ($streams as $stream) {
            $id = (string) data_get($stream, 'id');
            $streamIds[] = $id;
            $streamLine($id, data_get($stream, 'name'));
        }

        foreach ($revenue as $row) {
            $id = $row['stream_id'] !== null && isset($streamLines[(string) $row['stream_id']])
                ? (string) $row['stream_id']
                : $streamLine(self::UnmappedStream, 'Nog niet ingedeeld');

            static::add($streamLines[$id]['byMonth'], substr($row['month'], 0, 7), $row['ex_vat_cents']);
        }

        $groups = [];

        foreach ($costs as $row) {
            $category = $categories->get($row['category_id']);

            if (! $category) {
                continue;
            }

            $parent = $category->parent_id ? $categories->get($category->parent_id) : null;
            $month = substr($row['month'], 0, 7);

            if ($category->kind === CategoryKind::Revenue) {
                // Money in is positive on the bank, but costs are reported positive here: flip.
                $id = $streamLine("cat:{$category->getKey()}", ($parent ? "{$parent->name} › " : '').$category->name, $category->code);
                static::add($streamLines[$id]['byMonth'], $month, -$row['ex_vat_cents']);

                continue;
            }

            if ($category->kind !== CategoryKind::Expense) {
                continue;
            }

            $group = $parent ?? $category;
            $groupId = $group->getKey();
            $groups[$groupId] ??= ['id' => $groupId, 'name' => $group->name, 'belowOperating' => $category->below_operating || (bool) $parent?->below_operating, 'byMonth' => [], 'lines' => []];
            $groups[$groupId]['lines'][$category->getKey()] ??= ['id' => $category->getKey(), 'name' => $category->name, 'code' => $category->code, 'byMonth' => []];

            static::add($groups[$groupId]['lines'][$category->getKey()]['byMonth'], $month, $row['ex_vat_cents']);
            static::add($groups[$groupId]['byMonth'], $month, $row['ex_vat_cents']);
        }

        $order = fn (int $id): int => $categories->get($id)?->sort_order ?? 999;
        $byOrder = fn (array $a, array $b): int => [$order($a['id']), $a['name']] <=> [$order($b['id']), $b['name']];

        $allGroups = array_values($groups);
        usort($allGroups, $byOrder);

        foreach ($allGroups as &$group) {
            $lines = array_values($group['lines']);
            usort($lines, $byOrder);
            $group['lines'] = $lines;
        }
        unset($group);

        $revenueStreams = array_values(array_filter($streamLines, fn (array $line): bool => $line['byMonth'] !== [] || in_array($line['id'], $streamIds, true)));
        $costGroups = array_values(array_filter($allGroups, fn (array $group): bool => ! $group['belowOperating']));
        $belowGroups = array_values(array_filter($allGroups, fn (array $group): bool => $group['belowOperating']));

        $revenueTotals = static::sumInto($revenueStreams);
        $costTotals = static::sumInto($costGroups);
        $belowTotals = static::sumInto($belowGroups);

        $operating = [];
        $net = [];

        foreach (array_unique([...array_keys($revenueTotals), ...array_keys($costTotals), ...array_keys($belowTotals)]) as $month) {
            $operating[$month] = ($revenueTotals[$month] ?? 0) - ($costTotals[$month] ?? 0);
            $net[$month] = $operating[$month] - ($belowTotals[$month] ?? 0);
        }

        return [
            'revenueStreams' => $revenueStreams,
            'costGroups' => $costGroups,
            'belowGroups' => $belowGroups,
            'revenue' => $revenueTotals,
            'costs' => $costTotals,
            'operating' => $operating,
            'below' => $belowTotals,
            'net' => $net,
        ];
    }

    /**
     * The P&L from the database.
     *
     * @return array<string, mixed>
     */
    public static function fromDatabase(): array
    {
        $categories = Category::query()->get()->keyBy('id');
        $streams = RevenueStream::query()->orderBy('sort_order')->orderBy('name')->get();

        return static::build(static::costRows(), static::revenueRows(), $categories, $streams);
    }

    /**
     * Categorized bank lines (and split parts) per month and category, costs positive.
     *
     * @return list<array{month: string, category_id: int, ex_vat_cents: int}>
     */
    public static function costRows(): array
    {
        $totals = [];

        Transaction::query()
            ->whereIn('status', [TransactionStatus::Auto, TransactionStatus::Confirmed])
            ->whereNotNull('category_id')
            ->whereDoesntHave('splits')
            ->get(['booked_on', 'period_on', 'category_id', 'amount_cents', 'amount_ex_vat_cents'])
            ->each(function (Transaction $line) use (&$totals): void {
                $key = $line->plMonth().'|'.$line->category_id;
                $totals[$key] = ($totals[$key] ?? 0) - ($line->amount_ex_vat_cents ?? $line->amount_cents);
            });

        TransactionSplit::query()
            ->with('transaction:id,booked_on,period_on,status')
            ->whereHas('transaction', fn ($query) => $query->where('status', '!=', TransactionStatus::Excluded))
            ->get()
            ->each(function (TransactionSplit $split) use (&$totals): void {
                $key = $split->transaction->plMonth().'|'.$split->category_id;
                $totals[$key] = ($totals[$key] ?? 0) - $split->amount_ex_vat_cents;
            });

        return collect($totals)->map(function (int $cents, string $key): array {
            [$month, $categoryId] = explode('|', $key);

            return ['month' => $month, 'category_id' => (int) $categoryId, 'ex_vat_cents' => $cents];
        })->values()->all();
    }

    /**
     * Paid order lines per month (payment date) and revenue stream.
     *
     * @return list<array{month: string, stream_id: int|null, ex_vat_cents: int}>
     */
    public static function revenueRows(): array
    {
        $totals = [];

        Order::query()
            ->with('product:id,plug_and_pay_product_id,revenue_stream_id')
            ->where('status', 'paid')
            ->whereNotNull('paid_on')
            ->get(['paid_on', 'plug_and_pay_product_id', 'subtotal_ex_vat_cents'])
            ->each(function (Order $order) use (&$totals): void {
                $key = $order->paid_on->format('Y-m').'|'.($order->product?->revenue_stream_id ?? '');
                $totals[$key] = ($totals[$key] ?? 0) + $order->subtotal_ex_vat_cents;
            });

        return collect($totals)->map(function (int $cents, string $key): array {
            [$month, $streamId] = explode('|', $key);

            return ['month' => $month, 'stream_id' => $streamId !== '' ? (int) $streamId : null, 'ex_vat_cents' => $cents];
        })->values()->all();
    }

    /**
     * @return list<string>
     */
    public static function monthsOf(int $year): array
    {
        return array_map(fn (int $month): string => sprintf('%d-%02d', $year, $month), range(1, 12));
    }

    /**
     * The months in a period: year, ytd (through $lastMonth), q1..q4 or m1..m12.
     *
     * @return list<string>
     */
    public static function periodMonths(int $year, string $period, int $lastMonth): array
    {
        $all = static::monthsOf($year);

        return match (true) {
            $period === 'year' => $all,
            $period === 'ytd' => array_slice($all, 0, $lastMonth),
            str_starts_with($period, 'q') => array_slice($all, ((int) substr($period, 1) - 1) * 3, 3),
            default => [$all[(int) substr($period, 1) - 1]],
        };
    }

    /**
     * @param  array<string, int>  $byMonth
     * @param  list<string>  $months
     */
    public static function sumMonths(array $byMonth, array $months): int
    {
        return array_sum(array_map(fn (string $month): int => $byMonth[$month] ?? 0, $months));
    }

    /**
     * The same months one or more years earlier or later.
     *
     * @param  list<string>  $months
     * @return list<string>
     */
    public static function shiftYear(array $months, int $years): array
    {
        return array_map(fn (string $month): string => ((int) substr($month, 0, 4) + $years).substr($month, 4), $months);
    }

    /**
     * @param  array<string, int>  $totals
     */
    private static function add(array &$totals, string $month, int $cents): void
    {
        $totals[$month] = ($totals[$month] ?? 0) + $cents;
    }

    /**
     * @param  list<array{byMonth: array<string, int>}>  $items
     * @return array<string, int>
     */
    private static function sumInto(array $items): array
    {
        $totals = [];

        foreach ($items as $item) {
            foreach ($item['byMonth'] as $month => $cents) {
                static::add($totals, $month, $cents);
            }
        }

        return $totals;
    }
}
