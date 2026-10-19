<?php

namespace App\Services\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Category;
use App\Models\Finance\Rule;
use Illuminate\Support\Collection;

/**
 * The fields to store when a line gets (or loses) a category. VAT falls back to the category's default; transfers,
 * tax and financing never carry VAT.
 */
class Categorizer
{
    /**
     * @return array{category_id: ?int, vat_rate: ?float, amount_ex_vat_cents: ?int, status: TransactionStatus, rule_id: ?int}
     */
    public static function fieldsFor(int $amountCents, ?Category $category, TransactionStatus $status, ?float $vatRate = null, ?int $ruleId = null): array
    {
        if (! $category || $status === TransactionStatus::Excluded) {
            return ['category_id' => $category?->getKey(), 'vat_rate' => null, 'amount_ex_vat_cents' => null, 'status' => $status, 'rule_id' => $ruleId];
        }

        $vat = $category->kind->countsInProfitAndLoss() ? ($vatRate ?? $category->default_vat_rate ?? 0.0) : 0.0;

        return [
            'category_id' => $category->getKey(),
            'vat_rate' => $vat,
            'amount_ex_vat_cents' => Money::exVatCents($amountCents, $vat),
            'status' => $status,
            'rule_id' => $ruleId,
        ];
    }

    /**
     * The fields a rule's action gives a line, plus the note when the rule sets one.
     *
     * @param  array<int, Category>|Collection<int, Category>  $categories  Keyed by ID.
     * @return array<string, mixed>
     */
    public static function fieldsFromRule(int $amountCents, Rule $rule, iterable $categories): array
    {
        $action = $rule->action ?? [];
        $categoryId = $action['categoryId'] ?? null;
        $category = $categoryId ? (collect($categories)->get($categoryId)) : null;

        $status = match (true) {
            ($action['status'] ?? null) === 'excluded' => TransactionStatus::Excluded,
            $category !== null => TransactionStatus::Auto,
            default => TransactionStatus::Uncategorized,
        };

        $fields = static::fieldsFor($amountCents, $category, $status, isset($action['vatRate']) ? (float) $action['vatRate'] : null, $rule->getKey());

        return filled($action['note'] ?? null) ? [...$fields, 'note' => $action['note']] : $fields;
    }
}
