<?php

use App\Support\InvoiceAddressFormat;

it('formats a chinese address on one line with postal code and country', function () {
    expect(InvoiceAddressFormat::lines([
        'company_name' => 'DAYONE FULFILLMENT CO.,LTD',
        'address_line_1' => 'Rm808 Block A Zhongguanxilu 1277#',
        'region' => 'Zhenhaiqu',
        'city' => 'Ningbo',
        'postal_code' => '315201',
        'country' => 'CN',
    ]))->toBe([
        'DAYONE FULFILLMENT CO.,LTD',
        'Rm808 Block A Zhongguanxilu 1277# Zhenhaiqu Ningbo, 315201, China',
    ]);
});

it('formats a dutch address with the postal code before the city', function () {
    expect(InvoiceAddressFormat::lines([
        'company_name' => 'Re-Trends B.V.',
        'address_line_1' => 'Keizersgracht 1',
        'postal_code' => '1015 CJ',
        'city' => 'Amsterdam',
        'country' => 'NL',
    ]))->toBe([
        'Re-Trends B.V.',
        'Keizersgracht 1',
        '1015 CJ Amsterdam',
        'Netherlands',
    ]);
});

it('formats a us address with city, state and zip code on one line', function () {
    expect(InvoiceAddressFormat::lines([
        'address_line_1' => '123 Main Street',
        'address_line_2' => 'Suite 400',
        'city' => 'New York',
        'region' => 'NY',
        'postal_code' => '10001',
        'country' => 'US',
    ]))->toBe([
        '123 Main Street',
        'Suite 400',
        'New York, NY 10001',
        'United States',
    ]);
});

it('returns no lines when the address is empty', function () {
    expect(InvoiceAddressFormat::lines([]))->toBe([]);
});

it('adapts the field labels to the country', function (?string $country, string $postalLabel, string $regionLabel) {
    expect(InvoiceAddressFormat::postalCodeLabel($country))->toBe($postalLabel)
        ->and(InvoiceAddressFormat::regionLabel($country))->toBe($regionLabel);
})->with([
    'China' => ['CN', 'Postal code', 'District / province'],
    'United States' => ['US', 'ZIP code', 'State'],
    'Netherlands' => ['NL', 'Postal code', 'Region / province'],
    'No country' => [null, 'Postal code', 'Region / province'],
]);

it('lists countries by iso code without non-country regions', function () {
    $countries = InvoiceAddressFormat::countryOptions();

    expect($countries)->toHaveKeys(['NL', 'CN', 'US', 'DE'])
        ->not->toHaveKeys(['EU', 'UN', 'ZZ'])
        ->and($countries['NL'])->toBe('Netherlands');
});
