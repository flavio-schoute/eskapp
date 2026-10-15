<?php

namespace App\Models;

use App\Enums\DebtorCaseType;
use Database\Factories\DebtorCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An unpaid or charged back Plug&Pay order that needs following up (debiteurenbeheer).
 */
#[Fillable([
    'plug_and_pay_order_id',
    'type',
    'invoice_number',
    'invoice_date',
    'product',
    'amount',
    'amount_with_tax',
    'customer_name',
    'company',
    'email',
    'phone',
    'country',
    'payment_url',
    'invoice_url',
    'mollie_transaction_id',
    'mollie_customer_id',
    'plug_and_pay_debtor_status',
    'plug_and_pay_debtor_step',
    'resolved_at',
    'synced_at',
])]
class DebtorCase extends Model
{
    /** @use HasFactory<DebtorCaseFactory> */
    use HasFactory;

    /**
     * Country calling codes for turning a local phone number (06…) into an international one for WhatsApp.
     *
     * @var array<string, string>
     */
    public const CallingCodes = ['NL' => '31', 'BE' => '32', 'DE' => '49', 'FR' => '33', 'LU' => '352', 'GB' => '44', 'ES' => '34', 'SR' => '597', 'CW' => '599'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DebtorCaseType::class,
            'invoice_date' => 'date',
            'amount' => 'decimal:2',
            'amount_with_tax' => 'decimal:2',
            'plug_and_pay_debtor_step' => 'integer',
            'resolved_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Cases that still need following up.
     *
     * @param  Builder<DebtorCase>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function plugAndPayUrl(): string
    {
        return "https://admin.plugandpay.com/orders/{$this->plug_and_pay_order_id}";
    }

    /**
     * Days since the invoice date.
     */
    public function daysOpen(): ?int
    {
        return $this->invoice_date ? (int) $this->invoice_date->diffInDays(now(), true) : null;
    }

    /**
     * The phone number in international format without "+", for a wa.me link; null when there is none.
     */
    public function whatsAppNumber(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone);

        return match (true) {
            $digits === '' => null,
            str_starts_with((string) $this->phone, '+') => $digits,
            str_starts_with($digits, '00') => substr($digits, 2),
            str_starts_with($digits, '0') => (self::CallingCodes[$this->country ?? 'NL'] ?? '31').substr($digits, 1),
            default => $digits,
        };
    }

    /**
     * A WhatsApp link that opens a chat with the message filled in.
     */
    public function whatsAppUrl(string $message): ?string
    {
        $number = $this->whatsAppNumber();

        return $number ? "https://wa.me/{$number}?text=".rawurlencode($message) : null;
    }
}
