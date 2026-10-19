<?php

namespace App\Services\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Category;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * What people do with bank lines: categorize, confirm a rule's proposal, exclude, reset, split, change the VAT or
 * the P&L month, and re-run the rules over the open lines.
 */
class Bookkeeping
{
    /**
     * Give lines a category and mark them confirmed: a person decided.
     *
     * @param  list<int>  $transactionIds
     */
    public function setCategory(array $transactionIds, Category $category, ?User $user, ?float $vatRate = null, ?string $note = null): int
    {
        if ($category->isGroup() && $category->children()->exists()) {
            throw new InvalidArgumentException('Kies een subcategorie, niet de hoofdgroep.');
        }

        return $this->apply($transactionIds, fn (Transaction $transaction): array => [
            // A rate someone picked on an open line before categorizing it is kept.
            ...Categorizer::fieldsFor($transaction->amount_cents, $category, TransactionStatus::Confirmed, $vatRate ?? ($transaction->status === TransactionStatus::Uncategorized ? $transaction->vat_rate : null)),
            ...($note !== null ? ['note' => $note] : []),
            'categorized_by' => $user?->getKey(),
        ]);
    }

    /**
     * Accept the rules' proposals as they are.
     *
     * @param  list<int>  $transactionIds
     */
    public function confirm(array $transactionIds, ?User $user): int
    {
        return Transaction::query()
            ->whereKey($transactionIds)
            ->where('status', TransactionStatus::Auto)
            ->whereNotNull('category_id')
            ->update(['status' => TransactionStatus::Confirmed, 'categorized_by' => $user?->getKey(), 'categorized_at' => now()]);
    }

    /**
     * Leave lines out of the P&L entirely, e.g. transfers between own accounts.
     *
     * @param  list<int>  $transactionIds
     */
    public function exclude(array $transactionIds, ?User $user, ?string $note = null): int
    {
        return $this->apply($transactionIds, fn (Transaction $transaction): array => [
            ...Categorizer::fieldsFor($transaction->amount_cents, null, TransactionStatus::Excluded),
            ...($note !== null ? ['note' => $note] : []),
            'categorized_by' => $user?->getKey(),
        ]);
    }

    /**
     * Put lines back in the inbox without a category, and remove their splits.
     *
     * @param  list<int>  $transactionIds
     */
    public function reset(array $transactionIds): int
    {
        return DB::transaction(function () use ($transactionIds): int {
            TransactionSplit::query()->whereIn('transaction_id', $transactionIds)->delete();

            return $this->apply($transactionIds, fn (Transaction $transaction): array => [
                ...Categorizer::fieldsFor($transaction->amount_cents, null, TransactionStatus::Uncategorized),
                'categorized_by' => null,
            ]);
        });
    }

    public function setVatRate(Transaction $transaction, float $vatRate): void
    {
        if ($vatRate < 0 || $vatRate > 1) {
            throw new InvalidArgumentException("Ongeldig btw-tarief: {$vatRate}");
        }

        $transaction->update(['vat_rate' => $vatRate, 'amount_ex_vat_cents' => Money::exVatCents($transaction->amount_cents, $vatRate)]);
    }

    /**
     * Move a line to another P&L month (e.g. paid in April, belongs to March); null uses the bank date.
     */
    public function setPeriod(Transaction $transaction, ?string $month): void
    {
        if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new InvalidArgumentException("Ongeldige maand: {$month}");
        }

        $transaction->update(['period_on' => $month ? "{$month}-01" : null]);
    }

    /**
     * Replace a line's splits. The parts must add up exactly to the bank amount; an empty list removes the splits and
     * puts the line back in the inbox.
     *
     * @param  list<array{category_id: int, amount_cents: int, vat_rate?: float|null, note?: string|null}>  $splits
     */
    public function saveSplits(Transaction $transaction, array $splits, ?User $user): void
    {
        if (count($splits) === 1) {
            throw new InvalidArgumentException('Een splitsing heeft minstens twee delen.');
        }

        $sum = array_sum(array_column($splits, 'amount_cents'));

        if ($splits !== [] && $sum !== $transaction->amount_cents) {
            throw new InvalidArgumentException('De delen tellen op tot '.Money::format($sum).', maar de transactie is '.Money::format($transaction->amount_cents).'.');
        }

        $categories = Category::query()->whereKey(array_column($splits, 'category_id'))->get()->keyBy('id');

        DB::transaction(function () use ($transaction, $splits, $user, $categories): void {
            $transaction->splits()->delete();

            if ($splits === []) {
                $transaction->update(['category_id' => null, 'vat_rate' => null, 'amount_ex_vat_cents' => null, 'status' => TransactionStatus::Uncategorized, 'rule_id' => null, 'categorized_by' => null, 'categorized_at' => now()]);

                return;
            }

            foreach ($splits as $split) {
                $category = $categories->get($split['category_id']) ?? throw new InvalidArgumentException('Categorie niet gevonden');
                $vat = $category->kind->countsInProfitAndLoss() ? ($split['vat_rate'] ?? $category->default_vat_rate ?? 0.0) : 0.0;

                $transaction->splits()->create([
                    'category_id' => $category->getKey(),
                    'amount_cents' => $split['amount_cents'],
                    'vat_rate' => $vat,
                    'amount_ex_vat_cents' => Money::exVatCents($split['amount_cents'], $vat),
                    'note' => $split['note'] ?? null,
                ]);
            }

            $transaction->update(['category_id' => null, 'vat_rate' => null, 'amount_ex_vat_cents' => null, 'status' => TransactionStatus::Confirmed, 'rule_id' => null, 'categorized_by' => $user?->getKey(), 'categorized_at' => now()]);
        });
    }

    /**
     * Run all active rules over the lines no person decided on yet (open and proposed).
     *
     * @return array{changed: int, matched: int}
     */
    public function applyRulesToOpenLines(): array
    {
        $categories = Category::query()->get()->keyBy('id');
        $rules = RuleEngine::sort(Rule::query()->where('active', true)->get());
        $hits = [];
        $matched = 0;
        $changed = 0;

        Transaction::query()->open()->lazyById(500)->each(function (Transaction $transaction) use ($categories, $rules, &$hits, &$matched, &$changed): void {
            $rule = RuleEngine::categorize(RuleEngine::lineFrom($transaction), $rules);

            if ($rule) {
                $matched++;
                $hits[$rule->getKey()] = ($hits[$rule->getKey()] ?? 0) + 1;
            }

            $fields = $rule
                ? Categorizer::fieldsFromRule($transaction->amount_cents, $rule, $categories)
                : Categorizer::fieldsFor($transaction->amount_cents, null, TransactionStatus::Uncategorized);

            $unchanged = $fields['category_id'] === $transaction->category_id
                && $fields['status'] === $transaction->status
                && $fields['rule_id'] === $transaction->rule_id
                && $fields['vat_rate'] === $transaction->vat_rate;

            if (! $unchanged) {
                $transaction->update([...$fields, 'categorized_by' => null, 'categorized_at' => now()]);
                $changed++;
            }
        });

        BankImporter::bumpHits($hits);

        return ['changed' => $changed, 'matched' => $matched];
    }

    /**
     * How many existing lines a set of conditions matches, with a few examples, for the rule editor.
     *
     * @param  list<array<string, mixed>>  $conditions
     * @return array{total: int, open: int, sample: Collection<int, Transaction>}
     */
    public function previewConditions(array $conditions): array
    {
        $matches = Transaction::query()
            ->orderByDesc('booked_on')
            ->get(['id', 'account_id', 'booked_on', 'amount_cents', 'counterparty_name', 'counterparty_iban', 'description', 'mutation_code', 'status', 'category_id'])
            ->filter(fn (Transaction $transaction): bool => RuleEngine::matchesConditions(RuleEngine::lineFrom($transaction), $conditions));

        return [
            'total' => $matches->count(),
            'open' => $matches->filter(fn (Transaction $transaction): bool => $transaction->status->isOpen())->count(),
            'sample' => $matches->take(8)->values(),
        ];
    }

    /**
     * @param  list<int>  $transactionIds
     * @param  callable(Transaction): array<string, mixed>  $fields
     */
    private function apply(array $transactionIds, callable $fields): int
    {
        $count = 0;

        Transaction::query()->whereKey($transactionIds)->get()->each(function (Transaction $transaction) use ($fields, &$count): void {
            $transaction->update([...$fields($transaction), 'categorized_at' => now()]);
            $count++;
        });

        return $count;
    }
}
