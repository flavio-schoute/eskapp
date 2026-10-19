<?php

namespace App\Services\Finance;

/**
 * Suggestions for lines no rule matched, from history: "this payee was category X in at least 90% of past lines".
 */
class Suggester
{
    /**
     * @var array<string, array<int|string, int>>
     */
    private array $byIban = [];

    /**
     * @var array<string, array<int|string, int>>
     */
    private array $byName = [];

    /**
     * @param  iterable<array{counterpartyName: string, counterpartyIban: ?string, categoryId: int|string}>  $history
     */
    public function __construct(iterable $history, private float $minShare = 0.9, private int $minRows = 2)
    {
        foreach ($history as $row) {
            $this->add($this->byIban, static::ibanKey($row['counterpartyIban']), $row['categoryId']);
            $this->add($this->byName, static::nameKey($row['counterpartyName']), $row['categoryId']);
        }
    }

    /**
     * @return array{categoryId: int|string, basis: string, support: int, total: int}|null
     */
    public function suggest(string $counterpartyName, ?string $counterpartyIban): ?array
    {
        $candidates = [
            'iban' => $this->best($this->byIban[static::ibanKey($counterpartyIban)] ?? null),
            'counterparty' => $this->best($this->byName[static::nameKey($counterpartyName)] ?? null),
        ];

        foreach ($candidates as $basis => $best) {
            if ($best && $best['total'] >= $this->minRows && $this->minShare <= $best['support'] / $best['total']) {
                return [...$best, 'basis' => $basis];
            }
        }

        return null;
    }

    public static function nameKey(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)));
    }

    public static function ibanKey(?string $iban): string
    {
        return strtoupper(preg_replace('/\s/', '', (string) $iban));
    }

    /**
     * @param  array<string, array<int|string, int>>  $tally
     */
    private function add(array &$tally, string $key, int|string $categoryId): void
    {
        if ($key === '') {
            return;
        }

        $tally[$key][$categoryId] = ($tally[$key][$categoryId] ?? 0) + 1;
    }

    /**
     * @param  array<int|string, int>|null  $counts
     * @return array{categoryId: int|string, support: int, total: int}|null
     */
    private function best(?array $counts): ?array
    {
        if (! $counts) {
            return null;
        }

        arsort($counts);

        return ['categoryId' => array_key_first($counts), 'support' => reset($counts), 'total' => array_sum($counts)];
    }
}
