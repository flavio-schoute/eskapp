<?php

namespace App\Models;

use App\Enums\CustomerMatch;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An e-Skool customer from Plug&Pay, matched with Mollie, for debiteurenbeheer. Identified by email address.
 */
#[Fillable([
    'email',
    'name',
    'company',
    'phone',
    'street',
    'house_number',
    'postal_code',
    'city',
    'country',
    'vat_number',
    'plug_and_pay_customer_id',
    'orders_count',
    'total_amount',
    'open_amount',
    'customer_since',
    'last_order_at',
    'mollie_customer_ids',
    'mollie_name',
    'mollie_email',
    'match_status',
    'synced_at',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mollie_customer_ids' => 'array',
            'match_status' => CustomerMatch::class,
            'orders_count' => 'integer',
            'total_amount' => 'decimal:2',
            'open_amount' => 'decimal:2',
            'customer_since' => 'datetime',
            'last_order_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * The customer's unpaid and charged back invoices, matched by email address.
     *
     * @return HasMany<DebtorCase, $this>
     */
    public function debtorCases(): HasMany
    {
        return $this->hasMany(DebtorCase::class, 'email', 'email');
    }

    /**
     * The address on one line, e.g. "Korvet 2, 3232TK Brielle, NL".
     */
    public function addressLine(): ?string
    {
        $line = collect([
            trim($this->street.' '.$this->house_number),
            trim($this->postal_code.' '.$this->city),
            $this->country,
        ])->filter()->implode(', ');

        return $line !== '' ? $line : null;
    }

    public function mollieDashboardUrl(string $customerId): string
    {
        return 'https://my.mollie.com/dashboard/'.config('services.mollie.dashboard_organization')."/customers/{$customerId}";
    }
}
