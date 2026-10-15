<?php

use App\Filament\Pages\Revenue;
use App\Filament\Widgets\RevenueStats;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\User;
use App\Support\RevenueReport;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-20 12:00');
    actingAs(User::factory()->create());

    $acme = Affiliate::withoutEvents(fn () => Affiliate::factory()->create(['name' => 'Acme']));
    $bolt = Affiliate::withoutEvents(fn () => Affiliate::factory()->create(['name' => 'Bolt']));

    // Invoiced in October: 50 + 30 (the 30 is paid), and a cancelled one that does not count.
    AffiliateInvoice::factory()->for($acme)->create(['amount' => '50.00', 'total_amount' => '60.50', 'status' => 'issued', 'created_at' => '2026-10-02 10:00']);
    AffiliateInvoice::factory()->for($bolt)->create(['amount' => '30.00', 'total_amount' => '36.30', 'status' => 'paid', 'created_at' => '2026-10-03 10:00', 'paid_at' => '2026-10-10 10:00']);
    AffiliateInvoice::factory()->for($bolt)->create(['amount' => '999.00', 'status' => 'cancelled', 'created_at' => '2026-10-04 10:00']);
    // Invoiced in September, paid in October.
    AffiliateInvoice::factory()->for($acme)->create(['amount' => '200.00', 'total_amount' => '242.00', 'status' => 'paid', 'created_at' => '2026-09-05 10:00', 'paid_at' => '2026-10-01 09:00']);
});

it('calculates invoiced, received and the manager commission per month', function () {
    expect(RevenueReport::forMonth(now()))->toMatchArray([
        'label' => 'oktober 2026',
        'invoiced_count' => 2,
        'invoiced' => 80.0,
        'invoiced_incl_vat' => 96.8,
        'received_count' => 2,
        'received' => 230.0,
        'received_incl_vat' => 278.3,
        'commission' => 23.0,
    ])
        ->and(RevenueReport::forMonth(now()->subMonth()))->toMatchArray(['invoiced' => 200.0, 'received' => 0.0, 'commission' => 0.0]);
});

it('splits a month per affiliate', function () {
    expect(RevenueReport::perAffiliate(now()))->toBe([
        ['affiliate' => 'Acme', 'invoiced' => 50.0, 'received' => 200.0, 'commission' => 20.0],
        ['affiliate' => 'Bolt', 'invoiced' => 30.0, 'received' => 30.0, 'commission' => 3.0],
    ]);
});

it('uses the commission percentage from the settings', function () {
    RevenueReport::saveCommissionSettings(['manager' => 'Ben', 'percentage' => 12.5]);

    expect(RevenueReport::forMonth(now())['commission'])->toBe(28.75);
});

it('shows the revenue page with this month and the last 12 months', function () {
    get(Revenue::getUrl())->assertOk();

    Livewire::test(RevenueStats::class)
        ->assertSee('€ 80,00')
        ->assertSee('€ 230,00')
        ->assertSee('Commission Ben Durinck')
        ->assertSee('€ 23,00');

    Livewire::test(Revenue::class)
        ->assertSee('oktober 2026')
        ->assertSee('september 2026')
        ->assertSee('Last 12 months')
        ->assertSee('€ 280,00')
        ->mountAction(TestAction::make('details')->table('2026-10'))
        ->assertActionMounted(TestAction::make('details')->table('2026-10'));

    $perAffiliate = (fn () => static::perAffiliateHtml(now()))->call(new Revenue)->toHtml();

    expect($perAffiliate)->toContain('Acme')->toContain('Bolt')->toContain('€ 20,00')->toContain('€ 230,00');
});

it('keeps viewers out of the revenue page', function () {
    actingAs(User::factory()->viewer()->create());

    get(Revenue::getUrl())->assertForbidden();
});

it('lets admins change the commission settings', function () {
    Livewire::test(Revenue::class)
        ->callAction('commissionSettings', ['manager' => 'Ben', 'percentage' => 15])
        ->assertHasNoFormErrors();

    expect(RevenueReport::commissionSettings())->toBe(['manager' => 'Ben', 'percentage' => 15.0]);
});

it('exports the months to excel', function () {
    Livewire::test(Revenue::class)
        ->callAction(TestAction::make('export')->table())
        ->assertFileDownloaded('revenue-2026-10-20.csv');
});
