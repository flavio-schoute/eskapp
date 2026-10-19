<?php

use App\Enums\Finance\TransactionStatus;
use App\Filament\Pages\Finance\Signals as SignalsPage;
use App\Filament\Resources\Finance\Transactions\Pages\ListTransactions;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Order;
use App\Models\Finance\Transaction;
use App\Models\User;
use App\Services\Finance\PaymentMatcher;
use App\Services\Finance\SignalNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->editor()->create());
    $this->travelTo('2026-10-19 10:00');
    $this->account = Account::factory()->create();
    $this->customerPayment = Category::firstWhere('code', PaymentMatcher::CategoryCode);
});

/**
 * One Plug&Pay order with a line incl. VAT.
 */
function financeOrder(int $orderId, string $ref, int $totalCents, string $status = 'open', string $invoicedOn = '2026-10-05'): void
{
    Order::create(['external_id' => "{$orderId}#1", 'plug_and_pay_order_id' => $orderId, 'order_ref' => $ref, 'subtotal_ex_vat_cents' => (int) round($totalCents / 1.21), 'total_cents' => $totalCents, 'status' => $status, 'invoiced_on' => $invoicedOn, 'paid_on' => $status === 'paid' ? '2026-10-10' : null]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function bankPayment(int $cents, string $description, array $attributes = []): Transaction
{
    return Transaction::factory()->for(test()->account)->create(['booked_on' => '2026-10-10', 'amount_cents' => $cents, 'counterparty_name' => 'L. de Vries', 'description' => $description, ...$attributes]);
}

it('links bank payments to their Plug&Pay order by invoice number, order number or a unique amount', function () {
    financeOrder(18210996, 'ESKOOL202602188', 179848);
    financeOrder(18210997, 'ESKOOL202602189', 49900, 'paid');
    financeOrder(18210998, 'ESKOOL202602190', 12100);

    $byInvoice = bankPayment(179848, 'Betaling factuur eskool202602188');
    $byOrderId = bankPayment(49900, 'Order 18210997');
    $byAmount = bankPayment(12100, 'Cursus');
    $unknown = bankPayment(777700, 'Terugbetaling leverancier');
    $payout = bankPayment(360258, 'Uitbetaling', ['counterparty_name' => 'Stichting Mollie Payments', 'category_id' => Category::firstWhere('code', 'TR-PSP')->id, 'status' => TransactionStatus::Auto]);

    expect(app(PaymentMatcher::class)->matchIncoming())->toBe(3);

    expect($byInvoice->fresh())->order_ref->toBe('ESKOOL202602188')->order_match->toBe('open')
        ->category_id->toBe($this->customerPayment->id)->status->toBe(TransactionStatus::Auto);
    expect($byOrderId->fresh())->order_ref->toBe('ESKOOL202602189')->order_match->toBe('paid');
    // Matched on amount only: shown, but not proposed for a category.
    expect($byAmount->fresh())->order_ref->toBe('ESKOOL202602190')->order_match->toBe('open')->category_id->toBeNull();
    expect($unknown->fresh())->order_ref->toBeNull()->order_match->toBe('none');
    expect($payout->fresh())->order_match->toBeNull();

    Order::query()->where('plug_and_pay_order_id', 18210996)->update(['status' => 'paid', 'paid_on' => '2026-10-12']);
    app(PaymentMatcher::class)->matchIncoming();

    expect($byInvoice->fresh()->order_match)->toBe('paid');
});

it('signals bank payments of open orders and customer payments without an order, and posts them to Slack', function () {
    config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test']);
    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true])]);
    financeOrder(18210996, 'ESKOOL202602188', 179848);
    bankPayment(179848, 'ESKOOL202602188');
    $withoutOrder = bankPayment(49900, 'Cursus', ['category_id' => $this->customerPayment->id, 'status' => TransactionStatus::Confirmed]);
    app(PaymentMatcher::class)->matchIncoming();

    expect(app(SignalNotifier::class)->run())->toBe(2);

    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'Betaald per bank maar open in Plug & Pay: € 1.798,48 van *L. de Vries* op 2026-10-10 hoort bij ESKOOL202602188')
        && str_contains($request['text'], 'Klantbetaling zonder Plug & Pay-order: € 499,00'));

    Livewire::test(SignalsPage::class)
        ->assertSee(['Order open', 'Geen order', 'ESKOOL202602188'])
        ->call('dismiss', "payment-unmatched:{$withoutOrder->id}", 'payment_unmatched', 'x')
        ->assertDontSee('Geen order</span>', false);
});

it('warns when a bank payment of an order is booked as revenue', function () {
    financeOrder(18210997, 'ESKOOL202602189', 49900, 'paid');
    $line = bankPayment(49900, 'ESKOOL202602189', ['status' => TransactionStatus::Confirmed, 'category_id' => Category::firstWhere('code', 'TR-OWN')->id]);
    $line->update(['category_id' => null, 'status' => TransactionStatus::Uncategorized]);
    app(PaymentMatcher::class)->matchIncoming();
    $revenue = Category::query()->where('kind', 'revenue')->whereNotNull('parent_id')->first();

    Livewire::test(ListTransactions::class)
        ->assertSee('ESKOOL202602189 betaald')
        ->call('updateTableColumnState', 'category_id', (string) $line->id, (string) $revenue->id)
        ->assertNotified('Let op: dubbele omzet');
});
