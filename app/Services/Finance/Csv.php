<?php

namespace App\Services\Finance;

use InvalidArgumentException;

/**
 * Reads CSV exports into header-keyed records, guessing the delimiter (";", "," or tab).
 */
class Csv
{
    /**
     * @return array{headers: list<string>, records: list<array<string, string>>}
     */
    public static function read(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $delimiter = static::guessDelimiter($text);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $headers = null;
        $records = [];

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($row === [null] || trim(implode('', $row)) === '') {
                continue;
            }

            $row = array_map(fn (?string $value): string => trim((string) $value), $row);

            if ($headers === null) {
                $headers = $row;

                continue;
            }

            $record = [];

            foreach ($headers as $index => $header) {
                $record[$header] = $row[$index] ?? '';
            }

            $records[] = $record;
        }

        fclose($stream);

        return ['headers' => $headers ?? [], 'records' => $records];
    }

    public static function norm(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $required
     */
    public static function hasHeaders(array $headers, array $required): bool
    {
        $present = array_map(static::norm(...), $headers);

        foreach ($required as $header) {
            if (! in_array(static::norm($header), $present, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Case-insensitive lookup of the first of the given columns that is present.
     *
     * @param  array<string, string>  $record
     */
    public static function column(array $record, string ...$names): string
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $record)) {
                return $record[$name];
            }

            foreach ($record as $key => $value) {
                if (static::norm($key) === static::norm($name)) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Convert the supported date notations to ISO YYYY-MM-DD.
     */
    public static function toIsoDate(string $value, ?string $format = null): string
    {
        $date = preg_split('/[ T]/', trim($value))[0];

        if (($format === 'YYYYMMDD' || ! $format) && preg_match('/^\d{8}$/', $date)) {
            [$year, $month, $day] = [substr($date, 0, 4), substr($date, 4, 2), substr($date, 6, 2)];
        } elseif (($format === 'YYYY-MM-DD' || ! $format) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            [$year, $month, $day] = explode('-', $date);
        } elseif (($format === 'DD-MM-YYYY' || ! $format) && preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $date)) {
            [$day, $month, $year] = explode('-', $date);
        } elseif ($format === 'MM/DD/YYYY' && preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date)) {
            [$month, $day, $year] = explode('/', $date);
        } elseif (($format === 'DD/MM/YYYY' || ! $format) && preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date)) {
            [$day, $month, $year] = explode('/', $date);
        } else {
            throw new InvalidArgumentException("Onbekende datum: \"{$value}\"");
        }

        if ((int) $year < 2000 || ! checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidArgumentException("Ongeldige datum: \"{$value}\"");
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function guessDelimiter(string $text): string
    {
        $firstLine = strtok($text, "\n") ?: '';
        $counts = [];

        foreach ([';', ',', "\t"] as $delimiter) {
            $counts[$delimiter] = count(str_getcsv($firstLine, $delimiter, '"', ''));
        }

        arsort($counts);

        return array_key_first($counts);
    }
}
