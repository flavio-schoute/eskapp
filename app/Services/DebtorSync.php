<?php

namespace App\Services;

use App\Enums\DebtorCaseType;
use App\Models\DebtorCase;
use Illuminate\Support\Arr;

/**
 * Keeps the debtor cases in step with Plug&Pay: unpaid invoices (payment open with a final invoice) and charged back
 * payments (payment reversed). Cases that are no longer unpaid or charged back in Plug&Pay are marked resolved.
 */
class DebtorSync
{
    public function __construct(public PlugAndPay $plugAndPay) {}

    /**
     * @return array{open: int, resolved: int}
     */
    public function sync(): array
    {
        $includes = ['billing', 'payment', 'items'];

        $orders = $this->plugAndPay->orders(['payment_status' => 'open', 'invoice_status' => 'final'], $includes)
            ->map(fn (array $order): array => [$order, DebtorCaseType::Unpaid])
            ->concat($this->plugAndPay->orders(['payment_status' => 'reversed'], $includes)
                ->map(fn (array $order): array => [$order, DebtorCaseType::ChargedBack]))
            ->collect();

        foreach ($orders as [$order, $type]) {
            DebtorCase::updateOrCreate(
                ['plug_and_pay_order_id' => $order['id']],
                [...static::attributesFrom($order), 'type' => $type, 'resolved_at' => null, 'synced_at' => now()],
            );
        }

        $resolved = DebtorCase::query()
            ->open()
            ->whereNotIn('plug_and_pay_order_id', $orders->map(fn (array $pair): int => $pair[0]['id'])->all())
            ->update(['resolved_at' => now()]);

        return ['open' => $orders->count(), 'resolved' => $resolved];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    public static function attributesFrom(array $order): array
    {
        $contact = Arr::get($order, 'billing.contact', []);
        $name = trim(($contact['firstname'] ?? '').' '.($contact['lastname'] ?? ''));

        return [
            'invoice_number' => $order['invoice_number'] ?? null,
            'invoice_date' => $order['invoice_date'] ?? null,
            'product' => collect($order['items'] ?? [])->pluck('label')->filter()->implode(', ') ?: null,
            'amount' => $order['amount'] ?? 0,
            'amount_with_tax' => $order['amount_with_tax'] ?? 0,
            'customer_name' => $name !== '' ? $name : null,
            'company' => $contact['company'] ?? null,
            'email' => $contact['email'] ?? null,
            'phone' => $contact['telephone'] ?? null,
            'country' => Arr::get($order, 'billing.address.country'),
            'payment_url' => Arr::get($order, 'payment.url'),
            'invoice_url' => $order['invoice_url'] ?? null,
            'mollie_transaction_id' => Arr::get($order, 'payment.transaction_id'),
            'mollie_customer_id' => Arr::get($order, 'payment.customer_id') ?? ($order['customer_id'] ?? null),
            'plug_and_pay_debtor_status' => $order['debtor_status'] ?? null,
            'plug_and_pay_debtor_step' => $order['debtor_step'] ?? null,
        ];
    }
}
