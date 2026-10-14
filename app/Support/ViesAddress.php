<?php

namespace App\Support;

/**
 * Turns the company name and address that VIES returns (one block of capitals) into tidy invoice fields.
 */
class ViesAddress
{
    /**
     * Legal forms that stay written as they are, instead of being title-cased.
     *
     * @var list<string>
     */
    private const LegalForms = [
        'B.V.', 'BV', 'N.V.', 'NV', 'V.O.F.', 'VOF', 'C.V.', 'CV', 'BVBA', 'SRL', 'SPRL', 'SA', 'SAS', 'SARL', 'SE',
        'GMBH' => 'GmbH', 'AG', 'KG', 'UG', 'OHG', 'LTD' => 'Ltd', 'LTD.' => 'Ltd.', 'LLC', 'PLC', 'S.L.', 'SL', 'S.A.', 'S.P.A.', 'SPA', 'AB', 'AS', 'APS' => 'ApS', 'OY', 'OÜ',
    ];

    /**
     * Small words that stay lowercase inside a street or city name ("Rue de la Loi", "Laan van Meerdervoort").
     *
     * @var list<string>
     */
    private const LowercaseWords = ['de', 'la', 'le', 'les', 'du', 'des', 'van', 'der', 'den', 'het', 'ten', 'ter', 'von', 'am', 'an', 'im', 'di', 'da', 'del', 'della', 'et', 'und', 'en', 'op', 'aan', 'bij'];

    /**
     * The country of a VAT number prefix (Greece uses EL, Northern Ireland XI).
     */
    public static function countryFromVatNumber(string $vatNumber): ?string
    {
        $prefix = substr($vatNumber, 0, 2);

        return match ($prefix) {
            'EL' => 'GR',
            'XI' => 'GB',
            default => preg_match('/^[A-Z]{2}$/', $prefix) ? $prefix : null,
        };
    }

    public static function companyName(string $name): string
    {
        $legalForms = collect(self::LegalForms)->mapWithKeys(fn (string $value, int|string $key): array => [is_int($key) ? $value : $key => $value]);

        return collect(preg_split('/\s+/', trim($name)))
            ->map(fn (string $word): string => $legalForms->get(mb_strtoupper($word)) ?? self::titleCase($word))
            ->implode(' ');
    }

    /**
     * Split a VIES address into street and number, an optional second address line, postal code and city.
     *
     * @return array{street: ?string, street_additional: ?string, postal_code: ?string, city: ?string}
     */
    public static function parse(string $address, string $countryCode): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($address)))));

        if ($lines === []) {
            return ['street' => null, 'street_additional' => null, 'postal_code' => null, 'city' => null];
        }

        $lastLine = array_pop($lines);
        $postalCode = null;
        $city = $lastLine;

        if (preg_match('/^((?:[A-Z]{1,2}-)?\d{4,5}(?:\s?[A-Z]{2})?|\d{3}\s?\d{2})\s+(.+)$/u', $lastLine, $match)) {
            $postalCode = $match[1];
            $city = $match[2];
        }

        if ($countryCode === 'NL' && $postalCode && preg_match('/^(\d{4})\s?([A-Z]{2})$/', $postalCode, $nl)) {
            $postalCode = "{$nl[1]} {$nl[2]}";
        }

        $streetLines = array_map(fn (string $line): string => self::street($line), $lines);

        return [
            'street' => $streetLines[0] ?? null,
            'street_additional' => count($streetLines) > 1 ? implode(', ', array_slice($streetLines, 1)) : null,
            'postal_code' => $postalCode,
            'city' => self::titleCase($city),
        ];
    }

    /**
     * Title-case a street line and drop leading zeros from house numbers ("KWIKSTAARTLAAN 00042" → "Kwikstaartlaan 42").
     */
    private static function street(string $line): string
    {
        return preg_replace_callback('/\b0+(\d)/', fn (array $match): string => $match[1], self::titleCase($line));
    }

    /**
     * Title-case words, keeping small words like "de" and "van" lowercase unless they come first.
     */
    private static function titleCase(string $text): string
    {
        $words = preg_split('/(\s+)/', mb_strtolower(trim($text)), -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($words as $index => $word) {
            if (trim($word) === '' || ($index > 0 && in_array($word, self::LowercaseWords, true))) {
                continue;
            }

            $words[$index] = mb_convert_case($word, MB_CASE_TITLE);
        }

        return implode('', $words);
    }
}
