<?php

namespace App\Services\Finance;

use App\Enums\Finance\CategoryKind;
use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Category;
use App\Models\Finance\Order;
use App\Models\Finance\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Matches money coming in on the bank to its Plug&Pay order: by the invoice or order number in the description, or
 * else by an amount only one order has. The revenue already counts through the order, so a matched line belongs on
 * "Klantbetaling per bank (Plug & Pay)", an internal transfer. Lines are re-matched after every order sync, so a
 * line becomes "betaald" as soon as the order is paid in Plug&Pay.
 */
class PaymentMatcher
{
    public const CategoryCode = 'TR-PNP';

    public const Paid = 'paid';

    public const Open = 'open';

    public const None = 'none';

    /**
     * Match the incoming lines booked since a date; returns how many lines are linked to an order.
     */
    public function matchIncoming(?Carbon $since = null): int
    {
        $since ??= now()->subDays(120);
        $category = Category::query()->firstWhere('code', self::CategoryCode);
        $orders = static::ordersSince($since->copy()->subDays(90));
        $matched = 0;

        Transaction::query()
            ->with('category')
            ->where('amount_cents', '>', 0)
            ->whereDate('booked_on', '>=', $since)
            ->lazyById(500)
            ->each(function (Transaction $transaction) use ($orders, $category, &$matched): void {
                if (! static::isCandidate($transaction)) {
                    if ($transaction->order_match !== null) {
                        $transaction->update(['order_ref' => null, 'order_match' => null]);
                    }

                    return;
                }

                $match = static::match($transaction, $orders);
                $fields = ['order_ref' => $match['ref'] ?? null, 'order_match' => $match['status'] ?? self::None];

                // A line with the invoice number in it is proposed for the transfer category; a person confirms.
                if ($match && $match['by'] === 'reference' && $category && $transaction->status === TransactionStatus::Uncategorized) {
                    $fields = [...$fields, ...Categorizer::fieldsFor($transaction->amount_cents, $category, TransactionStatus::Auto), 'categorized_at' => now()];
                }

                $transaction->update($fields);
                $matched += (int) ($match !== null);
            });

        return $matched;
    }

    /**
     * Money in that could be a customer paying: not categorized yet, booked as customer payment or as revenue.
     * Payment provider payouts, own accounts and excluded lines are left alone.
     */
    public static function isCandidate(Transaction $transaction): bool
    {
        if ($transaction->amount_cents <= 0 || $transaction->status === TransactionStatus::Excluded) {
            return false;
        }

        return $transaction->category === null
            || $transaction->category->code === self::CategoryCode
            || $transaction->category->kind === CategoryKind::Revenue;
    }

    /**
     * @param  Collection<string, array{ref: string, orderId: int, status: string, total: int, invoicedOn: ?Carbon}>  $orders  Keyed by invoice number.
     * @return array{ref: string, status: string, by: string}|null
     */
    public static function match(Transaction $transaction, Collection $orders): ?array
    {
        preg_match_all('/[A-Z0-9][A-Z0-9-]{5,}/', mb_strtoupper("{$transaction->description} {$transaction->counterparty_name}"), $tokens);
        $byOrderId = $orders->keyBy('orderId');

        foreach (array_unique($tokens[0]) as $token) {
            $order = $orders->get($token) ?? (ctype_digit($token) ? $byOrderId->get((int) $token) : null);

            if ($order) {
                return ['ref' => $order['ref'], 'status' => $order['status'], 'by' => 'reference'];
            }
        }

        $sameAmount = $orders->filter(fn (array $order): bool => $order['total'] === $transaction->amount_cents
            && $order['invoicedOn'] !== null
            && $order['invoicedOn']->between($transaction->booked_on->copy()->subDays(60), $transaction->booked_on->copy()->addDays(3)));
        $open = $sameAmount->where('status', '!=', self::Paid);
        $order = match (true) {
            $open->count() === 1 => $open->first(),
            $sameAmount->count() === 1 => $sameAmount->first(),
            default => null,
        };

        return $order ? ['ref' => $order['ref'], 'status' => $order['status'], 'by' => 'amount'] : null;
    }

    /**
     * The Plug&Pay orders invoiced since a date, one entry per order with the total incl. VAT of its lines.
     *
     * @return Collection<string, array{ref: string, orderId: int, status: string, total: int, invoicedOn: ?Carbon}>
     */
    public static function ordersSince(Carbon $since): Collection
    {
        return Order::query()
            ->whereDate('invoiced_on', '>=', $since)
            ->get(['plug_and_pay_order_id', 'order_ref', 'status', 'total_cents', 'invoiced_on'])
            ->toBase()
            ->groupBy(fn (Order $order): string => mb_strtoupper($order->order_ref))
            ->map(fn (Collection $lines, string $ref): array => [
                'ref' => $lines->first()->order_ref,
                'orderId' => (int) $lines->first()->plug_and_pay_order_id,
                'status' => $lines->first()->status === self::Paid ? self::Paid : self::Open,
                'total' => (int) $lines->sum('total_cents'),
                'invoicedOn' => $lines->first()->invoiced_on ? Carbon::parse($lines->first()->invoiced_on) : null,
            ]);
    }
}
