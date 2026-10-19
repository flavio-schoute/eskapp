<?php

namespace App\Services\Finance;

use App\Models\Finance\Order;
use App\Models\Product;
use App\Services\PlugAndPay;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Fetches the invoiced Plug&Pay orders through the API, one row per order line, replacing the weekly CSV export.
 * Paid lines count as revenue on their payment date, excl. VAT; a line that is later charged back or credited stops
 * counting.
 */
class OrderSync
{
    public function __construct(public PlugAndPay $plugAndPay) {}

    /**
     * @param  Carbon|null  $since  Only orders invoiced since this date (for the frequent sync); null fetches all.
     * @return array{lines: int, new: int, updated: int}
     */
    public function sync(?Carbon $since = null): array
    {
        $filters = ['invoice_status' => 'final'];

        if ($since) {
            $filters['since_invoice_date'] = $since->toDateString();
        }

        $skus = Product::query()->pluck('sku', 'plug_and_pay_product_id');
        $existing = Order::query()->pluck('updated_at', 'external_id');
        $lines = 0;
        $new = 0;
        $updated = 0;

        foreach ($this->plugAndPay->orders($filters, ['items', 'payment', 'billing']) as $order) {
            $paidAt = Arr::get($order, 'payment.paid_at');
            $status = Arr::get($order, 'payment.status') ?? 'open';

            foreach ($order['items'] ?? [] as $item) {
                $externalId = "{$order['id']}#{$item['id']}";
                $subtotal = Money::parseToCents((string) ($item['amount'] ?? 0));
                $total = Money::parseToCents((string) ($item['amount_with_tax'] ?? $item['amount'] ?? 0));

                $record = Order::updateOrCreate(['external_id' => $externalId], [
                    'plug_and_pay_order_id' => $order['id'],
                    'order_ref' => $order['invoice_number'] ?? (string) $order['id'],
                    'plug_and_pay_product_id' => $item['product_id'] ?? null,
                    'sku' => isset($item['product_id']) ? ($skus[$item['product_id']] ?? null) : null,
                    'product_name' => (string) ($item['label'] ?? ''),
                    'quantity' => (float) ($item['quantity'] ?? 1),
                    'subtotal_ex_vat_cents' => $subtotal,
                    'vat_cents' => $total - $subtotal,
                    'total_cents' => $total,
                    'status' => $status,
                    'invoiced_on' => $order['invoice_date'] ?? null,
                    'paid_on' => $status === 'paid' && $paidAt ? Carbon::parse($paidAt)->timezone('Europe/Amsterdam')->toDateString() : null,
                    'customer_email' => Arr::get($order, 'billing.contact.email'),
                    'synced_at' => now(),
                ]);

                $lines++;
                $new += (int) $record->wasRecentlyCreated;
                $updated += (int) (! $record->wasRecentlyCreated && $record->wasChanged(['status', 'paid_on', 'subtotal_ex_vat_cents']));
            }
        }

        return ['lines' => $lines, 'new' => $new, 'updated' => $updated];
    }
}
