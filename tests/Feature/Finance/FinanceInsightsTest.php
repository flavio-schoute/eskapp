<?php

use App\Enums\Finance\AccountType;
use App\Enums\Finance\TransactionStatus;
use App\Filament\Pages\Finance\Signals as SignalsPage;
use App\Filament\Resources\Finance\Transactions\Pages\ListTransactions;
use App\Filament\Resources\SlackMembers\Pages\ManageSlackMembers;
use App\Filament\Widgets\Finance\FinanceStats;
use App\Filament\Widgets\Finance\StreamMarginTable;
use App\Models\Finance\Account;
use App\Models\Finance\Alert;
use App\Models\Finance\Category;
use App\Models\Finance\Order;
use App\Models\Finance\RevenueStream;
use App\Models\Finance\Transaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Finance\Cashflow;
use App\Services\Finance\FinanceReport;
use App\Services\Finance\SignalNotifier;
use App\Services\Finance\Signals;
use App\Support\FinanceAlerts;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->editor()->create());
    $this->travelTo('2026-10-19 10:00');
    $this->account = Account::factory()->create();
    FinanceReport::flush();
});

// The P&L is kept per request; don't let it leak into the next test file.
afterEach(fn () => FinanceReport::flush());

/**
 * @param  array<string, mixed>  $attributes
 */
function payment(string $date, int $cents, string $name, array $attributes = []): Transaction
{
    return Transaction::factory()->for(test()->account)->create(['booked_on' => $date, 'amount_cents' => -$cents, 'counterparty_name' => $name, ...$attributes]);
}

it('finds subscriptions, a price increase and a new subscription', function () {
    payment('2025-12-01', 5000, 'Oude leverancier');
    foreach (['2026-06-03', '2026-07-03', '2026-08-03', '2026-09-03'] as $date) {
        payment($date, 1000, 'NOTION LABS *A1B2');
    }
    payment('2026-10-03', 1200, 'NOTION LABS *Z9Y8');
    foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $date) {
        payment($date, 4900, 'Fathom.video');
    }
    payment('2026-10-05', 25000, 'Eenmalig BV');
    $ads = Category::firstWhere('is_ad_spend', true);
    foreach (['2026-08-01', '2026-09-01', '2026-10-01'] as $date) {
        payment($date, 18000, 'FACEBK *XYZ1', ['category_id' => $ads->id, 'status' => TransactionStatus::Confirmed]);
    }

    $subscriptions = collect(Signals::subscriptions())->keyBy('key');

    expect($subscriptions->keys()->all())->toEqualCanonicalizing(['notion labs', 'fathom video']);
    expect($subscriptions['notion labs'])->toMatchArray(['typical' => 1000, 'last' => 1200, 'previous' => 1000, 'priceUp' => true, 'isNew' => false, 'yearly' => 12000]);
    expect($subscriptions['fathom video'])->toMatchArray(['typical' => 4900, 'priceUp' => false, 'isNew' => true]);
});

it('flags possible duplicate and unusually high payments', function () {
    $first = payment('2026-10-01', 45000, 'Smid online marketing');
    $second = payment('2026-10-04', 45000, 'Smid online marketing');
    foreach (['2026-06-01', '2026-07-01', '2026-08-01'] as $date) {
        payment($date, 10000, 'Andro commerce');
    }
    $high = payment('2026-10-10', 90000, 'Andro commerce');

    $anomalies = collect(Signals::anomalies())->keyBy('key');

    expect($anomalies->keys()->all())->toEqualCanonicalizing(["duplicate:{$first->id}-{$second->id}", "unusual:{$high->id}"])
        ->and($anomalies["unusual:{$high->id}"]['message'])->toContain('€ 900,00')->toContain('normaal € 100,00');
});

it('forecasts the balance from the last three complete months', function () {
    foreach (['2026-06-30' => 100000, '2026-07-31' => 110000, '2026-08-31' => 120000, '2026-09-30' => 130000, '2026-10-15' => 135000] as $date => $balance) {
        Transaction::factory()->for($this->account)->create(['booked_on' => $date, 'balance_after_cents' => $balance]);
    }

    $cashflow = Cashflow::build();

    expect($cashflow)->toMatchArray(['balance' => 135000, 'balanceOn' => '2026-10-15', 'monthlyFlow' => 10000])
        ->and($cashflow['forecast'])->toBe(['2026-11' => 145000, '2026-12' => 155000, '2027-01' => 165000]);
});

it('shows the margin per stream and the ROAS on the dashboard', function () {
    $stream = RevenueStream::firstOrCreate(['name' => 'Event sales']);
    $product = Product::factory()->create(['revenue_stream_id' => $stream->id]);
    Order::create(['external_id' => '1#1', 'plug_and_pay_order_id' => 1, 'order_ref' => 'ESKOOL1', 'plug_and_pay_product_id' => $product->plug_and_pay_product_id, 'subtotal_ex_vat_cents' => 100000, 'status' => 'paid', 'paid_on' => '2026-10-02']);
    $delivery = Category::firstWhere('name', 'Delivery event-sales');
    $delivery->update(['revenue_stream_id' => $stream->id]);
    payment('2026-10-03', 30000, 'Zaalhuur', ['category_id' => $delivery->id, 'status' => TransactionStatus::Confirmed, 'amount_ex_vat_cents' => -30000]);
    payment('2026-10-04', 25000, 'Facebook', ['category_id' => Category::firstWhere('is_ad_spend', true)->id, 'status' => TransactionStatus::Confirmed, 'amount_ex_vat_cents' => -25000]);
    $filters = ['year' => 2026, 'period' => 'm10', 'compare' => false];

    expect(collect((new FinanceReport($filters))->streamMargins())->firstWhere('name', 'Event sales'))
        ->toMatchArray(['revenue' => 100000, 'costs' => 30000, 'margin' => 70000, 'ratio' => 0.7]);

    Livewire::test(StreamMarginTable::class, ['pageFilters' => $filters])->assertSee(['Marge per omzetstroom', 'Event sales', '€ 700', '70,0%']);
    Livewire::test(FinanceStats::class, ['pageFilters' => $filters])->assertSee(['ROAS', '4,0×', 'adspend € 250']);
});

it('posts new signals once in the finance Slack channel, and dismissed ones stay away', function () {
    config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test']);
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);
    $marketing = Category::firstWhere('name', 'Adspend Google');
    $marketing->update(['monthly_budget_cents' => 10000]);
    payment('2026-10-02', 12100, 'Google', ['category_id' => $marketing->id, 'status' => TransactionStatus::Confirmed, 'amount_ex_vat_cents' => -15000]);
    payment('2026-10-01', 45000, 'Smid online marketing');
    payment('2026-10-04', 45000, 'Smid online marketing');

    expect(app(SignalNotifier::class)->run())->toBe(2);

    Http::assertSent(fn (Request $request): bool => $request['channel'] === 'C09GF1F3XTQ'
        && str_contains($request['text'], 'Budget overschreden: *Marketing › Adspend Google* staat deze maand op € 150 van € 100')
        && str_contains($request['text'], 'Mogelijk dubbel betaald: € 450,00 aan *Smid online marketing*'));

    expect(app(SignalNotifier::class)->run())->toBe(0);
    Http::assertSentCount(1);

    $duplicate = Alert::firstWhere('type', 'duplicate');
    Livewire::test(SignalsPage::class)
        ->assertSee(['Mogelijk dubbel betaald', 'Adspend Google'])
        ->call('dismiss', $duplicate->key, $duplicate->type, $duplicate->message)
        ->assertDontSee('Mogelijk dubbel betaald');

    get(SignalsPage::getUrl())->assertOk();
});

it('changes the finance alert messages and sends a test message from the Slack settings', function () {
    actingAs(User::factory()->create());
    config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test']);
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);

    Livewire::test(ManageSlackMembers::class)
        ->mountAction('financeAlerts')
        ->fillForm(['messages.duplicate' => 'Dubbel? {bedrag} aan {partij}'])
        ->callAction(TestAction::make('sendFinanceAlertTest'))
        ->assertNotified('Testbericht verstuurd')
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Finance-meldingen opgeslagen');

    Http::assertSent(fn (Request $request): bool => $request['channel'] === 'C09GF1F3XTQ'
        && str_contains($request['text'], 'Testbericht')
        && str_contains($request['text'], '• Dubbel? € 450,00 aan Smid online marketing')
        && str_contains($request['text'], 'Budget overschreden'));

    expect(FinanceAlerts::render('duplicate', ['{bedrag}' => '€ 5', '{partij}' => 'X']))->toBe('Dubbel? € 5 aan X');
});

it('adds an invoice by hand so it counts in the costs, and deletes it', function () {
    $category = Category::firstWhere('code', '400');

    Livewire::test(ListTransactions::class)
        ->callAction('addInvoice', [
            'booked_on' => '2026-10-01', 'counterparty_name' => 'Mollie', 'description' => 'MOL-NL-R2026.0001379748',
            'direction' => 'out', 'amount' => '121', 'vat_rate' => '0.21', 'category_id' => $category->id,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Factuur toegevoegd');

    expect(Transaction::sole()->account->type)->toBe(AccountType::Manual)
        ->and(Transaction::sole())->amount_cents->toBe(-12100)->amount_ex_vat_cents->toBe(-10000)
        ->status->toBe(TransactionStatus::Confirmed);

    Livewire::test(ListTransactions::class)
        ->callAction(TestAction::make('deleteManual')->table(Transaction::sole()))
        ->assertNotified('Factuur verwijderd');

    expect(Transaction::count())->toBe(0);
});

it('adds an invoice by hand with the exact VAT amount from the invoice', function () {
    Livewire::test(ListTransactions::class)
        ->callAction('addInvoice', [
            'booked_on' => '2026-10-01', 'counterparty_name' => 'Mollie', 'direction' => 'out', 'amount' => '250',
            'vat_rate' => 'amount', 'vat_amount' => '10.50', 'category_id' => Category::firstWhere('code', '400')->id,
        ])
        ->assertHasNoFormErrors();

    expect(Transaction::sole())->amount_cents->toBe(-25000)->amount_ex_vat_cents->toBe(-23950)->vat_rate->toBe(0.044);
});
