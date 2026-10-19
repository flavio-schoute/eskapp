<?php

namespace App\Models\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Models\User;
use Database\Factories\Finance\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank or card line. Amounts are signed cents: negative is money out.
 */
#[Table('finance_transactions')]
#[Fillable([
    'account_id', 'import_id', 'dedupe_hash', 'booked_on', 'period_on', 'amount_cents', 'currency', 'counterparty_name',
    'counterparty_iban', 'description', 'mutation_code', 'mutation_type', 'balance_after_cents', 'raw', 'category_id',
    'vat_rate', 'amount_ex_vat_cents', 'status', 'rule_id', 'note', 'order_ref', 'order_match', 'categorized_by', 'categorized_at',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'period_on' => 'date',
            'amount_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'amount_ex_vat_cents' => 'integer',
            'vat_rate' => 'float',
            'raw' => 'array',
            'status' => TransactionStatus::class,
            'categorized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function categorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'categorized_by');
    }

    /**
     * @return HasMany<TransactionSplit, $this>
     */
    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    /**
     * Lines waiting in the inbox: not categorized, or categorized by a rule and not confirmed yet.
     *
     * @param  Builder<Transaction>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [TransactionStatus::Uncategorized, TransactionStatus::Auto]);
    }

    /**
     * The month the line counts in on the P&L: the override, otherwise the bank date.
     */
    public function plMonth(): string
    {
        return ($this->period_on ?? $this->booked_on)->format('Y-m');
    }
}
