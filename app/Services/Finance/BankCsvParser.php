<?php

namespace App\Services\Finance;

use InvalidArgumentException;
use Throwable;

/**
 * Reads bank exports: ING (Dutch and English), Rabobank, American Express, or any other CSV with a saved column mapping.
 */
class BankCsvParser
{
    private const IngNl = ['Datum', 'Naam / Omschrijving', 'Rekening', 'Tegenrekening', 'Code', 'Af Bij', 'Bedrag (EUR)', 'Mutatiesoort', 'Mededelingen'];

    private const IngEn = ['Date', 'Name / Description', 'Account', 'Counterparty', 'Code', 'Debit/credit', 'Amount (EUR)', 'Transaction type', 'Notifications'];

    private const Rabobank = ['IBAN/BBAN', 'Munt', 'Datum', 'Bedrag', 'Naam tegenpartij', 'Omschrijving-1'];

    private const Amex = ['Datum', 'Omschrijving', 'Bedrag'];

    /**
     * The supported formats and their labels.
     *
     * @var array<string, string>
     */
    public const Formats = ['ing-nl' => 'ING (NL)', 'ing-en' => 'ING (EN)', 'rabobank' => 'Rabobank', 'amex' => 'American Express', 'generic' => 'Eigen kolomindeling'];

    /**
     * @param  list<string>  $headers
     */
    public static function detectFormat(array $headers): string
    {
        return match (true) {
            Csv::hasHeaders($headers, self::IngNl) => 'ing-nl',
            Csv::hasHeaders($headers, self::IngEn) => 'ing-en',
            Csv::hasHeaders($headers, self::Rabobank) => 'rabobank',
            // Exact: other exports share these column names.
            count($headers) === count(self::Amex) && Csv::hasHeaders($headers, self::Amex) => 'amex',
            default => 'generic',
        };
    }

    /**
     * @param  array{date: string, dateFormat: string, amount: string, direction?: array{column: string, outValues: list<string>}, counterparty: string, iban?: string, description?: string}|null  $mapping
     * @return array{format: string, headers: list<string>, rows: list<ParsedTransaction>, errors: list<array{line: int, message: string}>}
     */
    public static function parse(string $text, ?array $mapping = null): array
    {
        ['headers' => $headers, 'records' => $records] = Csv::read($text);
        $format = static::detectFormat($headers);

        if ($format === 'generic' && ! $mapping) {
            return ['format' => $format, 'headers' => $headers, 'rows' => [], 'errors' => [['line' => 0, 'message' => 'Onbekend bankformaat — kies eerst een kolomindeling.']]];
        }

        $rows = [];
        $errors = [];

        foreach ($records as $index => $record) {
            try {
                $rows[] = match ($format) {
                    'ing-nl' => static::parseIng($record, english: false),
                    'ing-en' => static::parseIng($record, english: true),
                    'rabobank' => static::parseRabobank($record),
                    'amex' => static::parseAmex($record),
                    default => static::parseGeneric($record, $mapping),
                };
            } catch (Throwable $exception) {
                $errors[] = ['line' => $index + 2, 'message' => $exception->getMessage()];
            }
        }

        return ['format' => $format, 'headers' => $headers, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * ING puts the real payment reference inside "Mededelingen", e.g.
     * "Naam: X Omschrijving: Y IBAN: NL.. Kenmerk: K Valutadatum: 28-06-2024". Returns the Omschrijving part, or the
     * full text when there is none.
     */
    public static function extractIngDescription(string $text): string
    {
        preg_match('/(?:Omschrijving|Description):\s*(.*?)(?=\s+(?:IBAN|Kenmerk|Reference|Valutadatum|Value date|Machtiging ID|Mandate ID|Incassant ID|Creditor ID|Doorlopende incasso|Recurrent SEPA)[:\s]|$)/i', $text, $match);

        return trim($match[1] ?? $text);
    }

    /**
     * @param  array<string, string>  $record
     */
    private static function parseIng(array $record, bool $english): ParsedTransaction
    {
        $field = $english
            ? ['date' => 'Date', 'name' => 'Name / Description', 'account' => 'Account', 'counterparty' => 'Counterparty', 'direction' => 'Debit/credit', 'amount' => 'Amount (EUR)', 'type' => 'Transaction type', 'message' => 'Notifications', 'balance' => 'Resulting balance']
            : ['date' => 'Datum', 'name' => 'Naam / Omschrijving', 'account' => 'Rekening', 'counterparty' => 'Tegenrekening', 'direction' => 'Af Bij', 'amount' => 'Bedrag (EUR)', 'type' => 'Mutatiesoort', 'message' => 'Mededelingen', 'balance' => 'Saldo na mutatie'];

        $direction = Csv::norm(Csv::column($record, $field['direction']));
        $out = in_array($direction, ['af', 'debit'], true);

        if (! $out && ! in_array($direction, ['bij', 'credit'], true)) {
            throw new InvalidArgumentException('Onbekende af/bij-waarde: "'.Csv::column($record, $field['direction']).'"');
        }

        $amount = abs(Money::parseToCents(Csv::column($record, $field['amount'])));
        $balance = Csv::column($record, $field['balance']);

        return new ParsedTransaction(
            bookedOn: Csv::toIsoDate(Csv::column($record, $field['date'])),
            amountCents: $out ? -$amount : $amount,
            currency: 'EUR',
            counterpartyName: Csv::column($record, $field['name']),
            counterpartyIban: static::nullIfBlank(Csv::column($record, $field['counterparty'])),
            description: static::extractIngDescription(Csv::column($record, $field['message'])),
            mutationCode: static::nullIfBlank(Csv::column($record, 'Code')),
            mutationType: static::nullIfBlank(Csv::column($record, $field['type'])),
            ownIban: static::nullIfBlank(Csv::column($record, $field['account'])),
            balanceAfterCents: $balance !== '' ? Money::parseToCents($balance) : null,
            raw: $record,
        );
    }

    /**
     * @param  array<string, string>  $record
     */
    private static function parseRabobank(array $record): ParsedTransaction
    {
        $balance = Csv::column($record, 'Saldo na trn');
        $description = collect(['Omschrijving-1', 'Omschrijving-2', 'Omschrijving-3'])
            ->map(fn (string $column): string => Csv::column($record, $column))
            ->filter()
            ->implode(' ');

        return new ParsedTransaction(
            bookedOn: Csv::toIsoDate(Csv::column($record, 'Datum')),
            amountCents: Money::parseToCents(Csv::column($record, 'Bedrag')),
            currency: Csv::column($record, 'Munt') ?: 'EUR',
            counterpartyName: Csv::column($record, 'Naam tegenpartij') ?: Csv::column($record, 'Naam uiteindelijke partij'),
            counterpartyIban: static::nullIfBlank(Csv::column($record, 'Tegenrekening IBAN/BBAN')),
            description: $description,
            mutationCode: static::nullIfBlank(Csv::column($record, 'Code')),
            mutationType: null,
            ownIban: static::nullIfBlank(Csv::column($record, 'IBAN/BBAN')),
            balanceAfterCents: $balance !== '' ? Money::parseToCents($balance) : null,
            raw: $record,
        );
    }

    /**
     * American Express (NL) activity export: "Datum,Omschrijving,Bedrag", dates as MM/DD/YYYY, and amounts from the
     * card's point of view (a purchase is positive, a repayment negative). The description pads the merchant to a
     * fixed width before the city: "FACEBK *3SE3W56B74      DUBLIN".
     *
     * @param  array<string, string>  $record
     */
    private static function parseAmex(array $record): ParsedTransaction
    {
        $text = Csv::column($record, 'Omschrijving');

        return new ParsedTransaction(
            bookedOn: Csv::toIsoDate(Csv::column($record, 'Datum'), 'MM/DD/YYYY'),
            amountCents: -Money::parseToCents(Csv::column($record, 'Bedrag')),
            currency: 'EUR',
            counterpartyName: preg_split('/\s{2,}/', $text)[0],
            counterpartyIban: null,
            description: preg_replace('/\s+/', ' ', $text),
            mutationCode: null,
            mutationType: null,
            ownIban: null,
            balanceAfterCents: null,
            raw: $record,
        );
    }

    /**
     * @param  array<string, string>  $record
     * @param  array<string, mixed>  $mapping
     */
    private static function parseGeneric(array $record, array $mapping): ParsedTransaction
    {
        $amount = Money::parseToCents(Csv::column($record, $mapping['amount']));

        if (! empty($mapping['direction']['column'])) {
            $value = Csv::norm(Csv::column($record, $mapping['direction']['column']));
            $outValues = array_map(Csv::norm(...), $mapping['direction']['outValues'] ?? []);
            $amount = in_array($value, $outValues, true) ? -abs($amount) : abs($amount);
        }

        return new ParsedTransaction(
            bookedOn: Csv::toIsoDate(Csv::column($record, $mapping['date']), $mapping['dateFormat'] ?? null),
            amountCents: $amount,
            currency: 'EUR',
            counterpartyName: Csv::column($record, $mapping['counterparty']),
            counterpartyIban: ! empty($mapping['iban']) ? static::nullIfBlank(Csv::column($record, $mapping['iban'])) : null,
            description: ! empty($mapping['description']) ? Csv::column($record, $mapping['description']) : '',
            mutationCode: null,
            mutationType: null,
            ownIban: null,
            balanceAfterCents: null,
            raw: $record,
        );
    }

    private static function nullIfBlank(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
