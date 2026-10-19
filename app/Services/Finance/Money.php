<?php

namespace App\Services\Finance;

use InvalidArgumentException;

/**
 * Money helpers. All amounts are integer cents; negative is money out.
 */
class Money
{
    /**
     * Parse a bank export amount into cents, in Dutch ("1.234,56", "-12,50", "+3,00") or English ("1,234.56") notation.
     */
    public static function parseToCents(string|int|float|null $input): int
    {
        if ($input === null) {
            throw new InvalidArgumentException('Leeg bedrag');
        }

        if (is_int($input) || is_float($input)) {
            return static::round($input * 100);
        }

        $value = preg_replace('/\s|€|EUR/i', '', trim($input));

        if ($value === '') {
            throw new InvalidArgumentException('Leeg bedrag');
        }

        $sign = 1;

        if (str_starts_with($value, '-')) {
            $sign = -1;
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        $normalized = match (true) {
            // Whichever separator comes last is the decimal separator.
            $lastComma !== false && $lastDot !== false => $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value),
            $lastComma !== false => static::replaceFirst(',', '.', $value),
            // 1.234.567: only thousands separators.
            $lastDot !== false && preg_match('/^\d{1,3}(\.\d{3}){2,}$/', $value) === 1 => str_replace('.', '', $value),
            default => $value,
        };

        if (preg_match('/^\d+(\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Ongeldig bedrag: \"{$input}\"");
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized), 2, '');
        $cents = (int) $whole * 100 + (int) substr($fraction.'00', 0, 2) + (strlen($fraction) > 2 && (int) $fraction[2] >= 5 ? 1 : 0);

        return $sign * $cents;
    }

    /**
     * The amount excluding VAT (same sign as the input), rounded to the cent like the sheet's ROUND(x/1.21, 2).
     */
    public static function exVatCents(int $amountCents, ?float $vatRate): int
    {
        $rate = $vatRate ?? 0.0;

        if ($rate < 0 || $rate > 1) {
            throw new InvalidArgumentException("Ongeldig btw-tarief: {$rate}");
        }

        return static::round($amountCents / (1 + $rate));
    }

    /**
     * "€ 1.234,56", or "€ 1.235" without decimals.
     */
    public static function format(int $cents, bool $decimals = true): string
    {
        $formatted = number_format(abs($cents) / 100, $decimals ? 2 : 0, ',', '.');

        return ($cents < 0 ? '€ -' : '€ ').$formatted;
    }

    /**
     * Short amounts for chart axes: €1,2M, €45K, €950.
     */
    public static function compact(int $cents): string
    {
        $euro = $cents / 100;

        return match (true) {
            abs($euro) >= 1_000_000 => '€'.str_replace('.', ',', number_format($euro / 1_000_000, 1)).'M',
            abs($euro) >= 1_000 => '€'.round($euro / 1_000).'K',
            default => '€'.round($euro),
        };
    }

    /**
     * Round half up, like JavaScript's Math.round, so results match the original app exactly.
     */
    public static function round(float $value): int
    {
        return (int) floor($value + 0.5);
    }

    private static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $position = strpos($subject, $search);

        return $position === false ? $subject : substr_replace($subject, $replace, $position, strlen($search));
    }
}
