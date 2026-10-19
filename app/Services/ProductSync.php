<?php

namespace App\Services;

use App\Models\Finance\RevenueStream;
use App\Models\Product;
use App\Support\Finance\ProductStreams;

/**
 * Fetches the products from Plug&Pay, also the deleted ones, with how often and how recently each was ordered (from
 * the invoiced orders), so unused products can be cleaned up. Whether a product is in use, and its revenue stream,
 * are kept as they were set in the app; a new product with a known SKU gets that SKU's revenue stream.
 */
class ProductSync
{
    public function __construct(public PlugAndPay $plugAndPay) {}

    /**
     * @return array{products: int, never_ordered: int}
     */
    public function sync(): array
    {
        $ordersCount = [];
        $lastOrdered = [];

        foreach ($this->plugAndPay->orders(['invoice_status' => 'final'], ['items']) as $order) {
            foreach ($order['items'] ?? [] as $item) {
                $productId = $item['product_id'] ?? null;

                if (! $productId) {
                    continue;
                }

                $ordersCount[$productId] = ($ordersCount[$productId] ?? 0) + 1;
                $lastOrdered[$productId] = max($lastOrdered[$productId] ?? '', substr((string) $order['created_at'], 0, 10));
            }
        }

        $streams = RevenueStream::query()->pluck('id', 'name');
        $syncedIds = [];

        foreach ([false, true] as $deleted) {
            foreach ($this->plugAndPay->products($deleted) as $remote) {
                $product = Product::firstOrNew(['plug_and_pay_product_id' => $remote['id']]);

                if (! $product->exists) {
                    $product->in_use = in_array((int) $remote['id'], Product::InUseByDefault, true);
                    $product->revenue_stream_id = $streams[ProductStreams::streamFor($remote['sku'] ?? null)] ?? null;
                }

                $product->fill([
                    'title' => $remote['title'] ?? "Product {$remote['id']}",
                    'public_title' => $remote['public_title'] ?? null,
                    'type' => $remote['type'] ?? null,
                    'group' => $remote['group'] ?? null,
                    'sku' => filled($remote['sku'] ?? null) ? trim($remote['sku']) : null,
                    'orders_count' => $ordersCount[$remote['id']] ?? 0,
                    'last_ordered_at' => $lastOrdered[$remote['id']] ?? null,
                    'plug_and_pay_created_at' => $remote['created_at'] ?? null,
                    'deleted_in_plug_and_pay_at' => $deleted ? ($remote['deleted_at'] ?? now()) : null,
                    'synced_at' => now(),
                ])->save();

                $syncedIds[] = $remote['id'];
            }
        }

        // Products are never removed: their orders still count as revenue in Finance.
        Product::query()->whereNotIn('plug_and_pay_product_id', $syncedIds)->whereNull('deleted_in_plug_and_pay_at')->update(['deleted_in_plug_and_pay_at' => now()]);

        return [
            'products' => Product::query()->whereNull('deleted_in_plug_and_pay_at')->count(),
            'never_ordered' => Product::query()->whereNull('deleted_in_plug_and_pay_at')->where('orders_count', 0)->count(),
        ];
    }
}
