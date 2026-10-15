<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\Integration;
use App\Filament\Pages\AffiliateInvoices;
use App\Filament\Resources\SlackMembers\Pages\ManageSlackMembers;
use App\Jobs\IssueAffiliateInvoice;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\IntegrationError;
use App\Models\User;
use App\Services\IntegrationErrorLogger;
use App\Services\MollieSalesInvoices;
use App\Services\SlackInvoiceMessages;
use App\Support\InvoiceReminder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreateSalesInvoiceRequest;
use Mollie\Laravel\Facades\Mollie;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00');

    Http::preventStrayRequests();
    config([
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);

    actingAs(User::factory()->create());
});

function invoicedAffiliate(array $attributes = []): Affiliate
{
    return Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create([
        'payment_method' => AffiliatePaymentMethod::Invoice,
        ...$attributes,
    ]));
}

/**
 * Set the amount of the monthly invoice row of an affiliate.
 *
 * @param  array<string, float|string|null>  $amounts  Amounts by affiliate name.
 */
function withAmounts(array $amounts): Closure
{
    return function (array $state) use ($amounts): array {
        foreach ($state['invoices'] as $key => $row) {
            if (array_key_exists($row['name'], $amounts)) {
                $state['invoices'][$key]['amount'] = $amounts[$row['name']];
            }
        }

        return $state;
    };
}

it('lists every affiliate paid by invoice for last month', function () {
    $ready = invoicedAffiliate(['name' => 'Acme']);
    $incomplete = invoicedAffiliate(['name' => 'Bolt', 'invoice_city' => null]);
    $invoiced = invoicedAffiliate(['name' => 'Cobalt']);
    AffiliateInvoice::factory()->for($invoiced)->create(['period' => '2026-09-01', 'invoice_number' => 'INV-0007']);

    $rows = collect(Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('monthlyInvoices')->table())
        ->assertSchemaStateSet(['period' => '2026-09-01'])
        ->instance()->mountedActions[0]['data']['invoices'])->keyBy('name');

    expect($rows->keys()->all())->toBe(['Acme', 'Bolt', 'Cobalt'])
        ->and($rows['Acme']['blocked_reason'])->toBeNull()
        ->and($rows['Acme']['description'])->toContain('september 2026')
        ->and($rows['Bolt']['blocked_reason'])->toBe('Invoice details incomplete')
        ->and($rows['Cobalt']['blocked_reason'])->toBe('Already invoiced (INV-0007)')
        ->and($rows['Cobalt']['previous'])->toBe('€ 100,00 · Sep 2026');
});

it('sends an invoice in the background for every affiliate with an amount', function () {
    Queue::fake();
    $acme = invoicedAffiliate(['name' => 'Acme']);
    invoicedAffiliate(['name' => 'Bolt']);

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('monthlyInvoices')->table())
        ->fillForm(withAmounts(['Acme' => '250.00']))
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Sending 1 invoice for September 2026');

    Queue::assertPushed(IssueAffiliateInvoice::class, 1);
    Queue::assertPushed(fn (IssueAffiliateInvoice $job): bool => $job->affiliateId === $acme->getKey()
        && $job->period === '2026-09-01'
        && (float) $job->amount === 250.0
        && str_contains($job->description, 'september 2026'));
});

it('does not send anything without amounts', function () {
    Queue::fake();
    invoicedAffiliate(['name' => 'Acme']);

    Livewire::test(AffiliateInvoices::class)
        ->callAction(TestAction::make('monthlyInvoices')->table())
        ->assertNotified('No invoices to send');

    Queue::assertNothingPushed();
});

it('creates the invoice in mollie with the default email template', function () {
    config(['mollie.key' => 'access_'.str_repeat('a', 30)]);
    Mollie::fake([
        CreateSalesInvoiceRequest::class => MockResponse::created([
            'resource' => 'sales-invoice',
            'id' => 'invoice_monthly',
            'invoiceNumber' => 'INV-0100',
            'status' => 'issued',
            'totalAmount' => ['currency' => 'EUR', 'value' => '302.50'],
        ]),
    ]);
    $affiliate = invoicedAffiliate();

    (new IssueAffiliateInvoice($affiliate->getKey(), '2026-09-01', '250.00', 'Affiliate commissie – september 2026', '30 days', postToSlack: false))
        ->handle(app(MollieSalesInvoices::class), app(SlackInvoiceMessages::class), app(IntegrationErrorLogger::class));

    expect(AffiliateInvoice::sole())
        ->affiliate_id->toBe($affiliate->getKey())
        ->invoice_number->toBe('INV-0100')
        ->period->toDateString()->toBe('2026-09-01');
});

it('never invoices the same month twice', function () {
    Mollie::fake([]);
    $affiliate = invoicedAffiliate();
    AffiliateInvoice::factory()->for($affiliate)->create(['period' => '2026-09-01']);

    (new IssueAffiliateInvoice($affiliate->getKey(), '2026-09-01', '250.00', 'Commissie', '30 days'))
        ->handle(app(MollieSalesInvoices::class), app(SlackInvoiceMessages::class), app(IntegrationErrorLogger::class));

    expect(AffiliateInvoice::count())->toBe(1);
    Mollie::assertSentCount(0);
});

it('logs an invoice that could not be created', function () {
    $affiliate = invoicedAffiliate(['invoice_city' => null]);

    (new IssueAffiliateInvoice($affiliate->getKey(), '2026-09-01', '250.00', 'Commissie', '30 days'))
        ->handle(app(MollieSalesInvoices::class), app(SlackInvoiceMessages::class), app(IntegrationErrorLogger::class));

    expect(IntegrationError::sole())
        ->integration->toBe(Integration::Mollie)
        ->action->toBe('Generate invoice')
        ->message->toContain('city');
});

it('reminds the team in slack on the day and time from the settings, once a month', function () {
    config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test']);
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);
    invoicedAffiliate();
    InvoiceReminder::save(['enabled' => true, 'channel' => 'C0TEAM', 'day' => 4, 'time' => '09:00', 'message' => 'Facturen van {month} ({count}): {link}']);

    $this->travelTo(Carbon::parse('2026-10-04 08:55', 'Europe/Amsterdam'));
    artisan('invoices:monthly-reminder')->assertSuccessful();
    Http::assertNothingSent();

    $this->travelTo(Carbon::parse('2026-10-04 09:00', 'Europe/Amsterdam'));
    artisan('invoices:monthly-reminder')->assertSuccessful();
    artisan('invoices:monthly-reminder')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['channel'] === 'C0TEAM'
        && $request['text'] === 'Facturen van september 2026 (1): '.AffiliateInvoices::getUrl(panel: 'admin'));
});

it('does not remind when the reminder is turned off', function () {
    config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test']);
    Http::fake();
    InvoiceReminder::save(['enabled' => false, 'channel' => 'C0TEAM', 'day' => 7, 'time' => '09:00', 'message' => 'Hi']);

    artisan('invoices:monthly-reminder')->assertSuccessful();

    Http::assertNothingSent();
});

it('defaults to the 4th of the month at 9:00', function () {
    expect(InvoiceReminder::settings())->toMatchArray(['enabled' => true, 'day' => 4, 'time' => '09:00', 'message' => InvoiceReminder::DefaultMessage]);
});

it('changes the reminder under slack settings', function () {
    Livewire::test(ManageSlackMembers::class)
        ->callAction('invoiceReminder', [
            'enabled' => true,
            'channel' => 'C0TEAM123',
            'day' => 10,
            'time' => '08:30',
            'message' => 'Facturen versturen voor {month}!',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Invoice reminder saved');

    expect(InvoiceReminder::settings())->toMatchArray(['channel' => 'C0TEAM123', 'day' => 10, 'time' => '08:30', 'message' => 'Facturen versturen voor {month}!']);
});

it('requires a valid channel id for the reminder', function () {
    Livewire::test(ManageSlackMembers::class)
        ->callAction('invoiceReminder', ['enabled' => true, 'channel' => '#facturen'])
        ->assertHasFormErrors(['channel' => 'regex']);
});
