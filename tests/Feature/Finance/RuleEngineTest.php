<?php

use App\Models\Finance\Rule;
use App\Services\Finance\RuleEngine;
use App\Services\Finance\Suggester;

/**
 * Ported from the e-Skool Finance (Next.js) app's rule tests.
 *
 * @return array<string, mixed>
 */
function financeLine(array $overrides = []): array
{
    return [
        'accountId' => 1, 'amountCents' => -60500, 'counterpartyName' => 'ReTrend', 'counterpartyIban' => 'NL00 RABO 0000 0000 03',
        'description' => 'Factuur 2026-014 flat fee', 'mutationCode' => 'GT', 'status' => 'uncategorized', ...$overrides,
    ];
}

function financeRule(array $attributes): Rule
{
    return new Rule(['name' => 'r', 'priority' => 100, 'active' => true, 'conditions' => [], 'action' => ['categoryId' => 1], ...$attributes]);
}

it('picks the first matching rule by priority', function () {
    $payee = financeRule(['name' => 'ReTrend default', 'priority' => 200, 'conditions' => [['field' => 'counterparty', 'op' => 'equals', 'value' => 'retrend']]]);
    $phone = financeRule(['name' => 'ReTrend telefoon', 'priority' => 100, 'conditions' => [['field' => 'counterparty', 'op' => 'equals', 'value' => 'ReTrend'], ['field' => 'description', 'op' => 'contains', 'value' => 'telefoon']]]);

    expect(RuleEngine::categorize(financeLine(['description' => 'Declaratie telefoon aug']), [$payee, $phone])?->name)->toBe('ReTrend telefoon')
        ->and(RuleEngine::categorize(financeLine(), [$payee, $phone])?->name)->toBe('ReTrend default');
});

it('never overwrites confirmed or excluded lines', function () {
    $payee = financeRule(['conditions' => [['field' => 'counterparty', 'op' => 'equals', 'value' => 'retrend']]]);

    expect(RuleEngine::categorize(financeLine(['status' => 'confirmed']), [$payee]))->toBeNull()
        ->and(RuleEngine::categorize(financeLine(['status' => 'excluded']), [$payee]))->toBeNull()
        ->and(RuleEngine::categorize(financeLine(['status' => 'auto']), [$payee]))->not->toBeNull();
});

it('skips inactive rules and rules without conditions', function () {
    expect(RuleEngine::categorize(financeLine(), [financeRule(['active' => false, 'conditions' => [['field' => 'counterparty', 'op' => 'equals', 'value' => 'retrend']]])]))->toBeNull()
        ->and(RuleEngine::categorize(financeLine(), [financeRule(['conditions' => []])]))->toBeNull();
});

it('normalizes IBAN spacing and text case', function () {
    expect(RuleEngine::matchesConditions(financeLine(), [['field' => 'iban', 'op' => 'equals', 'value' => 'nl00rabo0000000003']]))->toBeTrue()
        ->and(RuleEngine::matchesConditions(financeLine(), [['field' => 'description', 'op' => 'starts_with', 'value' => 'FACTUUR']]))->toBeTrue();
});

it('supports direction, amount ranges and regex, and treats an invalid regex as no match', function () {
    $conditions = [
        ['field' => 'direction', 'op' => 'equals', 'value' => 'out'],
        ['field' => 'amount', 'op' => 'between', 'value' => 50000, 'value2' => 70000],
        ['field' => 'description', 'op' => 'regex', 'value' => 'flat\s*fee'],
    ];

    expect(RuleEngine::matchesConditions(financeLine(), $conditions))->toBeTrue()
        ->and(RuleEngine::matchesConditions(financeLine(['amountCents' => 60500]), $conditions))->toBeFalse()
        ->and(RuleEngine::matchesConditions(financeLine(['amountCents' => -10000]), $conditions))->toBeFalse()
        ->and(RuleEngine::matchesConditions(financeLine(), [['field' => 'description', 'op' => 'regex', 'value' => '([']]))->toBeFalse();
});

it('describes conditions in Dutch', function () {
    expect(RuleEngine::describeCondition(['field' => 'counterparty', 'op' => 'contains', 'value' => 'mollie']))->toBe('Tegenpartij bevat "mollie"')
        ->and(RuleEngine::describeCondition(['field' => 'direction', 'op' => 'equals', 'value' => 'in']))->toBe('Richting is inkomend')
        ->and(RuleEngine::describeCondition(['field' => 'account_id', 'op' => 'equals', 'value' => 3], [3 => 'Amex']))->toBe('Rekening is "Amex"');
});

it('suggests a category when at least 90% of the history agrees', function () {
    $history = [
        ...array_fill(0, 19, ['counterpartyName' => 'Google Ireland Limited', 'counterpartyIban' => 'NL00CITI0000000002', 'categoryId' => 'adspend-google']),
        ['counterpartyName' => 'Google Ireland Limited', 'counterpartyIban' => 'NL00CITI0000000002', 'categoryId' => 'overig'],
        ['counterpartyName' => 'Bart', 'counterpartyIban' => 'NL00INGB0000000077', 'categoryId' => 'closer'],
        ['counterpartyName' => 'Bart', 'counterpartyIban' => 'NL00INGB0000000077', 'categoryId' => 'captain-flat-fee'],
        ['counterpartyName' => 'Eenmalig', 'counterpartyIban' => null, 'categoryId' => 'overig'],
    ];
    $suggester = new Suggester($history);

    expect($suggester->suggest('whatever', 'NL00CITI0000000002'))->toMatchArray(['categoryId' => 'adspend-google', 'basis' => 'iban', 'support' => 19, 'total' => 20])
        ->and($suggester->suggest('google ireland  limited', null))->toMatchArray(['basis' => 'counterparty'])
        ->and($suggester->suggest('Bart', 'NL00INGB0000000077'))->toBeNull()
        ->and($suggester->suggest('Eenmalig', null))->toBeNull();
});
