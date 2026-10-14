<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks EU VAT numbers with the European Commission's VIES service.
 */
class ViesVatValidator
{
    public const Valid = 'valid';

    public const Invalid = 'invalid';

    public const Unavailable = 'unavailable';

    public const NotEu = 'not_eu';

    private const ApiUrl = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms';

    /**
     * VAT number prefixes that VIES knows: the EU member states (Greece uses EL) and Northern Ireland (XI).
     *
     * @var list<string>
     */
    private const Prefixes = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE',
        'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    public function isEnabled(): bool
    {
        return (bool) config('services.vies.enabled');
    }

    /**
     * Write a VAT number the way VIES and Mollie expect it: capitals, without spaces, dots or dashes.
     */
    public static function normalize(string $vatNumber): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]+/', '', $vatNumber));
    }

    public static function isEuVatNumber(string $vatNumber): bool
    {
        return in_array(substr(self::normalize($vatNumber), 0, 2), self::Prefixes, true);
    }

    /**
     * @return array{status: string, name: ?string, address: ?string}
     */
    public function check(string $vatNumber): array
    {
        $vatNumber = self::normalize($vatNumber);

        if (! self::isEuVatNumber($vatNumber)) {
            return ['status' => self::NotEu, 'name' => null, 'address' => null];
        }

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get(self::ApiUrl.'/'.substr($vatNumber, 0, 2).'/vat/'.substr($vatNumber, 2));
        } catch (Throwable) {
            return ['status' => self::Unavailable, 'name' => null, 'address' => null];
        }

        $error = $response->json('userError');

        if (! $response->successful() || ! in_array($error, ['VALID', 'INVALID'], true)) {
            return ['status' => self::Unavailable, 'name' => null, 'address' => null];
        }

        // Tidy up spaces but keep the line breaks: they separate the street from the postal code and city.
        $clean = fn (?string $value): ?string => in_array(trim((string) $value), ['', '---'], true)
            ? null
            : implode("\n", array_filter(array_map(fn (string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)), preg_split('/\R/', trim((string) $value)))));

        return [
            'status' => $response->json('isValid') ? self::Valid : self::Invalid,
            'name' => $clean($response->json('name')),
            'address' => $clean($response->json('address')),
        ];
    }
}
