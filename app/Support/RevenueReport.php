<?php

namespace App\Support;

use App\Models\AffiliateInvoice;
use App\Models\AppSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per month: how much was invoiced to affiliates (by invoice date), how much was received (by payment date),
 * and the affiliate manager's commission over what was received. Amounts are excl. VAT unless stated otherwise:
 * VAT is passed on to the tax office and is not revenue.
 */
class RevenueReport
{
    public const SettingKey = 'manager_commission';

    public const Timezone = 'Europe/Amsterdam';

    /**
     * @return array{manager: string, percentage: float}
     */
    public static function commissionSettings(): array
    {
        $saved = AppSetting::get(self::SettingKey, []);

        return [
            'manager' => $saved['manager'] ?? 'Ben Durinck',
            'percentage' => (float) ($saved['percentage'] ?? 10),
        ];
    }

    /**
     * @param  array{manager: string, percentage: float|int|string}  $settings
     */
    public static function saveCommissionSettings(array $settings): void
    {
        AppSetting::put(self::SettingKey, [
            'manager' => trim($settings['manager']),
            'percentage' => (float) $settings['percentage'],
        ]);
    }

    /**
     * The totals for one month.
     *
     * @return array{month: string, label: string, invoiced_count: int, invoiced: float, invoiced_incl_vat: float, received_count: int, received: float, received_incl_vat: float, commission: float}
     */
    public static function forMonth(Carbon $month): array
    {
        [$from, $until] = static::boundsOf($month);

        $invoiced = static::invoicedBetween($from, $until);
        $received = static::receivedBetween($from, $until);
        $receivedNet = round((float) $received->sum('amount'), 2);

        return [
            'month' => $from->format('Y-m'),
            'label' => $from->locale('nl')->translatedFormat('F Y'),
            'invoiced_count' => $invoiced->count(),
            'invoiced' => round((float) $invoiced->sum('amount'), 2),
            'invoiced_incl_vat' => round($invoiced->sum(fn (AffiliateInvoice $invoice): float => static::inclVat($invoice)), 2),
            'received_count' => $received->count(),
            'received' => $receivedNet,
            'received_incl_vat' => round($received->sum(fn (AffiliateInvoice $invoice): float => static::inclVat($invoice)), 2),
            'commission' => static::commissionOver($receivedNet),
        ];
    }

    /**
     * The totals for the last months, newest first, keyed by month (Y-m).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function lastMonths(int $months = 12): array
    {
        return collect(range(0, $months - 1))
            ->map(fn (int $monthsAgo): array => static::forMonth(now(self::Timezone)->startOfMonth()->subMonthsNoOverflow($monthsAgo)))
            ->keyBy('month')
            ->all();
    }

    /**
     * Per affiliate: what was invoiced and received in the month.
     *
     * @return list<array{affiliate: string, invoiced: float, received: float, commission: float}>
     */
    public static function perAffiliate(Carbon $month): array
    {
        [$from, $until] = static::boundsOf($month);

        $invoiced = static::invoicedBetween($from, $until)->groupBy(fn (AffiliateInvoice $invoice): string => $invoice->affiliate?->name ?? 'Deleted affiliate');
        $received = static::receivedBetween($from, $until)->groupBy(fn (AffiliateInvoice $invoice): string => $invoice->affiliate?->name ?? 'Deleted affiliate');

        return $invoiced->keys()->merge($received->keys())->unique()->sort()
            ->map(function (string $affiliate) use ($invoiced, $received): array {
                $receivedNet = round((float) ($received->get($affiliate)?->sum('amount') ?? 0), 2);

                return [
                    'affiliate' => $affiliate,
                    'invoiced' => round((float) ($invoiced->get($affiliate)?->sum('amount') ?? 0), 2),
                    'received' => $receivedNet,
                    'commission' => static::commissionOver($receivedNet),
                ];
            })
            ->values()
            ->all();
    }

    public static function commissionOver(float $received): float
    {
        return round($received * static::commissionSettings()['percentage'] / 100, 2);
    }

    public static function money(float $amount): string
    {
        return '€ '.number_format($amount, 2, ',', '.');
    }

    /**
     * Invoices with an invoice date in the period; cancelled invoices do not count.
     *
     * @return Collection<int, AffiliateInvoice>
     */
    protected static function invoicedBetween(Carbon $from, Carbon $until): Collection
    {
        return AffiliateInvoice::query()
            ->with('affiliate')
            ->whereBetween('created_at', [$from->copy()->utc(), $until->copy()->utc()])
            ->where('status', '!=', 'cancelled')
            ->get();
    }

    /**
     * Invoices paid in the period.
     *
     * @return Collection<int, AffiliateInvoice>
     */
    protected static function receivedBetween(Carbon $from, Carbon $until): Collection
    {
        return AffiliateInvoice::query()
            ->with('affiliate')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from->copy()->utc(), $until->copy()->utc()])
            ->get();
    }

    protected static function inclVat(AffiliateInvoice $invoice): float
    {
        return (float) ($invoice->total_amount ?? round((float) $invoice->amount * (1 + (float) $invoice->vat_rate / 100), 2));
    }

    /**
     * The first and last moment of a month in Amsterdam time.
     *
     * @return array{Carbon, Carbon}
     */
    protected static function boundsOf(Carbon $month): array
    {
        $start = Carbon::parse($month->format('Y-m-01'), self::Timezone)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}
