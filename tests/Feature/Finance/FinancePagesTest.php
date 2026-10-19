<?php

use App\Enums\Finance\TransactionStatus;
use App\Filament\Pages\Finance\FinanceDashboard;
use App\Filament\Pages\Finance\Inbox;
use App\Filament\Pages\Finance\Manual;
use App\Filament\Pages\Finance\RevenueStreams;
use App\Filament\Resources\Finance\Accounts\AccountResource;
use App\Filament\Resources\Finance\Categories\CategoryResource;
use App\Filament\Resources\Finance\Categories\Pages\ManageCategories;
use App\Filament\Resources\Finance\Rules\Pages\ManageRules;
use App\Filament\Resources\Finance\Rules\RuleResource;
use App\Filament\Resources\Finance\Transactions\Pages\ListTransactions;
use App\Filament\Widgets\Finance\FinanceNotice;
use App\Filament\Widgets\Finance\FinanceStats;
use App\Filament\Widgets\Finance\ProfitAndLossTable;
use App\Filament\Widgets\Finance\RevenueByStreamChart;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Import;
use App\Models\Finance\Order;
use App\Models\Finance\RevenueStream;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Finance\ProfitAndLoss;
use App\Services\ProductSync;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(fn () => actingAs(User::factory()->editor()->create()));

it('opens the finance pages for editors', function (string $url) {
    get($url)->assertOk();
})->with([
    'accounts' => fn () => AccountResource::getUrl(),
    'categories' => fn () => CategoryResource::getUrl(),
    'rules' => fn () => RuleResource::getUrl(),
]);

it('keeps viewers out of finance', function () {
    actingAs(User::factory()->viewer()->create());

    get(RuleResource::getUrl())->assertForbidden();
});

it('lists the main groups with their subcategories', function () {
    Livewire::test(ManageCategories::class)
        ->assertSee(['Marketing', 'Adspend Google', 'Interne overboekingen', 'TR-PSP']);
});

it('adds a subcategory under a main group', function () {
    $marketing = Category::firstWhere('name', 'Marketing');

    Livewire::test(ManageCategories::class)
        ->callAction(TestAction::make('addSubcategory')->arguments(['group' => $marketing->id]), ['name' => 'TikTok ads', 'code' => '112', 'kind' => 'expense', 'default_vat_rate' => '0.21'])
        ->assertHasNoFormErrors();

    expect(Category::firstWhere('code', '112'))->parent_id->toBe($marketing->id)->default_vat_rate->toBe(0.21);
});

it('edits and archives a category', function () {
    $category = Category::firstWhere('code', '104');

    Livewire::test(ManageCategories::class)
        ->callAction(TestAction::make('edit')->arguments(['category' => $category->id]), ['name' => 'Marketing overig'])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('archive')->arguments(['category' => $category->id]));

    expect($category->fresh())->name->toBe('Marketing overig')->archived->toBeTrue();

    Livewire::test(ManageCategories::class)->assertDontSee('Marketing overig')->set('showArchived', true)->assertSee('Marketing overig');
});

it('refuses to delete a category with transactions', function () {
    $category = Category::firstWhere('code', '100');
    Transaction::factory()->create(['category_id' => $category->id]);

    Livewire::test(ManageCategories::class)
        ->callAction(TestAction::make('delete')->arguments(['category' => $category->id]))
        ->assertNotified('Kan niet verwijderen');

    expect($category->fresh())->not->toBeNull();
});

it('creates a rule with conditions in euros and applies it to open lines', function () {
    $transaction = Transaction::factory()->create(['counterparty_name' => 'Google Ireland Limited', 'amount_cents' => -12100]);
    $ads = Category::firstWhere('code', '100');

    Livewire::test(ManageRules::class)
        ->callAction('create', [
            'name' => 'Google Ads',
            'conditions' => [
                ['field' => 'counterparty', 'op' => 'contains', 'value' => 'google'],
                ['field' => 'amount', 'op' => 'gte', 'amount' => '100'],
            ],
            'outcome' => 'categorize',
            'category_id' => $ads->id,
            'vat_rate' => '',
            'apply_now' => true,
        ])
        ->assertHasNoFormErrors();

    expect(Rule::firstWhere('name', 'Google Ads'))
        ->conditions->toBe([['field' => 'counterparty', 'op' => 'contains', 'value' => 'google'], ['field' => 'amount', 'op' => 'gte', 'value' => 10000]])
        ->action->toBe(['categoryId' => $ads->id])
        ->and($transaction->fresh()->category_id)->toBe($ads->id);
});

it('re-runs the rules from the rules page', function () {
    Livewire::test(ManageRules::class)
        ->callAction('rerun')
        ->assertNotified();
});

it('lists transactions and categorizes one inline', function () {
    $transaction = Transaction::factory()->create(['amount_cents' => -12100]);
    $ads = Category::firstWhere('code', '100');

    Livewire::test(ListTransactions::class)
        ->assertCanSeeTableRecords([$transaction])
        ->call('updateTableColumnState', 'category_id', (string) $transaction->id, (string) $ads->id);

    expect($transaction->fresh())->category_id->toBe($ads->id)->status->toBe(TransactionStatus::Confirmed)->amount_ex_vat_cents->toBe(-10000);
});

it('shows open lines in the inbox with a suggestion from history and accepts it', function () {
    $ads = Category::firstWhere('code', '100');
    Transaction::factory()->count(2)->create(['status' => TransactionStatus::Confirmed, 'category_id' => $ads->id]);
    $open = Transaction::factory()->create();
    $done = Transaction::factory()->create(['status' => TransactionStatus::Confirmed, 'category_id' => $ads->id]);

    Livewire::test(Inbox::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$done])
        ->assertSee(['Nog niet compleet', '1 bankregel wacht op een beslissing', 'suggestie: 3 van 3 keer eerder zo geboekt'])
        ->callAction(TestAction::make('accept')->table($open));

    expect($open->fresh())->category_id->toBe($ads->id)->status->toBe(TransactionStatus::Confirmed);

    Livewire::test(Inbox::class)->assertDontSee('Nog niet compleet');
});

it('creates a rule from an inbox line', function () {
    $open = Transaction::factory()->create(['counterparty_name' => 'Andro commerce', 'amount_cents' => -240000]);
    $coaches = Category::firstWhere('code', '301');

    Livewire::test(Inbox::class)
        ->callAction(TestAction::make('createRule')->table($open), ['name' => 'Andro commerce', 'value' => 'Andro commerce', 'category_id' => $coaches->id, 'vat_rate' => ''])
        ->assertHasNoFormErrors();

    expect(Rule::firstWhere('source', 'inbox'))->name->toBe('Andro commerce → Delivery coaches')
        ->and($open->fresh()->category_id)->toBe($coaches->id);
});

it('imports a bank export from the import page and undoes it', function () {
    $account = Account::firstWhere('name', 'ING zakelijk');
    $file = UploadedFile::fake()->createWithContent('ing.csv', file_get_contents(base_path('tests/Fixtures/finance/ing-nl.csv')));

    Livewire::test(App\Filament\Pages\Finance\Import::class)
        ->set('data.account_id', $account->id)
        ->set('data.file', $file)
        ->assertSee('Al geïmporteerd')
        ->call('commit')
        ->assertNotified('5 transacties geïmporteerd');

    expect(Transaction::count())->toBe(5);

    Livewire::test(App\Filament\Pages\Finance\Import::class)
        ->callAction(TestAction::make('undo')->table(Import::sole()));

    expect(Transaction::count())->toBe(0);
});

it('shows the finance dashboard with tiles, charts and the P&L', function () {
    $account = Account::firstWhere('name', 'ING zakelijk');
    Transaction::factory()->for($account)->create(['category_id' => Category::firstWhere('code', '100')->id, 'status' => TransactionStatus::Confirmed, 'amount_cents' => -12100, 'amount_ex_vat_cents' => -10000, 'booked_on' => now()->startOfMonth()]);
    Order::create(['external_id' => '1#1', 'plug_and_pay_order_id' => 1, 'order_ref' => 'ESKOOL1', 'subtotal_ex_vat_cents' => 50000, 'status' => 'paid', 'paid_on' => now()->startOfMonth()]);

    get(FinanceDashboard::getUrl())->assertOk()->assertSee('Finance dashboard');

    $filters = ['year' => now()->year, 'period' => 'ytd', 'compare' => true];

    Livewire::test(FinanceStats::class, ['pageFilters' => $filters])
        ->assertSee(['Omzet (betaald)', '€ 500', '€ 100', '€ 400', '80,0%']);

    Livewire::test(ProfitAndLossTable::class, ['pageFilters' => $filters])
        ->assertSee(['Omzet', 'Nog niet ingedeeld', 'Marketing', 'Adspend Google', 'Operationele winst'])
        ->call('mountAction', 'drilldown', ['category' => Category::firstWhere('code', '100')->id, 'from' => now()->format('Y-m'), 'to' => now()->format('Y-m')])
        ->assertActionMounted('drilldown');

    Livewire::test(ProfitAndLossTable::class, ['pageFilters' => $filters])
        ->assertDontSee('Google Ireland Limited')
        ->call('toggleLine', Category::firstWhere('code', '100')->id)
        ->assertSee(['Google Ireland Limited', '€ 100,00'])
        ->call('toggleLine', Category::firstWhere('code', '100')->id)
        ->assertDontSee('Google Ireland Limited')
        ->call('toggleStream', ProfitAndLoss::UnmappedStream)
        ->assertSee(['1 orderregel', '€ 500,00']);

    $drilldown = (fn () => $this->drilldownHtml(Category::firstWhere('code', '100')->id, now()->format('Y-m'), now()->format('Y-m')))->call(new ProfitAndLossTable)->toHtml();
    expect($drilldown)->toContain('Google Ireland Limited')->toContain('€ 100,00');

    Livewire::test(RevenueByStreamChart::class, ['pageFilters' => $filters])->assertOk();
    Livewire::test(FinanceNotice::class, ['pageFilters' => $filters])->assertSee('omzet van producten zonder omzetstroom');
});

it('links products to a revenue stream', function () {
    $product = Product::factory()->create(['sku' => '123456']);
    Order::create(['external_id' => '2#1', 'plug_and_pay_order_id' => 2, 'order_ref' => 'ESKOOL2', 'plug_and_pay_product_id' => $product->plug_and_pay_product_id, 'subtotal_ex_vat_cents' => 10000, 'status' => 'paid', 'paid_on' => now()]);
    $stream = RevenueStream::firstWhere('name', 'Event sales');

    Livewire::test(RevenueStreams::class)
        ->assertCanSeeTableRecords([$product])
        ->assertSee(['Event sales', 'Nog niet ingedeeld'])
        ->call('updateTableColumnState', 'revenue_stream_id', (string) $product->id, (string) $stream->id);

    expect($product->fresh()->revenue_stream_id)->toBe($stream->id);
});

it('shows the manual', function () {
    get(Manual::getUrl())->assertOk()->assertSee(['Hoe de app rekent', 'Bankexport importeren', 'Sneltoetsen']);
});

it('warns about products without a revenue stream and duplicate SKUs, and links all products of a SKU at once', function () {
    $a = Product::factory()->create(['sku' => '777777', 'title' => 'Traject A']);
    $b = Product::factory()->create(['sku' => '777777', 'title' => 'Traject B']);
    Order::create(['external_id' => '3#1', 'plug_and_pay_order_id' => 3, 'order_ref' => 'ESKOOL3', 'plug_and_pay_product_id' => $a->plug_and_pay_product_id, 'subtotal_ex_vat_cents' => 10000, 'status' => 'paid', 'paid_on' => now()]);
    $stream = RevenueStream::firstWhere('name', 'Coaching sales');

    expect(RevenueStreams::getNavigationBadge())->toBe('1');

    Livewire::test(RevenueStreams::class)
        ->assertSee(['nieuw product zonder omzetstroom', 'Dubbele SKU 777777'])
        ->call('updateTableColumnState', 'revenue_stream_id', (string) $a->id, (string) $stream->id);

    expect($a->fresh()->revenue_stream_id)->toBe($stream->id)
        ->and($b->fresh()->revenue_stream_id)->toBe($stream->id)
        ->and(RevenueStreams::getNavigationBadge())->toBeNull();
});

it('gives new products the revenue stream of their known SKU and keeps deleted products', function () {
    config(['services.plug_and_pay.api_key' => 'pnp-token']);
    Http::fake([
        'api.plugandpay.com/v2/products?*is_deleted=1*' => Http::response(['data' => [['id' => 201373, 'title' => 'Aanbetaling €1', 'sku' => '615803', 'deleted_at' => '2025-01-01T00:00:00Z']], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/products*' => Http::response(['data' => [['id' => 321827, 'title' => 'VCNO - HRM', 'sku' => '888999']], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/orders*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]]),
    ]);

    app(ProductSync::class)->sync();

    expect(Product::firstWhere('sku', '888999')->revenueStream->name)->toBe('Coaching sales')
        ->and(Product::firstWhere('sku', '615803'))->revenueStream->name->toBe('Overig / Intern')->deleted_in_plug_and_pay_at->not->toBeNull();
});

it('changes the VAT of an open line in the inbox and keeps it when the line gets a category', function () {
    $line = Transaction::factory()->for(Account::firstWhere('name', 'ING zakelijk'))->create(['status' => TransactionStatus::Uncategorized, 'category_id' => null, 'amount_cents' => -10900]);

    Livewire::test(Inbox::class)
        ->call('updateTableColumnState', 'vat_rate', (string) $line->id, '0.09')
        ->call('updateTableColumnState', 'category_id', (string) $line->id, (string) Category::firstWhere('code', '100')->id);

    expect($line->fresh())
        ->status->toBe(TransactionStatus::Confirmed)
        ->vat_rate->toBe(0.09)
        ->amount_ex_vat_cents->toBe(-10000);
});

it('explains categories on hover: on the categories page, in the P&L and at a transaction', function () {
    $ads = Category::firstWhere('code', '100');

    Livewire::test(ManageCategories::class)
        ->callAction(TestAction::make('edit')->arguments(['category' => $ads->id]), ['description' => 'Alleen Google Ads, telt mee in de ROAS.'])
        ->assertHasNoFormErrors()
        ->assertSee('Alleen Google Ads, telt mee in de ROAS.');

    expect($ads->fresh()->description)->toBe('Alleen Google Ads, telt mee in de ROAS.');

    $line = Transaction::factory()->create(['category_id' => $ads->id, 'status' => TransactionStatus::Confirmed, 'amount_cents' => -12100, 'amount_ex_vat_cents' => -10000, 'booked_on' => now()->startOfMonth()]);

    Livewire::test(ProfitAndLossTable::class, ['pageFilters' => ['year' => now()->year, 'period' => 'ytd', 'compare' => false]])
        ->assertSee('Alleen Google Ads, telt mee in de ROAS.');

    Livewire::test(ListTransactions::class)
        ->assertCanSeeTableRecords([$line])
        ->assertSee('Alleen Google Ads, telt mee in de ROAS.');
});
