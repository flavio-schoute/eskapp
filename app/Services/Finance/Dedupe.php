<?php

namespace App\Services\Finance;

/**
 * A stable fingerprint per bank line, so re-uploading an overlapping export adds nothing. Identical lines inside one
 * file (same day, amount, payee and text) get an occurrence number, which stays the same when the same file, or an
 * export covering the same days, is uploaded again.
 */
class Dedupe
{
    /**
     * @param  list<ParsedTransaction>  $rows
     * @return list<string>
     */
    public static function hashes(int|string $accountId, array $rows): array
    {
        $seen = [];

        return array_map(function (ParsedTransaction $row) use ($accountId, &$seen): string {
            $base = implode('|', [
                $accountId,
                $row->bookedOn,
                $row->amountCents,
                strtoupper(preg_replace('/\s/', '', (string) $row->counterpartyIban)),
                preg_replace('/\s+/', ' ', mb_strtolower($row->counterpartyName)),
                preg_replace('/\s+/', ' ', mb_strtolower($row->description)),
                $row->balanceAfterCents ?? '',
            ]);

            $occurrence = $seen[$base] ?? 0;
            $seen[$base] = $occurrence + 1;

            return hash('sha256', "{$base}|{$occurrence}");
        }, $rows);
    }
}
