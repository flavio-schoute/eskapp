<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

/**
 * Client for the Plug&Pay API (https://api.plugandpay.com/v2), where e-Skool's customer orders, invoices and
 * subscriptions live; Mollie is their payment provider. The official SDK needs Guzzle 7, so this uses Laravel's
 * HTTP client directly.
 */
class PlugAndPay
{
    private const ApiUrl = 'https://api.plugandpay.com/v2';

    /**
     * Payment statuses of an order in Plug&Pay. "reversed" is a charged back payment.
     *
     * @var list<string>
     */
    public const PaymentStatuses = ['open', 'processing', 'paid', 'reversed', 'credited', 'credit_invoice'];

    public function isConfigured(): bool
    {
        return filled(config('services.plug_and_pay.api_key'));
    }

    /**
     * Get one order with its billing details (contact and address) and payment.
     *
     * @param  list<string>  $include
     * @return array<string, mixed>
     */
    public function order(int $orderId, array $include = ['billing', 'payment', 'items']): array
    {
        return $this->request()->get("/orders/{$orderId}", ['include' => implode(',', $include)])->throw()->json('data');
    }

    /**
     * All orders matching the filters, fetched page by page while they are used.
     *
     * @param  array<string, string|int|list<string>>  $filters  E.g. ['payment_status' => ['reversed', 'open']].
     * @param  list<string>  $include
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function orders(array $filters = [], array $include = ['billing', 'payment'], int $perPage = 100): LazyCollection
    {
        $query = collect($filters)
            ->map(fn (mixed $value): string => is_array($value) ? implode(',', $value) : (string) $value)
            ->put('include', implode(',', $include))
            ->put('limit', $perPage)
            ->all();

        return $this->paginate('/orders', $query);
    }

    /**
     * All customers with their order totals (name, email, count, total, open, last_order), page by page.
     * Plug&Pay returns 20 customers per page, whatever limit is asked.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function customers(): LazyCollection
    {
        return $this->paginate('/customers');
    }

    /**
     * All products (title, type, sku, ...), page by page; with $deleted the products deleted in Plug&Pay instead.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function products(bool $deleted = false): LazyCollection
    {
        return $this->paginate('/products', ['limit' => 100, ...($deleted ? ['is_deleted' => 1] : [])]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    private function paginate(string $path, array $query = []): LazyCollection
    {
        return LazyCollection::make(function () use ($path, $query) {
            $page = 1;

            do {
                $response = $this->request()->get($path, [...$query, 'page' => $page])->throw();

                foreach ($response->json('data') ?? [] as $item) {
                    yield $item;
                }

                $lastPage = (int) $response->json('meta.last_page', 1);
                $page++;
            } while ($page <= $lastPage);
        });
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::ApiUrl)
            ->withToken(config('services.plug_and_pay.api_key'))
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 1000, throw: false);
    }
}
