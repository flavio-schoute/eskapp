<?php

use App\Support\ViesAddress;

it('turns a vies address into tidy invoice fields', function (string $address, string $country, array $expected) {
    expect(ViesAddress::parse($address, $country))->toBe($expected);
})->with([
    'Netherlands' => ["KWIKSTAARTLAAN 00042\n3704GS ZEIST", 'NL', ['street' => 'Kwikstaartlaan 42', 'street_additional' => null, 'postal_code' => '3704 GS', 'city' => 'Zeist']],
    'small words stay lowercase' => ["LAAN VAN MEERDERVOORT 1\n2517AA DEN HAAG", 'NL', ['street' => 'Laan van Meerdervoort 1', 'street_additional' => null, 'postal_code' => '2517 AA', 'city' => 'Den Haag']],
    'Belgium' => ["RUE DE LA LOI 16\n1000 BRUXELLES", 'BE', ['street' => 'Rue de la Loi 16', 'street_additional' => null, 'postal_code' => '1000', 'city' => 'Bruxelles']],
    'second address line' => ["12 RUE DE RIVOLI\nBATIMENT A\n75001 PARIS", 'FR', ['street' => '12 Rue de Rivoli', 'street_additional' => 'Batiment A', 'postal_code' => '75001', 'city' => 'Paris']],
    'empty' => ['', 'DE', ['street' => null, 'street_additional' => null, 'postal_code' => null, 'city' => null]],
]);

it('keeps legal forms written correctly in the company name', function (string $name, string $expected) {
    expect(ViesAddress::companyName($name))->toBe($expected);
})->with([
    ['ESKOOL B.V.', 'Eskool B.V.'],
    ['EXAMPLE GMBH', 'Example GmbH'],
    ['ACME LTD', 'Acme Ltd'],
    ['SOCIETE EXEMPLE SAS', 'Societe Exemple SAS'],
]);

it('gets the country from the vat number prefix', function (string $vatNumber, ?string $country) {
    expect(ViesAddress::countryFromVatNumber($vatNumber))->toBe($country);
})->with([
    ['NL866580748B01', 'NL'],
    ['BE0123456789', 'BE'],
    ['EL123456789', 'GR'],
    ['XI123456789', 'GB'],
]);
