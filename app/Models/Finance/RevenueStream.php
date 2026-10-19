<?php

namespace App\Models\Finance;

use App\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A revenue stream (e.g. Coaching sales): the paid orders of its products count as its revenue.
 */
#[Table('finance_revenue_streams')]
#[Fillable(['name', 'color', 'sort_order'])]
class RevenueStream extends Model
{
    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'revenue_stream_id');
    }
}
