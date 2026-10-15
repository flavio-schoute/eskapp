<?php

use App\Filament\Widgets\CommissionChart;
use App\Filament\Widgets\DashboardStats;
use App\Models\Affiliate;
use App\Models\AffiliateContract;
use App\Models\AffiliateInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-15 12:00');
    actingAs(User::factory()->create());
});

it('shows open and overdue invoices, waiting contracts and the pipeline', function () {
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create());
    Affiliate::withoutEvents(fn () => Affiliate::factory()->pipeline()->count(2)->create());
    AffiliateInvoice::factory()->for($affiliate)->create(['status' => 'issued', 'total_amount' => '121.00', 'due_at' => now()->addDays(10)]);
    AffiliateInvoice::factory()->for($affiliate)->create(['status' => 'issued', 'total_amount' => '242.00', 'due_at' => now()->subDays(3)]);
    AffiliateInvoice::factory()->for($affiliate)->create(['status' => 'paid', 'total_amount' => '1000.00']);
    AffiliateContract::factory()->for($affiliate)->create(['created_at' => now()->subDays(4)]);
    AffiliateContract::factory()->signed()->create();

    Livewire::test(DashboardStats::class)
        ->assertSee('€ 363,00')
        ->assertSee('2 invoices waiting for payment')
        ->assertSee('€ 242,00 past the due date')
        ->assertSee('Longest waiting: 4 days')
        ->assertSee('Affiliates in pipeline');
});

it('charts the invoiced and received commission per month like the revenue page', function () {
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create());
    AffiliateInvoice::factory()->for($affiliate)->create(['amount' => '100.00', 'status' => 'paid', 'created_at' => '2026-09-03 10:00', 'paid_at' => '2026-10-02 10:00']);
    AffiliateInvoice::factory()->for($affiliate)->create(['amount' => '50.00', 'status' => 'issued', 'created_at' => '2026-09-04 10:00']);
    AffiliateInvoice::factory()->for($affiliate)->create(['amount' => '80.00', 'status' => 'cancelled', 'created_at' => '2026-08-04 10:00']);

    $data = (fn () => $this->getData())->call(Livewire::test(CommissionChart::class)->instance());

    expect($data['labels'])->toHaveCount(12)
        ->and($data['labels'][10])->toBe('Sep 2026')
        ->and($data['datasets'][0]['data'][10])->toBe(150.0)
        ->and($data['datasets'][1]['data'][10])->toBe(0.0)
        ->and($data['datasets'][1]['data'][11])->toBe(100.0)
        ->and($data['datasets'][0]['data'][9])->toBe(0.0);
});

it('shows the dashboard to everyone', function () {
    get('/admin')->assertOk()->assertSeeLivewire(DashboardStats::class)->assertSeeLivewire(CommissionChart::class);
});

it('only shows viewers the contracts and pipeline tiles', function () {
    actingAs(User::factory()->viewer()->create());

    get('/admin')->assertOk()->assertSeeLivewire(DashboardStats::class)->assertDontSeeLivewire(CommissionChart::class);

    Livewire::test(DashboardStats::class)
        ->assertSee(['Contracts waiting for a signature', 'Affiliates in pipeline'])
        ->assertDontSee(['Open invoices', 'Overdue invoices']);
});
