<?php

use App\Services\Finance\BankCsvParser;
use App\Services\Finance\Dedupe;
use App\Services\Finance\Money;

/**
 * Ported from the e-Skool Finance (Next.js) app's tests, with the same fixtures, so both read exports identically.
 */
function financeFixture(string $file): string
{
    return file_get_contents(base_path("tests/Fixtures/finance/{$file}"));
}

it('parses amounts in Dutch and English notation', function (string $input, int $cents) {
    expect(Money::parseToCents($input))->toBe($cents);
})->with([
    ['1.250,00', 125000], ['-12,50', -1250], ['+3,00', 300], ['4,5', 450],
    ['1,234.56', 123456], ['1234.56', 123456], ['1.234.567', 123456700], ['0,01', 1],
]);

it('rejects garbage amounts', function () {
    Money::parseToCents('abc');
})->throws(InvalidArgumentException::class);

it("computes ex-VAT like the sheet's ROUND(x/1.21, 2)", function () {
    expect(Money::exVatCents(-242000, 0.21))->toBe(-200000)
        ->and(Money::exVatCents(-3200, 0))->toBe(-3200)
        ->and(Money::exVatCents(-10900, 0.09))->toBe(-10000)
        ->and(Money::exVatCents(-100, 0.21))->toBe(-83);
});

it('reads ING (NL) exports: signs amounts by Af/Bij and extracts the description', function () {
    $result = BankCsvParser::parse(financeFixture('ing-nl.csv'));

    expect($result['format'])->toBe('ing-nl')
        ->and($result['errors'])->toBe([])
        ->and($result['rows'])->toHaveCount(5);

    [$google, , $mollie, $coffee] = $result['rows'];

    expect($google)
        ->bookedOn->toBe('2026-09-01')
        ->amountCents->toBe(-125000)
        ->counterpartyName->toBe('Google Ireland Limited')
        ->counterpartyIban->toBe('NL00CITI0000000002')
        ->description->toBe('Google Ads 12345')
        ->mutationCode->toBe('IC')
        ->balanceAfterCents->toBe(1000000)
        ->and($mollie->amountCents)->toBe(432109)
        ->and($coffee->description)->toContain('Pasvolgnr');
});

it('reads ING (EN) Debit/Credit exports', function () {
    $result = BankCsvParser::parse(financeFixture('ing-en.csv'));

    expect($result['format'])->toBe('ing-en')
        ->and(array_map(fn ($row) => $row->amountCents, $result['rows']))->toBe([-9680, 12100])
        ->and($result['rows'][0]->description)->toBe('Notion subscription');
});

it('reads Rabobank exports with signed amounts and joined descriptions', function () {
    $result = BankCsvParser::parse(financeFixture('rabobank.csv'));

    expect($result['format'])->toBe('rabobank')
        ->and($result['rows'][0])
        ->bookedOn->toBe('2024-05-14')
        ->amountCents->toBe(-3200)
        ->counterpartyName->toBe('Todoist')
        ->description->toBe('Todoist Pro jaarabonnement')
        ->balanceAfterCents->toBe(196800)
        ->and($result['rows'][1]->amountCents)->toBe(200000);
});

it('asks for a column mapping for unknown formats and applies a saved one', function () {
    $csv = "Datum,Omschrijving,Bedrag,Type\n03/09/2026,Facebook Ads,150.00,Debit\n04/09/2026,Refund,20.00,Credit\n";

    $unknown = BankCsvParser::parse($csv);
    expect($unknown['format'])->toBe('generic')
        ->and($unknown['rows'])->toBe([])
        ->and($unknown['errors'][0]['message'])->toContain('kolomindeling');

    $mapped = BankCsvParser::parse($csv, ['date' => 'Datum', 'dateFormat' => 'DD/MM/YYYY', 'amount' => 'Bedrag', 'counterparty' => 'Omschrijving', 'direction' => ['column' => 'Type', 'outValues' => ['Debit']]]);
    expect(array_map(fn ($row) => [$row->bookedOn, $row->amountCents], $mapped['rows']))->toBe([['2026-09-03', -15000], ['2026-09-04', 2000]]);
});

it('keeps dedupe hashes stable across re-uploads and identical same-day lines apart', function () {
    $rows = BankCsvParser::parse(financeFixture('ing-nl.csv'))['rows'];
    $hashes = Dedupe::hashes(1, $rows);

    expect($hashes)->toBe(Dedupe::hashes(1, BankCsvParser::parse(financeFixture('ing-nl.csv'))['rows']))
        ->and(array_unique($hashes))->toHaveCount(5)
        ->and(Dedupe::hashes(2, $rows)[0])->not->toBe($hashes[0]);
});

it('extracts the ING description up to the next known label', function () {
    expect(BankCsvParser::extractIngDescription('Naam: X Omschrijving: Factuur 12 IBAN: NL00 Kenmerk: K'))->toBe('Factuur 12')
        ->and(BankCsvParser::extractIngDescription('Name: X Description: Sub Value date: 01/01/2026'))->toBe('Sub');
});

it('reads American Express exports: MM/DD dates, purchases out and repayments in', function () {
    $result = BankCsvParser::parse(financeFixture('amex.csv'));

    expect($result['format'])->toBe('amex')
        ->and($result['errors'])->toBe([])
        ->and($result['rows'])->toHaveCount(15)
        ->and($result['rows'][0])
        ->bookedOn->toBe('2026-09-24')
        ->amountCents->toBe(-176600)
        ->counterpartyName->toBe('FACEBK *3SE3W56B74')
        ->description->toBe('FACEBK *3SE3W56B74 DUBLIN');

    $repayment = collect($result['rows'])->first(fn ($row) => str_starts_with($row->counterpartyName, 'HARTELIJK'));
    expect($repayment)->bookedOn->toBe('2026-09-15')->amountCents->toBe(1000000)
        ->and(collect($result['rows'])->contains(fn ($row) => $row->bookedOn === '2026-09-11' && $row->amountCents === 1219637))->toBeTrue();

    $trengo = array_values(array_filter($result['rows'], fn ($row) => $row->counterpartyName === 'MOLLIE=TRENGO BV'));
    expect($trengo)->toHaveCount(2)
        ->and(array_unique(Dedupe::hashes(1, $trengo)))->toHaveCount(2);
});
