<?php

namespace App\Services\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Category;
use App\Models\Finance\Transaction;
use App\Support\FinanceAlerts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Patterns in the money going out: subscriptions (recurring payments, new ones and price increases), possible
 * duplicate payments and unusually high payments compared with earlier ones to the same counterparty.
 */
class Signals
{
    /**
     * "FACEBK *U5Q7L9SB74" and "FACEBK *A1B2C3" are the same counterparty: lowercase, without words containing digits.
     */
    public static function normalize(string $name): string
    {
        $words = preg_split('/[\s*#\/,.]+/', mb_strtolower(trim($name))) ?: [];

        return trim(implode(' ', array_filter($words, fn (string $word): bool => $word !== '' && preg_match('/\d/', $word) !== 1)));
    }

    /**
     * Counterparties paid in at least three of the last six months, the last time within 45 days, with a steady amount.
     * Ad spend and transfers are left out.
     *
     * @return list<array{key: string, name: string, category: string|null, typical: int, last: int, previous: int|null, lastOn: string, firstOn: string, months: int, yearly: int, isNew: bool, priceUp: bool, transactionId: int}>
     */
    public static function subscriptions(?Carbon $today = null): array
    {
        $today ??= now();
        $earliest = Transaction::query()->min('booked_on');

        return static::outgoing($today->copy()->subMonthsNoOverflow(13), $today)
            ->toBase()->groupBy(fn (Transaction $transaction): string => static::normalize($transaction->counterparty_name))
            ->except([''])
            ->map(function (Collection $payments, string $key) use ($today, $earliest): ?array {
                $payments = $payments->sortBy([['booked_on', 'asc'], ['id', 'asc']])->values();
                $last = $payments->last();
                $perMonth = $payments->groupBy(fn (Transaction $transaction): string => $transaction->booked_on->format('Y-m'))->map(fn (Collection $month): int => (int) -$month->sum('amount_cents'));
                $recentMonths = $perMonth->filter(fn (int $cents, string $month): bool => $month >= $today->copy()->subMonthsNoOverflow(5)->format('Y-m'));

                if ($recentMonths->count() < 3 || $last->booked_on->lt($today->copy()->subDays(45))) {
                    return null;
                }

                $lastThree = $recentMonths->sortKeys()->take(-3)->values();
                $median = static::median($lastThree->all());

                if ($median <= 0 || $lastThree->contains(fn (int $cents): bool => abs($cents - $median) > $median * 0.35)) {
                    return null;
                }

                $previous = $payments->count() > 1 ? $payments[$payments->count() - 2] : null;
                $lastCents = -$last->amount_cents;
                $previousCents = $previous ? -$previous->amount_cents : null;
                $firstOn = $payments->first()->booked_on;

                return [
                    'key' => $key,
                    'name' => $last->counterparty_name,
                    'category' => $last->category?->label(),
                    'typical' => $median,
                    'last' => $lastCents,
                    'previous' => $previousCents,
                    'lastOn' => $last->booked_on->format('Y-m-d'),
                    'firstOn' => $firstOn->format('Y-m-d'),
                    'months' => $perMonth->count(),
                    'yearly' => $median * 12,
                    // Only "new" when the books go back far enough to know it wasn't paid before.
                    'isNew' => $firstOn->gte($today->copy()->subDays(100)) && $earliest !== null && Carbon::parse($earliest)->lt($firstOn->copy()->subDays(60)),
                    'priceUp' => $previousCents !== null && $lastCents > $previousCents * 1.05 && $lastCents - $previousCents >= 100,
                    'transactionId' => $last->getKey(),
                ];
            })
            ->filter()
            ->sortByDesc('yearly')
            ->values()
            ->all();
    }

    /**
     * Payments of the last days that look like a mistake: the same amount to the same counterparty within a week, or
     * more than three times what was usually paid to that counterparty (and at least € 100 more).
     *
     * @return list<array{key: string, type: string, message: string, transaction: Transaction, other: Transaction|null}>
     */
    public static function anomalies(int $days = 60, ?Carbon $today = null): array
    {
        $today ??= now();
        $since = $today->copy()->subDays($days)->startOfDay();
        $byCounterparty = static::outgoing($today->copy()->subMonthsNoOverflow(13), $today)
            ->toBase()->groupBy(fn (Transaction $transaction): string => static::normalize($transaction->counterparty_name))
            ->except(['']);
        $found = [];

        foreach ($byCounterparty as $payments) {
            $payments = $payments->sortBy([['booked_on', 'asc'], ['id', 'asc']])->values();

            foreach ($payments as $index => $payment) {
                if ($payment->booked_on->lt($since)) {
                    continue;
                }

                $twin = $payments->take($index)->last(fn (Transaction $earlier): bool => $earlier->amount_cents === $payment->amount_cents && $earlier->booked_on->diffInDays($payment->booked_on) <= 7);

                if ($twin) {
                    $found[] = [
                        'key' => "duplicate:{$twin->getKey()}-{$payment->getKey()}",
                        'type' => 'duplicate',
                        'message' => FinanceAlerts::render('duplicate', ['{bedrag}' => Money::format(-$payment->amount_cents), '{partij}' => $payment->counterparty_name, '{datum}' => $twin->booked_on->format('Y-m-d'), '{datum2}' => $payment->booked_on->format('Y-m-d')]),
                        'transaction' => $payment,
                        'other' => $twin,
                    ];

                    continue;
                }

                $earlier = $payments->take($index)->map(fn (Transaction $transaction): int => -$transaction->amount_cents);

                if ($earlier->count() < 3) {
                    continue;
                }

                $median = static::median($earlier->take(-12)->values()->all());
                $cents = -$payment->amount_cents;

                if ($median > 0 && $cents > $median * 3 && $cents - $median >= 10000) {
                    $found[] = [
                        'key' => "unusual:{$payment->getKey()}",
                        'type' => 'unusual',
                        'message' => FinanceAlerts::render('unusual', ['{bedrag}' => Money::format($cents), '{partij}' => $payment->counterparty_name, '{datum}' => $payment->booked_on->format('Y-m-d'), '{normaal}' => Money::format($median)]),
                        'transaction' => $payment,
                        'other' => null,
                    ];
                }
            }
        }

        return collect($found)->sortByDesc(fn (array $anomaly): string => $anomaly['transaction']->booked_on->format('Y-m-d'))->values()->all();
    }

    /**
     * Money in of the last days that needs a look: matched to a Plug&Pay order that is still open (the revenue doesn't
     * count until the order is paid), or booked as customer payment without an order.
     *
     * @return list<array{key: string, type: string, message: string, transaction: Transaction}>
     */
    public static function bankPayments(int $days = 60, ?Carbon $today = null): array
    {
        $today ??= now();
        $customerPayment = Category::query()->where('code', PaymentMatcher::CategoryCode)->value('id');

        return Transaction::query()
            ->where('amount_cents', '>', 0)
            ->whereDate('booked_on', '>=', $today->copy()->subDays($days))
            ->where(fn ($query) => $query
                ->where('order_match', PaymentMatcher::Open)
                ->orWhere(fn ($query) => $query->where('order_match', PaymentMatcher::None)->where('category_id', $customerPayment)))
            ->orderByDesc('booked_on')
            ->get()
            ->map(fn (Transaction $transaction): array => $transaction->order_match === PaymentMatcher::Open
                ? [
                    'key' => "payment-open:{$transaction->getKey()}",
                    'type' => 'payment_open',
                    'message' => FinanceAlerts::render('payment_open', ['{bedrag}' => Money::format($transaction->amount_cents), '{partij}' => $transaction->counterparty_name, '{datum}' => $transaction->booked_on->format('Y-m-d'), '{order}' => $transaction->order_ref]),
                    'transaction' => $transaction,
                ]
                : [
                    'key' => "payment-unmatched:{$transaction->getKey()}",
                    'type' => 'payment_unmatched',
                    'message' => FinanceAlerts::render('payment_unmatched', ['{bedrag}' => Money::format($transaction->amount_cents), '{partij}' => $transaction->counterparty_name, '{datum}' => $transaction->booked_on->format('Y-m-d')]),
                    'transaction' => $transaction,
                ])
            ->all();
    }

    /**
     * Money out in a period that counts in the P&L (or isn't categorized yet), without ad spend.
     *
     * @return Collection<int, Transaction>
     */
    private static function outgoing(Carbon $from, Carbon $to): Collection
    {
        $leftOut = Category::query()
            ->where('is_ad_spend', true)
            ->orWhereNotIn('kind', ['expense', 'revenue'])
            ->pluck('id');

        return Transaction::query()
            ->with('category.parent')
            ->where('amount_cents', '<', 0)
            ->where('status', '!=', TransactionStatus::Excluded)
            ->whereBetween('booked_on', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->where(fn ($query) => $query->whereNull('category_id')->orWhereNotIn('category_id', $leftOut))
            ->get();
    }

    /**
     * @param  list<int>  $values
     */
    private static function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$middle] : Money::round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
