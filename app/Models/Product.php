<?php

namespace App\Models;

use App\Models\Finance\Order;
use App\Models\Finance\RevenueStream;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product in Plug&Pay, marked as in use or not, with how often it was ordered, to clean up unused products.
 */
#[Fillable([
    'plug_and_pay_product_id',
    'title',
    'public_title',
    'type',
    'group',
    'sku',
    'in_use',
    'revenue_stream_id',
    'orders_count',
    'last_ordered_at',
    'plug_and_pay_created_at',
    'deleted_in_plug_and_pay_at',
    'synced_at',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The products e-Skool sells now; marked as in use when they are first synced.
     *
     * @var list<int>
     */
    public const InUseByDefault = [314885, 314888, 314890, 321827, 331701, 383367, 390459, 397583];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'in_use' => 'boolean',
            'orders_count' => 'integer',
            'last_ordered_at' => 'date',
            'plug_and_pay_created_at' => 'datetime',
            'deleted_in_plug_and_pay_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Whether the product was ordered in the last 3 months, so removing it needs a second look.
     */
    public function isRecentlyOrdered(): bool
    {
        return $this->last_ordered_at !== null && $this->last_ordered_at->gte(now()->subMonths(3)->startOfDay());
    }

    /**
     * Products marked as not in use that were still ordered in the last 3 months.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function recentlyOrderedButNotInUse(Builder $query): void
    {
        $query->where('in_use', false)->where('last_ordered_at', '>=', now()->subMonths(3)->startOfDay()->toDateString());
    }

    /**
     * @return BelongsTo<RevenueStream, $this>
     */
    public function revenueStream(): BelongsTo
    {
        return $this->belongsTo(RevenueStream::class);
    }

    /**
     * The Plug&Pay order lines of this product, for Finance.
     *
     * @return HasMany<Order, $this>
     */
    public function financeOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'plug_and_pay_product_id', 'plug_and_pay_product_id');
    }

    public function plugAndPayUrl(): string
    {
        return "https://admin.plugandpay.com/products/{$this->plug_and_pay_product_id}";
    }

    public function typeLabel(): ?string
    {
        return match ($this->type) {
            'one_off' => 'One-off',
            'installments' => 'Installments',
            'subscription' => 'Subscription',
            default => $this->type,
        };
    }
}
