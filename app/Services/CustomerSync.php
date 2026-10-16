<?php

namespace App\Services;

use App\Enums\CustomerMatch;
use App\Models\Affiliate;
use App\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mollie\Api\Http\Requests\GetPaginatedCustomerRequest;
use Mollie\Laravel\Facades\Mollie;

/**
 * Builds the customer list for debiteurenbeheer from Plug&Pay (customers with their totals, and the contact details
 * and address from their latest invoiced order) and matches each customer with Mollie: through the Mollie customer
 * used for their Plug&Pay payments, otherwise by email address.
 */
class CustomerSync
{
    public function __construct(
        public PlugAndPay $plugAndPay,
        public MollieCustomers $mollieCustomers,
    ) {}

    /**
     * @return array{customers: int, matched: int, mismatched: int}
     */
    public function sync(): array
    {
        $mollie = $this->mollieCustomers();
        $mollieByEmail = $mollie->groupBy(fn (array $customer): string => static::normalizeEmail($customer['email']));
        $orders = $this->latestOrderPerEmail();
        $seenMollieIds = [];
        $syncedEmails = [];

        foreach ($this->plugAndPay->customers() as $plugAndPayCustomer) {
            $email = static::normalizeEmail($plugAndPayCustomer['email'] ?? null);

            if ($email === '') {
                continue;
            }

            $order = $orders->get($email);
            $linkedIds = $order['mollie_customer_ids'] ?? [];
            $byEmail = ($mollieByEmail->get($email) ?? collect())->pluck('id')->all();
            $mollieIds = array_values(array_unique([...$linkedIds, ...$byEmail]));
            $mollieCustomer = collect([...array_reverse($linkedIds), ...$byEmail])->map(fn (string $id): ?array => $mollie->get($id))->filter()->first();
            $linkedIds = array_values(array_filter($linkedIds, fn (string $id): bool => $mollie->has($id)));
            $name = $plugAndPayCustomer['name'] ?? $order['name'] ?? null;

            array_push($seenMollieIds, ...$mollieIds);
            $syncedEmails[] = $email;

            Customer::updateOrCreate(['email' => $email], [
                'name' => $name,
                'company' => $order['company'] ?? null,
                'phone' => $order['phone'] ?? null,
                'street' => $order['street'] ?? null,
                'house_number' => $order['house_number'] ?? null,
                'postal_code' => $order['postal_code'] ?? null,
                'city' => $order['city'] ?? null,
                'country' => $order['country'] ?? null,
                'vat_number' => $order['vat_number'] ?? null,
                'plug_and_pay_customer_id' => $order['plug_and_pay_customer_id'] ?? null,
                'orders_count' => (int) ($plugAndPayCustomer['count'] ?? 0),
                'total_amount' => (float) ($plugAndPayCustomer['total'] ?? 0),
                'open_amount' => (float) ($plugAndPayCustomer['open'] ?? 0),
                'customer_since' => $plugAndPayCustomer['created_at'] ?? null,
                'last_order_at' => $plugAndPayCustomer['last_order'] ?? null,
                'mollie_customer_ids' => $mollieIds ?: null,
                'mollie_name' => $mollieCustomer['name'] ?? null,
                'mollie_email' => $mollieCustomer['email'] ?? null,
                'match_status' => static::matchStatus($email, $name, $linkedIds, $byEmail, $mollieCustomer),
                'synced_at' => now(),
            ]);
        }

        foreach ($mollie->except($seenMollieIds) as $mollieCustomer) {
            $email = static::normalizeEmail($mollieCustomer['email']);

            if ($email === '' || in_array($email, $syncedEmails, true)) {
                continue;
            }

            $syncedEmails[] = $email;

            Customer::updateOrCreate(['email' => $email], [
                'name' => $mollieCustomer['name'],
                'mollie_customer_ids' => [$mollieCustomer['id']],
                'mollie_name' => $mollieCustomer['name'],
                'mollie_email' => $mollieCustomer['email'],
                'customer_since' => $mollieCustomer['created_at'],
                'match_status' => CustomerMatch::MollieOnly,
                'synced_at' => now(),
            ]);
        }

        Customer::query()->whereNotIn('email', $syncedEmails)->delete();

        return [
            'customers' => count($syncedEmails),
            'matched' => Customer::query()->whereIn('match_status', [CustomerMatch::Linked, CustomerMatch::Email])->count(),
            'mismatched' => Customer::query()->where('match_status', CustomerMatch::Mismatch)->count(),
        ];
    }

    /**
     * @param  list<string>  $linkedIds
     * @param  list<string>  $byEmail
     * @param  array{id: string, name: ?string, email: ?string}|null  $mollieCustomer
     */
    public static function matchStatus(string $email, ?string $name, array $linkedIds, array $byEmail, ?array $mollieCustomer): CustomerMatch
    {
        if (! $mollieCustomer) {
            return CustomerMatch::PlugAndPayOnly;
        }

        $sameEmail = static::normalizeEmail($mollieCustomer['email']) === $email;
        $sameName = static::normalizeName($mollieCustomer['name']) === static::normalizeName($name);

        return match (true) {
            ! $sameEmail || ! $sameName => CustomerMatch::Mismatch,
            $linkedIds !== [] => CustomerMatch::Linked,
            default => CustomerMatch::Email,
        };
    }

    public static function normalizeEmail(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    public static function normalizeName(?string $name): string
    {
        return Str::of((string) $name)->ascii()->lower()->replaceMatches('/[^a-z]+/', '')->toString();
    }

    /**
     * Mollie customers keyed by ID, leaving out the affiliates' Mollie customers.
     *
     * @return Collection<string, array{id: string, name: ?string, email: ?string, created_at: ?string}>
     */
    protected function mollieCustomers(): Collection
    {
        if (! $this->mollieCustomers->isConfigured()) {
            return collect();
        }

        $affiliateIds = Affiliate::query()->whereNotNull('mollie_customer_id')->pluck('mollie_customer_id')->all();
        $customers = collect();

        foreach (Mollie::send(new GetPaginatedCustomerRequest(limit: 250))->getAutoIterator() as $customer) {
            if (! in_array($customer->id, $affiliateIds, true)) {
                $customers->put($customer->id, [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'created_at' => $customer->createdAt,
                ]);
            }
        }

        return $customers;
    }

    /**
     * The contact details and address from each customer's latest invoiced order, with the Mollie customers used
     * for their payments.
     *
     * @return Collection<string, array<string, mixed>>
     */
    protected function latestOrderPerEmail(): Collection
    {
        $latest = collect();

        foreach ($this->plugAndPay->orders(['invoice_status' => 'final'], ['billing', 'payment']) as $order) {
            $email = static::normalizeEmail(Arr::get($order, 'billing.contact.email'));

            if ($email === '') {
                continue;
            }

            $current = $latest->get($email);
            $mollieIds = array_values(array_unique(array_filter([...($current['mollie_customer_ids'] ?? []), Arr::get($order, 'payment.customer_id')])));

            if ($current && ($current['date'] ?? '') > ($order['created_at'] ?? '')) {
                $latest->put($email, [...$current, 'mollie_customer_ids' => $mollieIds]);

                continue;
            }

            $contact = Arr::get($order, 'billing.contact', []);
            $address = Arr::get($order, 'billing.address', []);
            $name = trim(($contact['firstname'] ?? '').' '.($contact['lastname'] ?? ''));

            $latest->put($email, [
                'date' => $order['created_at'] ?? '',
                'name' => $name !== '' ? $name : null,
                'company' => $contact['company'] ?? null,
                'phone' => $contact['telephone'] ?? null,
                'vat_number' => $contact['vat_id_number'] ?? null,
                'street' => $address['street'] ?? null,
                'house_number' => $address['housenumber'] ?? null,
                'postal_code' => $address['zipcode'] ?? null,
                'city' => $address['city'] ?? null,
                'country' => $address['country'] ?? null,
                'plug_and_pay_customer_id' => $order['customer_id'] ?? null,
                'mollie_customer_ids' => $mollieIds,
            ]);
        }

        return $latest;
    }
}
