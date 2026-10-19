<?php

namespace App\Services\Finance;

use App\Models\Finance\Account;
use App\Models\Finance\Transaction;
use Illuminate\Support\Carbon;

/**
 * The bank balance (from the balance after each line in the bank export) at every month end, and a forecast that
 * continues the average cash flow of the last three complete months.
 */
class Cashflow
{
    /**
     * @return array{balance: int|null, balanceOn: string|null, monthlyFlow: int|null, history: array<string, int>, forecast: array<string, int>}
     */
    public static function build(int $monthsBack = 6, int $monthsAhead = 3, ?Carbon $today = null): array
    {
        $today ??= now();
        $accountIds = Account::query()->whereHas('transactions', fn ($query) => $query->whereNotNull('balance_after_cents'))->pluck('id');

        if ($accountIds->isEmpty()) {
            return ['balance' => null, 'balanceOn' => null, 'monthlyFlow' => null, 'history' => [], 'forecast' => []];
        }

        $history = [];

        for ($offset = $monthsBack; $offset >= 1; $offset--) {
            $monthEnd = $today->copy()->startOfMonth()->subMonthsNoOverflow($offset)->endOfMonth();
            $balance = static::balanceOn($accountIds->all(), $monthEnd);

            if ($balance !== null) {
                $history[$monthEnd->format('Y-m')] = $balance;
            }
        }

        $balance = static::balanceOn($accountIds->all(), $today->copy()->endOfDay());
        $balanceOn = Transaction::query()->whereIn('account_id', $accountIds)->whereNotNull('balance_after_cents')->max('booked_on');
        $history[$today->format('Y-m')] = $balance;

        // The change over the last three complete months, per month.
        $complete = array_slice(array_diff_key($history, [$today->format('Y-m') => true]), -4, 4, true);
        $monthlyFlow = count($complete) >= 2 ? Money::round((end($complete) - reset($complete)) / (count($complete) - 1)) : null;

        $forecast = [];

        if ($monthlyFlow !== null) {
            foreach (range(1, $monthsAhead) as $ahead) {
                $forecast[$today->copy()->startOfMonth()->addMonthsNoOverflow($ahead)->format('Y-m')] = $balance + $monthlyFlow * $ahead;
            }
        }

        return [
            'balance' => $balance,
            'balanceOn' => $balanceOn ? Carbon::parse($balanceOn)->format('Y-m-d') : null,
            'monthlyFlow' => $monthlyFlow,
            'history' => $history,
            'forecast' => $forecast,
        ];
    }

    /**
     * The sum of the last known balance of each account on a date; null when no account has a balance by then.
     *
     * @param  list<int>  $accountIds
     */
    private static function balanceOn(array $accountIds, Carbon $date): ?int
    {
        $balances = collect($accountIds)
            ->map(fn (int $accountId): ?int => Transaction::query()
                ->where('account_id', $accountId)
                ->whereNotNull('balance_after_cents')
                ->whereDate('booked_on', '<=', $date)
                ->orderByDesc('booked_on')
                ->orderByDesc('id')
                ->value('balance_after_cents'))
            ->filter(fn (?int $balance): bool => $balance !== null);

        return $balances->isEmpty() ? null : (int) $balances->sum();
    }
}
