<?php

namespace App\Services;

use App\Enums\DebtorCaseType;
use App\Models\DebtorCase;
use App\Models\DebtorCaseEvent;
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
            $case = DebtorCase::firstOrNew(['plug_and_pay_order_id' => $order['id']]);
            $isNew = ! $case->exists;
            $wasResolved = $case->resolved_at !== null;
            $previousType = $case->type;

            $case->fill([...static::attributesFrom($order), 'type' => $type, 'resolved_at' => null, 'synced_at' => now()])->save();

            match (true) {
                $isNew => $case->recordEvent(DebtorCaseEvent::Opened, $type === DebtorCaseType::ChargedBack
                    ? 'Payment charged back (gestorneerd), case opened'
                    : 'Invoice unpaid, case opened', bySync: true),
                $wasResolved => $case->recordEvent(DebtorCaseEvent::Reopened, "Case reopened: {$type->getLabel()} again in Plug&Pay", bySync: true),
                $previousType !== $type && $type === DebtorCaseType::ChargedBack => $case->recordEvent(DebtorCaseEvent::ChargedBack, 'Payment charged back (gestorneerd)', bySync: true),
                default => null,
            };
        }

        $resolvedCases = DebtorCase::query()
            ->open()
            ->whereNotIn('plug_and_pay_order_id', $orders->map(fn (array $pair): int => $pair[0]['id'])->all())
            ->get();

        foreach ($resolvedCases as $case) {
            $case->update(['resolved_at' => now()]);
            $case->recordEvent(DebtorCaseEvent::Resolved, 'Paid or resolved in Plug&Pay, case closed', bySync: true);
        }

        $resolved = $resolvedCases->count();

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
            'email' => filled($contact['email'] ?? null) ? CustomerSync::normalizeEmail($contact['email']) : null,
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
