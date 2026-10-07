<?php

namespace App\Support;

use ResourceBundle;

/**
 * Country-aware labels and formatting for invoice addresses.
 *
 * Every country uses the same fields; only the labels, placeholders and the
 * order in which the parts are printed differ.
 */
class InvoiceAddressFormat
{
    /**
     * Region codes in ICU data that are not countries.
     *
     * @var list<string>
     */
    private const NonCountryCodes = ['AC', 'CP', 'DG', 'EA', 'EU', 'EZ', 'IC', 'QO', 'TA', 'UN', 'XA', 'XB', 'ZZ'];

    /**
     * Get all countries as ISO 3166-1 alpha-2 code => English name, sorted by name.
     *
     * @return array<string, string>
     */
    public static function countryOptions(): array
    {
        static $countries = null;

        if ($countries !== null) {
            return $countries;
        }

        $countries = [];

        foreach (ResourceBundle::create('en', 'ICUDATA-region')['Countries'] as $code => $name) {
            if (preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, self::NonCountryCodes, true)) {
                $countries[$code] = $name;
            }
        }

        asort($countries);

        return $countries;
    }

    public static function countryName(?string $countryCode): ?string
    {
        return self::countryOptions()[$countryCode] ?? $countryCode;
    }

    public static function regionLabel(?string $countryCode): string
    {
        return match ($countryCode) {
            'CN' => 'District / province',
            'US', 'AU' => 'State',
            'CA' => 'Province',
            'GB', 'IE' => 'County',
            default => 'Region / province',
        };
    }

    public static function postalCodeLabel(?string $countryCode): string
    {
        return match ($countryCode) {
            'US' => 'ZIP code',
            'GB' => 'Postcode',
            default => 'Postal code',
        };
    }

    public static function postalCodePlaceholder(?string $countryCode): ?string
    {
        return match ($countryCode) {
            'NL' => '1234 AB',
            'BE', 'AT', 'CH', 'DK' => '1000',
            'DE', 'FR', 'ES', 'IT' => '10115',
            'CN' => '315201',
            'US' => '10001',
            'GB' => 'SW1A 1AA',
            default => null,
        };
    }

    public static function addressPlaceholder(?string $countryCode): string
    {
        return match ($countryCode) {
            'CN' => 'Rm808 Block A Zhongguanxilu 1277#',
            'US', 'GB', 'CA', 'AU' => '123 Main Street',
            default => 'Street and house number',
        };
    }

    /**
     * Format the address parts into the lines printed on an invoice for the given country.
     *
     * @param  array{company_name?: ?string, address_line_1?: ?string, address_line_2?: ?string, postal_code?: ?string, city?: ?string, region?: ?string, country?: ?string}  $address
     * @return list<string>
     */
    public static function lines(array $address): array
    {
        $part = fn (string $key): string => trim((string) ($address[$key] ?? ''));
        $join = fn (string $glue, string ...$parts): string => implode($glue, array_filter($parts, fn (string $value): bool => $value !== ''));

        $countryCode = $address['country'] ?? null;
        $country = $countryCode ? (string) self::countryName($countryCode) : '';

        $lines = match ($countryCode) {
            'CN' => [
                $join(', ', $join(' ', $part('address_line_1'), $part('address_line_2'), $part('region'), $part('city')), $part('postal_code'), $country),
            ],
            'US', 'CA', 'AU' => [
                $part('address_line_1'),
                $part('address_line_2'),
                $join(' ', $join(', ', $part('city'), $part('region')), $part('postal_code')),
                $country,
            ],
            'GB', 'IE' => [
                $part('address_line_1'),
                $part('address_line_2'),
                $part('city'),
                $part('region'),
                $part('postal_code'),
                $country,
            ],
            default => [
                $part('address_line_1'),
                $part('address_line_2'),
                $join(' ', $part('postal_code'), $part('city')),
                $part('region'),
                $country,
            ],
        };

        return array_values(array_filter([$part('company_name'), ...$lines], fn (string $line): bool => $line !== ''));
    }
}
