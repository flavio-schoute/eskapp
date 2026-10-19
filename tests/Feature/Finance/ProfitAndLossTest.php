<?php

use App\Enums\Finance\CategoryKind;
use App\Models\Finance\Category;
use App\Services\Finance\ProfitAndLoss;

/**
 * Ported from the e-Skool Finance (Next.js) app's P&L tests.
 */
function plCategory(int $id, array $attributes): Category
{
    $category = new Category(['parent_id' => null, 'code' => null, 'kind' => CategoryKind::Expense, 'default_vat_rate' => 0.21, 'below_operating' => false, 'sort_order' => 0, ...$attributes]);
    $category->id = $id;

    return $category;
}

beforeEach(function () {
    // 1 Marketing > 2 Advertenties, 3 Fiscaal (below operating) > 4 Fiscale kosten, 5 Omzet (bank) > 6 Affiliate, 7 Overboekingen > 8 Eigen rekening.
    $categories = collect([
        plCategory(1, ['name' => 'Marketing', 'sort_order' => 1]),
        plCategory(2, ['parent_id' => 1, 'name' => 'Advertenties']),
        plCategory(3, ['name' => 'Fiscaal', 'below_operating' => true, 'sort_order' => 9]),
        plCategory(4, ['parent_id' => 3, 'name' => 'Fiscale kosten']),
        plCategory(5, ['name' => 'Omzet (bank)', 'kind' => CategoryKind::Revenue]),
        plCategory(6, ['parent_id' => 5, 'name' => 'Affiliate', 'kind' => CategoryKind::Revenue, 'code' => '454545']),
        plCategory(7, ['name' => 'Overboekingen', 'kind' => CategoryKind::Transfer]),
        plCategory(8, ['parent_id' => 7, 'name' => 'Eigen rekening', 'kind' => CategoryKind::Transfer]),
    ])->keyBy('id');

    $this->pl = ProfitAndLoss::build(
        [
            ['month' => '2026-01', 'category_id' => 2, 'ex_vat_cents' => 100_00],
            ['month' => '2026-01', 'category_id' => 4, 'ex_vat_cents' => 30_00],
            ['month' => '2026-01', 'category_id' => 6, 'ex_vat_cents' => -50_00],
            ['month' => '2026-01', 'category_id' => 8, 'ex_vat_cents' => 9999_00],
            ['month' => '2026-02', 'category_id' => 2, 'ex_vat_cents' => 20_00],
        ],
        [
            ['month' => '2026-01', 'stream_id' => 10, 'ex_vat_cents' => 1000_00],
            ['month' => '2026-01', 'stream_id' => null, 'ex_vat_cents' => 5_00],
        ],
        $categories,
        [['id' => 10, 'name' => 'Coaching']],
    );
});

it('adds order and bank revenue and ignores transfers', function () {
    expect($this->pl['revenue']['2026-01'])->toBe(1055_00)
        ->and(array_column($this->pl['revenueStreams'], 'id'))->toBe(['10', ProfitAndLoss::UnmappedStream, 'cat:6'])
        ->and($this->pl['costs']['2026-01'])->toBe(100_00);
});

it('splits the operating profit from below-the-line costs', function () {
    expect($this->pl['operating']['2026-01'])->toBe(955_00)
        ->and($this->pl['below']['2026-01'])->toBe(30_00)
        ->and($this->pl['net']['2026-01'])->toBe(925_00)
        ->and($this->pl['operating']['2026-02'])->toBe(-20_00)
        ->and(array_column($this->pl['belowGroups'], 'name'))->toBe(['Fiscaal']);
});

it('keeps the lines per group for drilling down', function () {
    expect($this->pl['costGroups'][0]['lines'][0])->toMatchArray(['id' => 2, 'byMonth' => ['2026-01' => 100_00, '2026-02' => 20_00]]);
});

it('resolves periods', function () {
    expect(ProfitAndLoss::periodMonths(2026, 'ytd', 3))->toBe(['2026-01', '2026-02', '2026-03'])
        ->and(ProfitAndLoss::periodMonths(2026, 'q2', 12))->toBe(['2026-04', '2026-05', '2026-06'])
        ->and(ProfitAndLoss::periodMonths(2026, 'm11', 12))->toBe(['2026-11'])
        ->and(ProfitAndLoss::periodMonths(2026, 'year', 1))->toHaveCount(12)
        ->and(ProfitAndLoss::shiftYear(['2026-03'], -1))->toBe(['2025-03'])
        ->and(ProfitAndLoss::sumMonths(['2026-01' => 5, '2026-02' => 7], ['2026-01', '2026-02', '2026-03']))->toBe(12);
});
