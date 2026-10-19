<?php

namespace App\Services\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Import;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports a bank export into an account: lines already imported are skipped, new lines are categorized by the rules.
 */
class BankImporter
{
    /**
     * What an import would do, without saving anything.
     *
     * @param  array<string, mixed>|null  $mapping
     * @return array{format: string, headers: list<string>, total: int, new: int, duplicates: int, errors: list<array{line: int, message: string}>, date_from: ?string, date_to: ?string, sum_in: int, sum_out: int, own_ibans: list<string>, sample: list<ParsedTransaction>, needs_mapping: bool}
     */
    public function preview(Account $account, string $text, ?array $mapping = null): array
    {
        $parsed = BankCsvParser::parse($text, $mapping ?? $account->csv_mapping);
        $fresh = $this->freshRows($account, $parsed['rows']);
        $dates = collect($parsed['rows'])->pluck('bookedOn')->sort()->values();

        return [
            'format' => $parsed['format'],
            'headers' => $parsed['headers'],
            'total' => count($parsed['rows']),
            'new' => count($fresh),
            'duplicates' => count($parsed['rows']) - count($fresh),
            'errors' => array_slice($parsed['errors'], 0, 20),
            'date_from' => $dates->first(),
            'date_to' => $dates->last(),
            'sum_in' => collect($fresh)->pluck('row')->filter(fn (ParsedTransaction $row): bool => $row->amountCents > 0)->sum('amountCents'),
            'sum_out' => collect($fresh)->pluck('row')->filter(fn (ParsedTransaction $row): bool => $row->amountCents < 0)->sum('amountCents'),
            'own_ibans' => collect($parsed['rows'])->pluck('ownIban')->filter()->unique()->values()->all(),
            'sample' => collect($fresh)->pluck('row')->take(5)->all(),
            'needs_mapping' => $parsed['format'] === 'generic' && $parsed['rows'] === [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $mapping  Saved on the account for its next imports.
     * @return array{import: Import, new: int, duplicates: int, auto: int, excluded: int, errors: int}
     */
    public function commit(Account $account, string $filename, string $text, ?array $mapping = null, ?User $uploader = null): array
    {
        if ($mapping) {
            $account->update(['csv_mapping' => $mapping]);
        }

        $parsed = BankCsvParser::parse($text, $mapping ?? $account->csv_mapping);

        if ($parsed['rows'] === []) {
            throw new RuntimeException($parsed['errors'][0]['message'] ?? 'Geen transacties gevonden in dit bestand.');
        }

        $fresh = $this->freshRows($account, $parsed['rows']);
        $categories = Category::query()->get()->keyBy('id');
        $rules = RuleEngine::sort(Rule::query()->where('active', true)->get());
        $dates = collect($parsed['rows'])->pluck('bookedOn')->sort()->values();

        $result = DB::transaction(function () use ($account, $filename, $text, $parsed, $fresh, $categories, $rules, $dates, $uploader): array {
            $import = Import::create([
                'account_id' => $account->getKey(),
                'kind' => 'bank',
                'filename' => $filename,
                'file_hash' => hash('sha256', $text),
                'format' => $parsed['format'],
                'rows_total' => count($parsed['rows']),
                'rows_new' => count($fresh),
                'rows_duplicate' => count($parsed['rows']) - count($fresh),
                'date_from' => $dates->first(),
                'date_to' => $dates->last(),
                'uploaded_by' => $uploader?->getKey(),
            ]);

            $hits = [];
            $auto = 0;
            $excluded = 0;
            $now = now();
            $records = [];

            foreach ($fresh as ['row' => $row, 'hash' => $hash]) {
                $line = [
                    'accountId' => $account->getKey(),
                    'amountCents' => $row->amountCents,
                    'counterpartyName' => $row->counterpartyName,
                    'counterpartyIban' => $row->counterpartyIban,
                    'description' => $row->description,
                    'mutationCode' => $row->mutationCode,
                ];

                $rule = RuleEngine::categorize($line, $rules);
                $fields = $rule ? Categorizer::fieldsFromRule($row->amountCents, $rule, $categories) : ['status' => TransactionStatus::Uncategorized];

                if ($rule) {
                    $hits[$rule->getKey()] = ($hits[$rule->getKey()] ?? 0) + 1;
                }

                $auto += (int) ($fields['status'] === TransactionStatus::Auto);
                $excluded += (int) ($fields['status'] === TransactionStatus::Excluded);

                $records[] = [
                    'account_id' => $account->getKey(),
                    'import_id' => $import->getKey(),
                    'dedupe_hash' => $hash,
                    'booked_on' => $row->bookedOn,
                    'amount_cents' => $row->amountCents,
                    'currency' => $row->currency,
                    'counterparty_name' => mb_substr($row->counterpartyName, 0, 500),
                    'counterparty_iban' => $row->counterpartyIban,
                    'description' => $row->description,
                    'mutation_code' => $row->mutationCode,
                    'mutation_type' => $row->mutationType,
                    'balance_after_cents' => $row->balanceAfterCents,
                    'raw' => json_encode($row->raw),
                    'category_id' => $fields['category_id'] ?? null,
                    'vat_rate' => $fields['vat_rate'] ?? null,
                    'amount_ex_vat_cents' => $fields['amount_ex_vat_cents'] ?? null,
                    'status' => $fields['status']->value,
                    'rule_id' => $fields['rule_id'] ?? null,
                    'note' => $fields['note'] ?? null,
                    'categorized_at' => $rule ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($records, 500) as $chunk) {
                Transaction::query()->insertOrIgnore($chunk);
            }

            static::bumpHits($hits);

            $import->update(['rows_auto' => $auto]);
            $account->update(['bank_format' => $parsed['format']]);

            return ['import' => $import, 'new' => count($fresh), 'duplicates' => count($parsed['rows']) - count($fresh), 'auto' => $auto, 'excluded' => $excluded, 'errors' => count($parsed['errors'])];
        });

        // Customers paying by bank transfer: link the new lines to their Plug&Pay orders.
        if ($dates->isNotEmpty()) {
            app(PaymentMatcher::class)->matchIncoming(Carbon::parse($dates->first()));
        }

        return $result;
    }

    /**
     * Remove an import and all its transactions, also the ones that were categorized since.
     */
    public function undo(Import $import): void
    {
        $import->delete();
    }

    /**
     * Add the matches to the rules' hit counts (informational).
     *
     * @param  array<int, int>  $hits
     */
    public static function bumpHits(array $hits): void
    {
        foreach ($hits as $ruleId => $count) {
            Rule::query()->whereKey($ruleId)->increment('hit_count', $count);
        }
    }

    /**
     * The rows that are not imported yet, with their dedupe hash.
     *
     * @param  list<ParsedTransaction>  $rows
     * @return list<array{row: ParsedTransaction, hash: string}>
     */
    private function freshRows(Account $account, array $rows): array
    {
        $hashes = Dedupe::hashes($account->getKey(), $rows);
        $existing = [];

        foreach (array_chunk($hashes, 500) as $chunk) {
            array_push($existing, ...Transaction::query()->whereIn('dedupe_hash', $chunk)->pluck('dedupe_hash')->all());
        }

        $existing = array_flip($existing);
        $fresh = [];

        foreach ($rows as $index => $row) {
            if (! isset($existing[$hashes[$index]])) {
                $fresh[] = ['row' => $row, 'hash' => $hashes[$index]];
            }
        }

        return $fresh;
    }
}
