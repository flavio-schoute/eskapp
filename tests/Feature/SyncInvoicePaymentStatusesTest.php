<?php

use App\Enums\Integration;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\IntegrationError;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetSalesInvoiceRequest;
use Mollie\Laravel\Facades\Mollie;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['mollie.key' => 'live_'.str_repeat('a', 30)]);
});

function openInvoice(array $attributes = []): AffiliateInvoice
{
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create());

    return AffiliateInvoice::factory()->for($affiliate)->create(['status' => 'issued', ...$attributes]);
}

it('marks invoices as paid when mollie reports them paid', function () {
    Mollie::fake([
        GetSalesInvoiceRequest::class => MockResponse::ok(['resource' => 'sales-invoice', 'id' => 'invoice_a', 'status' => 'paid']),
    ]);
    $invoice = openInvoice(['mollie_sales_invoice_id' => 'invoice_a']);
    $alreadyPaid = openInvoice(['status' => 'paid', 'paid_at' => now()->subWeek()]);

    artisan('invoices:sync-payment-status')
        ->expectsOutput('Checked 1 open invoices, 1 now paid.')
        ->assertSuccessful();

    expect($invoice->refresh()->isPaid())->toBeTrue()
        ->and($invoice->paid_at)->not->toBeNull()
        ->and($alreadyPaid->refresh()->paid_at->isSameDay(now()->subWeek()))->toBeTrue();
});

it('logs invoices that could not be checked', function () {
    Mollie::fake([
        GetSalesInvoiceRequest::class => MockResponse::notFound(),
    ]);
    openInvoice();

    artisan('invoices:sync-payment-status')->assertSuccessful();

    expect(IntegrationError::where('integration', Integration::Mollie)->where('action', 'Check payment status')->count())->toBe(1);
});

it('checks the payment status every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'invoices:sync-payment-status'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});

it('uses the payment date mollie reports', function () {
    Mollie::fake([
        GetSalesInvoiceRequest::class => MockResponse::ok(['resource' => 'sales-invoice', 'id' => 'invoice_a', 'status' => 'paid', 'paidAt' => '2026-10-12T09:30:00+00:00']),
    ]);
    $invoice = openInvoice(['mollie_sales_invoice_id' => 'invoice_a']);

    artisan('invoices:sync-payment-status')->assertSuccessful();

    expect($invoice->refresh()->paid_at->toIso8601String())->toBe('2026-10-12T09:30:00+00:00');
});

it('stops checking invoices that were cancelled or expired', function () {
    Mollie::fake([
        GetSalesInvoiceRequest::class => MockResponse::ok(['resource' => 'sales-invoice', 'id' => 'invoice_a', 'status' => 'cancelled']),
    ]);
    $invoice = openInvoice(['mollie_sales_invoice_id' => 'invoice_a']);
    openInvoice(['status' => 'expired']);

    artisan('invoices:sync-payment-status')
        ->expectsOutput('Checked 1 open invoices, 0 now paid.')
        ->assertSuccessful();

    expect($invoice->refresh()->status)->toBe('cancelled');

    artisan('invoices:sync-payment-status')
        ->expectsOutput('Checked 0 open invoices, 0 now paid.')
        ->assertSuccessful();
});
