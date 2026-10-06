<?php

namespace App\Models;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use Database\Factories\AffiliateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'type',
    'status',
    'login_url',
    'username',
    'password',
    'agreement',
    'payment_method',
    'affiliate_link',
    'notes',
])]
class Affiliate extends Model
{
    /** @use HasFactory<AffiliateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AffiliateType::class,
            'status' => AffiliateStatus::class,
            'payment_method' => AffiliatePaymentMethod::class,
            'password' => 'encrypted',
        ];
    }
}
