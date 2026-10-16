<?php

use App\Enums\DebtorCaseType;
use App\Filament\Pages\Debtors;
use App\Filament\Resources\DebtorCases\DebtorCaseResource;
use App\Filament\Resources\DebtorCases\Pages\ViewDebtorCase;
use App\Models\DebtorCase;
use App\Models\DebtorCaseEvent;
use App\Models\User;
use App\Services\DebtorSync;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.plug_and_pay.api_key' => 'pnp-token']);
    Http::preventStrayRequests();
    actingAs(User::factory()->create());
});

/**
 * @return array<string, mixed>
 */
function plugAndPayOrder(int $id, string $paymentStatus, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'invoice_number' => "ESKOOL{$id}",
        'invoice_date' => '2026-09-14',
        'invoice_status' => 'final',
        'invoice_url' => "https://eskool.plugandpay.nl/invoices/{$id}.pdf",
        'amount' => '1798.48',
        'amount_with_tax' => '2176.16',
        'debtor_status' => 'active',
        'debtor_step' => 2,
        'billing' => [
            'contact' => ['firstname' => 'Angelo', 'lastname' => 'Dap', 'company' => 'ASD-services', 'email' => 'angelo@example.com', 'telephone' => '+31681937501'],
            'address' => ['country' => 'NL'],
        ],
        'items' => [['label' => 'Van consument naar ondernemer traject']],
        'payment' => ['status' => $paymentStatus, 'url' => "https://eskool.plugandpay.nl/orders/payment-link/{$id}", 'transaction_id' => 'tr_abc', 'customer_id' => 'cst_abc'],
    ], $overrides);
}

function fakePlugAndPayOrders(array $unpaid, array $chargedBack): void
{
    Http::fake([
        'api.plugandpay.com/v2/orders?*payment_status=open*' => Http::response(['data' => $unpaid, 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/orders?*payment_status=reversed*' => Http::response(['data' => $chargedBack, 'meta' => ['last_page' => 1]]),
    ]);
}

it('syncs unpaid invoices and charged back payments from plug and pay', function () {
    fakePlugAndPayOrders([plugAndPayOrder(17898317, 'open')], [plugAndPayOrder(18210996, 'reversed', ['amount_with_tax' => '314.27'])]);

    artisan('debtors:sync')->assertSuccessful();

    expect(DebtorCase::firstWhere('plug_and_pay_order_id', 17898317))
        ->type->toBe(DebtorCaseType::Unpaid)
        ->customer_name->toBe('Angelo Dap')
        ->company->toBe('ASD-services')
        ->phone->toBe('+31681937501')
        ->product->toBe('Van consument naar ondernemer traject')
        ->amount_with_tax->toBe('2176.16')
        ->plug_and_pay_debtor_step->toBe(2)
        ->and(DebtorCase::firstWhere('plug_and_pay_order_id', 18210996)->type)->toBe(DebtorCaseType::ChargedBack);

    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'payment_status=open') && str_contains(urldecode($request->url()), 'invoice_status=final'));
});

it('resolves cases that are no longer unpaid in plug and pay', function () {
    $paid = DebtorCase::factory()->create(['plug_and_pay_order_id' => 111]);
    fakePlugAndPayOrders([plugAndPayOrder(222, 'open')], []);

    expect(app(DebtorSync::class)->sync())->toBe(['open' => 1, 'resolved' => 1])
        ->and($paid->fresh()->resolved_at)->not->toBeNull();
});

it('reopens a case that is unpaid again', function () {
    $case = DebtorCase::factory()->create(['plug_and_pay_order_id' => 333, 'resolved_at' => now()->subDay()]);
    fakePlugAndPayOrders([], [plugAndPayOrder(333, 'reversed')]);

    app(DebtorSync::class)->sync();

    expect($case->fresh())->resolved_at->toBeNull()->type->toBe(DebtorCaseType::ChargedBack);
});

it('turns local phone numbers into whatsapp numbers', function (?string $phone, ?string $country, ?string $expected) {
    expect(DebtorCase::factory()->make(['phone' => $phone, 'country' => $country])->whatsAppNumber())->toBe($expected);
})->with([
    'international' => ['+31 6 81937501', 'NL', '31681937501'],
    'dutch mobile' => ['06-12345678', 'NL', '31612345678'],
    'belgian mobile' => ['0470 12 34 56', 'BE', '32470123456'],
    'double zero' => ['0031612345678', 'NL', '31612345678'],
    'none' => [null, 'NL', null],
]);

it('lists the open cases with a whatsapp message containing the payment link', function () {
    $unpaid = DebtorCase::factory()->create(['customer_name' => 'Angelo Dap', 'invoice_number' => 'ESKOOL202602188', 'amount_with_tax' => '2176.16']);
    $chargedBack = DebtorCase::factory()->chargedBack()->create();
    $resolved = DebtorCase::factory()->create(['resolved_at' => now()]);

    Livewire::test(Debtors::class)
        ->assertSeeHtml("\$set('activeTab', 'charged_back')")
        ->assertCanSeeTableRecords([$unpaid])
        ->assertCanNotSeeTableRecords([$chargedBack, $resolved])
        ->assertSee(['Amount excl. VAT', '1.000,00'])
        ->assertDontSee('Incl. VAT')
        ->assertActionHasUrl(TestAction::make('view')->table($unpaid), DebtorCaseResource::getUrl('view', ['record' => $unpaid]))
        ->assertActionHasUrl(TestAction::make('whatsApp')->table($unpaid), $unpaid->whatsAppUrl(Debtors::whatsAppMessage($unpaid)));

    expect(Debtors::whatsAppMessage($unpaid))
        ->toStartWith('Hoi Angelo, we hebben de betaling van factuur ESKOOL202602188 (€ 2.176,16) nog niet ontvangen.')
        ->toContain($unpaid->payment_url)
        ->and(Debtors::whatsAppMessage($chargedBack))->toContain('is helaas gestorneerd');
});

it('shows the charged back payments in their own tab', function () {
    $unpaid = DebtorCase::factory()->create();
    $chargedBack = DebtorCase::factory()->chargedBack()->create();

    Livewire::test(Debtors::class)
        ->set('activeTab', 'charged_back')
        ->assertCanSeeTableRecords([$chargedBack])
        ->assertCanNotSeeTableRecords([$unpaid])
        ->assertSee('Gestorneerde betalingen');
});

it('syncs from the page', function () {
    fakePlugAndPayOrders([plugAndPayOrder(444, 'open')], []);

    Livewire::test(Debtors::class)
        ->callAction(TestAction::make('sync')->table())
        ->assertNotified('1 open debtor cases');

    expect(DebtorCase::count())->toBe(1);
});

it('records the timeline when a case opens, is charged back, resolves and reopens', function () {
    $page = fn (array $orders): array => ['data' => $orders, 'meta' => ['last_page' => 1]];
    Http::fake([
        'api.plugandpay.com/v2/orders?*payment_status=open*' => Http::sequence()
            ->push($page([plugAndPayOrder(555, 'open')]))
            ->whenEmpty(Http::response($page([]))),
        'api.plugandpay.com/v2/orders?*payment_status=reversed*' => Http::sequence()
            ->push($page([]))
            ->push($page([plugAndPayOrder(555, 'reversed')]))
            ->push($page([]))
            ->push($page([plugAndPayOrder(555, 'reversed')])),
    ]);

    foreach (range(1, 4) as $sync) {
        app(DebtorSync::class)->sync();
    }

    expect(DebtorCase::sole()->events()->orderBy('id')->pluck('type')->all())->toBe([
        DebtorCaseEvent::Opened,
        DebtorCaseEvent::ChargedBack,
        DebtorCaseEvent::Resolved,
        DebtorCaseEvent::Reopened,
    ])
        ->and(DebtorCase::sole()->events()->first()->causer())->toBe('Plug&Pay sync');
});

it('shows the dossier with its timeline', function () {
    $case = DebtorCase::factory()->create(['customer_name' => 'Angelo Dap', 'invoice_number' => 'ESKOOL202602188']);
    $case->events()->create(['type' => DebtorCaseEvent::Opened, 'title' => 'Invoice unpaid, case opened', 'created_at' => now()->subDay()]);
    DebtorCaseEvent::factory()->for($case)->create(['user_name' => 'Gairo Juliana', 'body' => '<p>Gebeld, <strong>betaalt vrijdag</strong>.</p><script>alert(1)</script>']);

    $this->get(DebtorCaseResource::getUrl('view', ['record' => $case]))
        ->assertOk()
        ->assertSee(['Angelo Dap', 'ESKOOL202602188', 'Invoice unpaid, case opened', 'Gairo Juliana'])
        ->assertSee('<strong>betaalt vrijdag</strong>', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('adds a rich text note with who wrote it', function () {
    actingAs(User::factory()->create(['name' => 'Flavio']));
    $case = DebtorCase::factory()->create();

    Livewire::test(ViewDebtorCase::class, ['record' => $case->getKey()])
        ->callAction(TestAction::make('addNote')->schemaComponent('timeline_section', schema: 'infolist'), ['body' => '<p>Gebeld, betaalt <strong>vrijdag</strong>.</p>'])
        ->assertHasNoFormErrors()
        ->assertNotified('Note saved');

    expect($case->events()->sole())
        ->type->toBe(DebtorCaseEvent::Note)
        ->user_name->toBe('Flavio')
        ->body->toContain('<strong>vrijdag</strong>');
});

it('keeps viewers out of the dossiers', function () {
    actingAs(User::factory()->viewer()->create());

    $this->get(DebtorCaseResource::getUrl('view', ['record' => DebtorCase::factory()->create()]))->assertForbidden();
});
