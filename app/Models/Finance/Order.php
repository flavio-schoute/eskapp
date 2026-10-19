<?php

namespace App\Models\Finance;

use App\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Plug&Pay order line. Paid lines count as revenue on their payment date, excl. VAT.
 */
#[Table('finance_orders')]
#[Fillable([
    'external_id', 'import_id', 'plug_and_pay_order_id', 'order_ref', 'plug_and_pay_product_id', 'sku', 'product_name', 'quantity',
    'subtotal_ex_vat_cents', 'vat_cents', 'total_cents', 'status', 'invoiced_on', 'paid_on', 'customer_email', 'synced_at',
])]
class Order extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal_ex_vat_cents' => 'integer',
            'vat_cents' => 'integer',
            'total_cents' => 'integer',
            'quantity' => 'float',
            'invoiced_on' => 'date',
            'paid_on' => 'date',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'plug_and_pay_product_id', 'plug_and_pay_product_id');
    }
}
