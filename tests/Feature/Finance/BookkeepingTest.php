<?php

use App\Enums\Finance\CategoryKind;
use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Order;
use App\Models\Finance\RevenueStream;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Finance\BankImporter;
use App\Services\Finance\Bookkeeping;
use App\Services\Finance\OrderSync;
use App\Services\Finance\ProfitAndLoss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->ads = Category::factory()->under()->create(['name' => 'Adspend Google', 'default_vat_rate' => 0.21]);
    $this->transfers = Category::factory()->under(Category::factory()->create(['kind' => CategoryKind::Transfer]))->create(['name' => 'Payment provider uitbetalingen', 'kind' => CategoryKind::Transfer]);
});

it('imports a bank export, categorizes it with the rules and skips lines already imported', function () {
    Rule::factory()->create(['name' => 'Google', 'conditions' => [['field' => 'counterparty', 'op' => 'contains', 'value' => 'google']], 'action' => ['categoryId' => $this->ads->id]]);
    Rule::factory()->create(['name' => 'Mollie', 'conditions' => [['field' => 'direction', 'op' => 'equals', 'value' => 'in'], ['field' => 'counterparty', 'op' => 'contains', 'value' => 'mollie']], 'action' => ['categoryId' => $this->transfers->id]]);
    $csv = file_get_contents(base_path('tests/Fixtures/finance/ing-nl.csv'));

    $preview = app(BankImporter::class)->preview($this->account, $csv);
    expect($preview)->toMatchArray(['format' => 'ing-nl', 'total' => 5, 'new' => 5, 'duplicates' => 0, 'date_from' => '2026-09-01', 'date_to' => '2026-09-03', 'sum_in' => 432109]);

    $result = app(BankImporter::class)->commit($this->account, 'ing.csv', $csv);
    expect($result)->toMatchArray(['new' => 5, 'duplicates' => 0, 'auto' => 2]);

    $google = Transaction::firstWhere('counterparty_name', 'Google Ireland Limited');
    expect($google)
        ->status->toBe(TransactionStatus::Auto)
        ->category_id->toBe($this->ads->id)
        ->vat_rate->toBe(0.21)
        ->amount_ex_vat_cents->toBe(-103306)
        ->and(Transaction::firstWhere('counterparty_name', 'Stichting Mollie Payments')->vat_rate)->toBe(0.0)
        ->and(Rule::firstWhere('name', 'Google')->hit_count)->toBe(1);

    expect(app(BankImporter::class)->commit($this->account, 'ing.csv', $csv))->toMatchArray(['new' => 0, 'duplicates' => 5])
        ->and(Transaction::count())->toBe(5);
});

it('undoes an import with all its transactions', function () {
    $result = app(BankImporter::class)->commit($this->account, 'ing.csv', file_get_contents(base_path('tests/Fixtures/finance/ing-nl.csv')));

    app(BankImporter::class)->undo($result['import']);

    expect(Transaction::count())->toBe(0);
});

it('categorizes, confirms, excludes and resets lines', function () {
    $user = User::factory()->create();
    $line = Transaction::factory()->for($this->account)->create(['amount_cents' => -12100]);
    $bookkeeping = app(Bookkeeping::class);

    $bookkeeping->setCategory([$line->id], $this->ads, $user, note: 'Campagne oktober');
    expect($line->fresh())->status->toBe(TransactionStatus::Confirmed)->amount_ex_vat_cents->toBe(-10000)->note->toBe('Campagne oktober')->categorized_by->toBe($user->id);

    $bookkeeping->exclude([$line->id], $user);
    expect($line->fresh())->status->toBe(TransactionStatus::Excluded)->amount_ex_vat_cents->toBeNull();

    $bookkeeping->reset([$line->id]);
    expect($line->fresh())->status->toBe(TransactionStatus::Uncategorized)->category_id->toBeNull();
});

it('refuses a main group that has subcategories', function () {
    app(Bookkeeping::class)->setCategory([Transaction::factory()->for($this->account)->create()->id], $this->ads->parent, null);
})->throws(InvalidArgumentException::class, 'Kies een subcategorie');

it('splits a line over categories when the parts add up', function () {
    $line = Transaction::factory()->for($this->account)->create(['amount_cents' => -24200]);
    $other = Category::factory()->under($this->ads->parent)->create(['default_vat_rate' => 0.0]);

    app(Bookkeeping::class)->saveSplits($line, [
        ['category_id' => $this->ads->id, 'amount_cents' => -12100],
        ['category_id' => $other->id, 'amount_cents' => -12100],
    ], null);

    expect($line->fresh()->status)->toBe(TransactionStatus::Confirmed)
        ->and($line->splits()->pluck('amount_ex_vat_cents')->all())->toEqualCanonicalizing([-10000, -12100]);

    app(Bookkeeping::class)->saveSplits($line, [['category_id' => $this->ads->id, 'amount_cents' => -1], ['category_id' => $other->id, 'amount_cents' => -1]], null);
})->throws(InvalidArgumentException::class, 'De delen tellen op tot');

it('re-runs the rules over open lines but never over confirmed ones', function () {
    $open = Transaction::factory()->for($this->account)->create();
    $confirmed = Transaction::factory()->for($this->account)->create(['status' => TransactionStatus::Confirmed]);
    Rule::factory()->create(['conditions' => [['field' => 'counterparty', 'op' => 'contains', 'value' => 'google']], 'action' => ['categoryId' => $this->ads->id]]);

    expect(app(Bookkeeping::class)->applyRulesToOpenLines())->toBe(['changed' => 1, 'matched' => 1])
        ->and($open->fresh()->status)->toBe(TransactionStatus::Auto)
        ->and($confirmed->fresh()->category_id)->toBeNull();
});

it('syncs the paid Plug&Pay order lines and counts them as revenue per stream', function () {
    config(['services.plug_and_pay.api_key' => 'pnp-token']);
    Http::preventStrayRequests();
    Http::fake(['api.plugandpay.com/v2/orders*' => Http::response(['data' => [[
        'id' => 18668990, 'invoice_number' => 'ESKOOL202602731', 'invoice_date' => '2026-10-19',
        'payment' => ['status' => 'paid', 'paid_at' => '2026-10-19T12:36:38Z'],
        'billing' => ['contact' => ['email' => 'klant@example.com']],
        'items' => [
            ['id' => 1, 'product_id' => 321827, 'label' => 'Traject', 'quantity' => 1, 'amount' => '3500.00', 'amount_with_tax' => '4235.00'],
            ['id' => 2, 'product_id' => 999, 'label' => 'Event ticket', 'quantity' => 2, 'amount' => '100.00', 'amount_with_tax' => '121.00'],
        ],
    ]], 'meta' => ['last_page' => 1]])]);
    $stream = RevenueStream::firstOrCreate(['name' => 'Coaching sales']);
    Product::factory()->create(['plug_and_pay_product_id' => 321827, 'sku' => '888999', 'revenue_stream_id' => $stream->id]);

    expect(app(OrderSync::class)->sync())->toBe(['lines' => 2, 'new' => 2, 'updated' => 0])
        ->and(Order::firstWhere('external_id', '18668990#1'))
        ->sku->toBe('888999')->subtotal_ex_vat_cents->toBe(350000)->vat_cents->toBe(73500)->paid_on->toDateString()->toBe('2026-10-19')
        ->customer_email->toBe('klant@example.com');

    $pl = ProfitAndLoss::fromDatabase();
    expect($pl['revenue']['2026-10'])->toBe(360000)
        ->and(collect($pl['revenueStreams'])->firstWhere('id', (string) $stream->id)['byMonth']['2026-10'])->toBe(350000)
        ->and(collect($pl['revenueStreams'])->firstWhere('id', ProfitAndLoss::UnmappedStream)['byMonth']['2026-10'])->toBe(10000);
});

it('builds the costs from categorized lines on their P&L month', function () {
    Transaction::factory()->for($this->account)->create(['category_id' => $this->ads->id, 'status' => TransactionStatus::Confirmed, 'amount_cents' => -12100, 'amount_ex_vat_cents' => -10000, 'booked_on' => '2026-10-02', 'period_on' => '2026-09-01']);
    Transaction::factory()->for($this->account)->create(['category_id' => $this->ads->id, 'status' => TransactionStatus::Uncategorized, 'amount_cents' => -500]);

    expect(ProfitAndLoss::fromDatabase()['costs'])->toBe(['2026-09' => 10000]);
});
