<?php

namespace App\Services\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use Illuminate\Support\Collection;

/**
 * The rule engine: the same code runs on import, on "re-run rules" and in the rule editor's preview.
 *
 * A line to match is an array: {accountId, amountCents, counterpartyName, counterpartyIban, description, mutationCode,
 * status?}.
 */
class RuleEngine
{
    /**
     * @var array<string, string>
     */
    public const FieldLabels = [
        'counterparty' => 'Tegenpartij',
        'iban' => 'IBAN',
        'description' => 'Omschrijving',
        'mutation_code' => 'Code',
        'account_id' => 'Rekening',
        'direction' => 'Richting',
        'amount' => 'Bedrag',
    ];

    /**
     * @var array<string, string>
     */
    public const OperatorLabels = [
        'equals' => 'is',
        'contains' => 'bevat',
        'starts_with' => 'begint met',
        'regex' => 'matcht',
        'gte' => '≥',
        'lte' => '≤',
        'between' => 'tussen',
    ];

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $condition
     */
    public static function matchesCondition(array $line, array $condition): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['op'] ?? null;
        $value = $condition['value'] ?? null;

        if ($field === 'direction') {
            return $value === ($line['amountCents'] < 0 ? 'out' : 'in');
        }

        if ($field === 'amount') {
            $absolute = abs($line['amountCents']);

            return match ($operator) {
                'between' => $absolute >= (int) $value && $absolute <= (int) ($condition['value2'] ?? 0),
                'gte' => $absolute >= (int) $value,
                default => $absolute <= (int) $value,
            };
        }

        $actual = match ($field) {
            'counterparty' => static::normText($line['counterpartyName'] ?? ''),
            'iban' => static::normIban($line['counterpartyIban'] ?? ''),
            'description' => static::normText($line['description'] ?? ''),
            'mutation_code' => static::normText($line['mutationCode'] ?? ''),
            'account_id' => (string) ($line['accountId'] ?? ''),
            default => '',
        };

        if ($operator === 'regex') {
            return static::safeRegexMatch((string) $value, $actual);
        }

        $expected = match ($field) {
            'iban' => static::normIban((string) $value),
            'account_id' => (string) $value,
            default => static::normText((string) $value),
        };

        if ($expected === '') {
            return false;
        }

        return match ($operator) {
            'equals' => $actual === $expected,
            'contains' => str_contains($actual, $expected),
            default => str_starts_with($actual, $expected),
        };
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<array<string, mixed>>  $conditions
     */
    public static function matchesConditions(array $line, array $conditions): bool
    {
        if ($conditions === []) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! static::matchesCondition($line, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first matching active rule by priority, or null. Lines a person confirmed or excluded are left alone.
     *
     * @param  array<string, mixed>  $line
     * @param  iterable<Rule>  $rules
     */
    public static function categorize(array $line, iterable $rules): ?Rule
    {
        $status = $line['status'] ?? null;

        if (in_array($status, [TransactionStatus::Confirmed, TransactionStatus::Excluded, 'confirmed', 'excluded'], true)) {
            return null;
        }

        foreach (static::sort($rules) as $rule) {
            if ($rule->active && static::matchesConditions($line, $rule->conditions ?? [])) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  iterable<Rule>  $rules
     * @return Collection<int, Rule>
     */
    public static function sort(iterable $rules): Collection
    {
        return collect($rules)->sort(fn (Rule $a, Rule $b): int => [$a->priority, $a->name] <=> [$b->priority, $b->name])->values();
    }

    /**
     * A readable condition, e.g. 'Tegenpartij bevat "mollie"' or 'Richting is inkomend'.
     *
     * @param  array<string, mixed>  $condition
     * @param  array<int|string, string>  $accountNames
     */
    public static function describeCondition(array $condition, array $accountNames = []): string
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['op'] ?? '';

        if ($field === 'direction') {
            return 'Richting is '.(($condition['value'] ?? '') === 'out' ? 'uitgaand' : 'inkomend');
        }

        if ($field === 'amount') {
            $euro = fn (mixed $cents): string => '€'.number_format(((int) $cents) / 100, 2, ',', '.');

            return $operator === 'between'
                ? 'Bedrag tussen '.$euro($condition['value'] ?? 0).' en '.$euro($condition['value2'] ?? 0)
                : 'Bedrag '.(self::OperatorLabels[$operator] ?? $operator).' '.$euro($condition['value'] ?? 0);
        }

        $value = $field === 'account_id' ? ($accountNames[$condition['value'] ?? ''] ?? $condition['value'] ?? '') : ($condition['value'] ?? '');

        return (self::FieldLabels[$field] ?? $field).' '.(self::OperatorLabels[$operator] ?? $operator).' "'.$value.'"';
    }

    /**
     * The matchable shape of a stored transaction.
     *
     * @return array<string, mixed>
     */
    public static function lineFrom(Transaction $transaction): array
    {
        return [
            'accountId' => $transaction->account_id,
            'amountCents' => $transaction->amount_cents,
            'counterpartyName' => $transaction->counterparty_name,
            'counterpartyIban' => $transaction->counterparty_iban,
            'description' => $transaction->description,
            'mutationCode' => $transaction->mutation_code,
            'status' => $transaction->status,
        ];
    }

    private static function normText(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $value)));
    }

    private static function normIban(?string $value): string
    {
        return strtoupper(preg_replace('/\s/', '', (string) $value));
    }

    /**
     * An invalid pattern counts as no match instead of failing.
     */
    private static function safeRegexMatch(string $pattern, string $subject): bool
    {
        $delimited = '~'.str_replace('~', '\~', $pattern).'~iu';

        return @preg_match($delimited, $subject) === 1;
    }
}
