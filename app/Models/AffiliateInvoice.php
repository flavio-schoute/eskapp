<?php

namespace App\Models;

use Database\Factories\AffiliateInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'affiliate_id',
    'created_by',
    'mollie_sales_invoice_id',
    'invoice_number',
    'status',
    'due_at',
    'paid_at',
    'status_checked_at',
    'period',
    'description',
    'amount',
    'vat_rate',
    'total_amount',
    'sent_to',
    'pdf_url',
    'slack_posted_at',
])]
class AffiliateInvoice extends Model
{
    /**
     * Mollie statuses after which an invoice no longer changes, so it does not need to be checked again.
     *
     * @var list<string>
     */
    public const FinalStatuses = ['paid', 'cancelled', 'expired'];

    /** @use HasFactory<AffiliateInvoiceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'slack_posted_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'status_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Whether the invoice is still waiting to be paid (issued, payment pending or overdue).
     */
    public function isAwaitingPayment(): bool
    {
        return in_array($this->status, ['issued', 'issuing', 'pending-payment', 'overdue'], true);
    }

    /**
     * Invoices whose status can still change at Mollie.
     *
     * @param  Builder<AffiliateInvoice>  $query
     */
    #[Scope]
    protected function notFinal(Builder $query): void
    {
        $query->whereNotIn('status', self::FinalStatuses);
    }

    /**
     * Unpaid invoices past their due date.
     *
     * @param  Builder<AffiliateInvoice>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->whereIn('status', ['issued', 'issuing', 'pending-payment', 'overdue'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->startOfDay());
    }

    /**
     * Days until the due date: negative when overdue, null when not awaiting payment or without a due date.
     */
    public function daysUntilDue(): ?int
    {
        if (! $this->isAwaitingPayment() || ! $this->due_at) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_at->copy()->startOfDay(), false);
    }
}
