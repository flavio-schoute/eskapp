<?php

namespace App\Filament\Resources\Finance\Transactions;

use App\Enums\Finance\AccountType;
use App\Enums\Finance\CategoryKind;
use App\Enums\Finance\TransactionStatus;
use App\Filament\Concerns\FinanceOnly;
use App\Filament\Resources\Finance\Transactions\Pages\ListTransactions;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Transaction;
use App\Models\User;
use App\Services\Finance\Bookkeeping;
use App\Services\Finance\Money;
use App\Services\Finance\PaymentMatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Js;
use InvalidArgumentException;
use UnitEnum;

/**
 * All bank and card lines, with their category, status and amount excl. VAT.
 */
class TransactionResource extends Resource
{
    use FinanceOnly;

    protected static ?string $model = Transaction::class;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Transacties';

    protected static ?string $modelLabel = 'transactie';

    protected static ?string $pluralModelLabel = 'transacties';

    protected static ?string $slug = 'finance/transactions';

    /**
     * @var array<string, string>
     */
    public const array VatRates = ['0.21' => '21%', '0.09' => '9%', '0' => '0%'];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return static::configureTransactionTable($table)
            ->filters([
                SelectFilter::make('status')
                    ->options(TransactionStatus::class),
                SelectFilter::make('category_id')
                    ->label('Categorie')
                    ->options(fn (): array => Category::pickerOptions())
                    ->searchable(),
                SelectFilter::make('account_id')
                    ->label('Rekening')
                    ->options(fn (): array => Account::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('direction')
                    ->label('In & uit')
                    ->options(['in' => 'Inkomend', 'out' => 'Uitgaand'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'in' => $query->where('amount_cents', '>', 0),
                        'out' => $query->where('amount_cents', '<', 0),
                        default => $query,
                    }),
                Filter::make('booked_on')
                    ->schema([
                        DatePicker::make('from')->label('Vanaf'),
                        DatePicker::make('until')->label('Tot en met'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('booked_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('booked_on', '<=', $date))),
            ], layout: FiltersLayout::AboveContentCollapsible);
    }

    /**
     * The transaction table, shared with the inbox.
     */
    public static function configureTransactionTable(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['account:id,name', 'category:id,name,description', 'splits.category']))
            // Always also sort on the ID, so a line never jumps to another place after it was categorized.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('booked_on')->orderByDesc('id'))
            ->paginated([25, 50, 100, 250])
            ->defaultPaginationPageOption(100)
            ->columns([
                TextColumn::make('booked_on')
                    ->label('Datum')
                    ->date('Y-m-d')
                    ->description(fn (Transaction $record): ?string => $record->period_on ? 'W&V: '.$record->period_on->translatedFormat('M Y') : null)
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('counterparty_name')
                    ->label('Tegenpartij / omschrijving')
                    ->description(fn (Transaction $record): string => str($record->description)->limit(70).($record->note ? ' · 📝 '.$record->note : ''))
                    ->weight('medium')
                    ->copyable()
                    ->copyMessage('Tegenpartij gekopieerd')
                    ->searchable(['counterparty_name', 'description', 'counterparty_iban', 'note'])
                    ->wrap(),
                TextColumn::make('amount_cents')
                    ->label('Bedrag')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->copyable()
                    ->copyableState(fn (int $state): string => number_format($state / 100, 2, ',', ''))
                    ->copyMessage('Bedrag gekopieerd')
                    ->color(fn (int $state): ?string => $state > 0 ? 'success' : null)
                    ->description(fn (Transaction $record): string => $record->account->name ?? '')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('amount_ex_vat_cents')
                    ->label('Excl. btw')
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::format($state))
                    ->placeholder('-')
                    ->alignEnd()
                    ->toggleable(),
                SelectColumn::make('vat_rate')
                    ->label('Btw')
                    ->options(static::VatRates)
                    ->state(fn (Transaction $record): ?string => $record->vat_rate === null ? null : (string) $record->vat_rate)
                    ->placeholder('—')
                    ->selectablePlaceholder(false)
                    // Split lines have a rate per part; excluded lines don't count in the P&L.
                    ->disabled(fn (Transaction $record): bool => $record->splits->isNotEmpty() || $record->status === TransactionStatus::Excluded)
                    ->tooltip(fn (Transaction $record): ?string => $record->splits->isNotEmpty() ? 'Btw per deel: via Splitsen' : null)
                    ->updateStateUsing(function (?string $state, Transaction $record): ?string {
                        if ($state !== null && $state !== '') {
                            app(Bookkeeping::class)->setVatRate($record, (float) $state);
                        }

                        return $state;
                    })
                    ->width('6rem'),
                SelectColumn::make('category_id')
                    ->label('Categorie')
                    ->options(fn (): array => Category::pickerOptions())
                    ->placeholder(fn (Transaction $record): string => $record->splits->isNotEmpty() ? 'Gesplitst ('.$record->splits->count().' delen)' : 'Kies categorie…')
                    ->disabled(fn (Transaction $record): bool => $record->splits->isNotEmpty())
                    // The searchable select ignores tooltip(): show the category's explanation on the cell instead.
                    ->extraCellAttributes(fn (Transaction $record): array => filled($record->category?->description)
                        ? ['x-data' => '{}', 'x-tooltip' => '{ content: '.Js::from($record->category->description).', theme: $store.theme }']
                        : [])
                    ->searchableOptions()
                    ->updateStateUsing(function (?string $state, Transaction $record): ?string {
                        if (! $state) {
                            app(Bookkeeping::class)->reset([$record->getKey()]);

                            return null;
                        }

                        $category = Category::findOrFail($state);

                        try {
                            app(Bookkeeping::class)->setCategory([$record->getKey()], $category, static::user());
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }

                        static::warnAboutDoubleRevenue($category, array_filter([$record->order_ref]));

                        return $state;
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('order_match')
                    ->label('Plug & Pay')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, Transaction $record): ?string => match ($state) {
                        PaymentMatcher::Paid => "{$record->order_ref} betaald",
                        PaymentMatcher::Open => "{$record->order_ref} staat open",
                        PaymentMatcher::None => 'Geen order',
                        PaymentMatcher::Credited => "{$record->order_ref} gecrediteerd · telt niet mee",
                        default => null,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        PaymentMatcher::Paid => 'success',
                        PaymentMatcher::Open => 'warning',
                        PaymentMatcher::Credited => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (?string $state): ?Heroicon => match ($state) {
                        PaymentMatcher::Paid => Heroicon::OutlinedCheckCircle,
                        PaymentMatcher::Open => Heroicon::OutlinedExclamationTriangle,
                        PaymentMatcher::None => Heroicon::OutlinedQuestionMarkCircle,
                        PaymentMatcher::Credited => Heroicon::OutlinedReceiptRefund,
                        default => null,
                    })
                    ->tooltip(fn (?string $state): ?string => match ($state) {
                        PaymentMatcher::Paid => 'Deze bijschrijving hoort bij een betaalde Plug & Pay-order: de omzet telt al mee via de order. Boek hem op Klantbetaling per bank.',
                        PaymentMatcher::Open => 'Het geld is binnen, maar de order staat in Plug & Pay nog open. Zet de order op betaald, anders telt de omzet niet mee.',
                        PaymentMatcher::None => 'Geen Plug & Pay-order gevonden op factuurnummer of bedrag.',
                        PaymentMatcher::Credited => 'Terugbetaling van een factuur die in Plug & Pay helemaal is gecrediteerd. De creditering haalde de omzet al weg, dus deze terugbetaling telt niet nog een keer als kosten.',
                        default => null,
                    })
                    ->placeholder('')
                    ->toggleable(),
            ])
            ->recordActions([
                static::detailsAction(),
                static::splitAction(),
                static::deleteManualAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setCategory')
                        ->label('Categorie toewijzen')
                        ->icon(Heroicon::OutlinedTag)
                        ->schema([
                            static::categoryField(),
                            Select::make('vat_rate')->label('Btw')->options(['' => 'Standaard', '0.21' => '21%', '0.09' => '9%', '0' => '0%'])->default('')->selectablePlaceholder(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $category = Category::findOrFail($data['category_id']);

                            try {
                                $count = app(Bookkeeping::class)->setCategory($records->modelKeys(), $category, static::user(), $data['vat_rate'] === '' ? null : (float) $data['vat_rate']);
                                Notification::make()->success()->title("{$count} transacties gecategoriseerd")->send();
                            } catch (InvalidArgumentException $exception) {
                                Notification::make()->danger()->title($exception->getMessage())->send();

                                return;
                            }

                            static::warnAboutDoubleRevenue($category, $records->pluck('order_ref')->filter()->unique()->values()->all());
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('confirm')
                        ->label('Voorstel akkoord')
                        ->icon(Heroicon::OutlinedCheck)
                        ->action(function (Collection $records): void {
                            $count = app(Bookkeeping::class)->confirm($records->modelKeys(), static::user());
                            Notification::make()->success()->title("{$count} voorstellen bevestigd")->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('exclude')
                        ->label('Uitsluiten van de W&V')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $count = app(Bookkeeping::class)->exclude($records->modelKeys(), static::user());
                            Notification::make()->success()->title("{$count} transacties uitgesloten")->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('reset')
                        ->label('Terug naar de inbox')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $count = app(Bookkeeping::class)->reset($records->modelKeys());
                            Notification::make()->success()->title("{$count} transacties terug in de inbox")->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * VAT, the P&L month and a note for one line.
     */
    public static function detailsAction(): Action
    {
        return Action::make('details')
            ->label('Details')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->iconButton()
            ->tooltip('Btw, W&V-maand, notitie of uitsplitsen')
            ->color('gray')
            ->modalHeading(fn (Transaction $record): string => $record->counterparty_name ?: 'Transactie')
            ->modalDescription(fn (Transaction $record): string => $record->booked_on->format('Y-m-d').' · '.($record->account->name ?? '').' · klik op een waarde om te kopiëren')
            ->fillForm(fn (Transaction $record): array => [
                'vat_rate' => $record->vat_rate === null ? null : (string) $record->vat_rate,
                'period' => $record->period_on?->format('Y-m'),
                'note' => $record->note,
            ])
            ->schema([
                Grid::make(2)->schema([
                    TextEntry::make('counterparty_name')->label('Tegenpartij')->state(fn (Transaction $record): string => $record->counterparty_name)->copyable()->placeholder('-'),
                    TextEntry::make('counterparty_iban')->label('IBAN')->state(fn (Transaction $record): ?string => $record->counterparty_iban)->fontFamily('mono')->copyable()->placeholder('-'),
                    TextEntry::make('description')->label('Omschrijving')->state(fn (Transaction $record): string => $record->description)->copyable()->placeholder('-')->columnSpanFull(),
                    TextEntry::make('amount')->label('Bedrag')->state(fn (Transaction $record): string => Money::format($record->amount_cents))->copyable()->copyableState(fn (Transaction $record): string => number_format($record->amount_cents / 100, 2, ',', '')),
                    TextEntry::make('excl')->label('Excl. btw')->state(fn (Transaction $record): ?string => $record->amount_ex_vat_cents === null ? null : Money::format($record->amount_ex_vat_cents))->copyable()->placeholder('-'),
                ]),
                Grid::make(2)->schema([
                    Select::make('vat_rate')
                        ->label('Btw')
                        ->options(static::VatRates)
                        ->placeholder('—')
                        ->disabled(fn (Transaction $record): bool => $record->splits->isNotEmpty())
                        ->helperText(fn (Transaction $record): ?string => $record->splits->isNotEmpty() ? 'Gesplitst: btw per deel via Uitsplitsen.' : null),
                    Select::make('period')
                        ->label('Telt in de W&V in')
                        ->options(fn (Transaction $record): array => collect(range(-3, 3))->mapWithKeys(fn (int $offset): array => [
                            $record->booked_on->copy()->startOfMonth()->addMonthsNoOverflow($offset)->format('Y-m') => $record->booked_on->copy()->startOfMonth()->addMonthsNoOverflow($offset)->translatedFormat('F Y'),
                        ])->all())
                        ->placeholder('De bankdatum'),
                ]),
                TextInput::make('note')
                    ->label('Notitie')
                    ->maxLength(500),
            ])
            ->extraModalFooterActions([
                static::splitAction()->button()->label('Uitsplitsen')->cancelParentActions(),
            ])
            ->action(function (Transaction $record, array $data, Bookkeeping $bookkeeping): void {
                if ($record->splits->isEmpty() && filled($data['vat_rate'] ?? null) && (float) $data['vat_rate'] !== $record->vat_rate) {
                    $bookkeeping->setVatRate($record, (float) $data['vat_rate']);
                }

                $bookkeeping->setPeriod($record, $data['period'] ?? null);
                $record->update(['note' => filled($data['note'] ?? null) ? trim($data['note']) : null]);

                Notification::make()->success()->title('Opgeslagen')->send();
            });
    }

    /**
     * Split one line over several categories; the parts must add up to the bank amount.
     */
    public static function splitAction(): Action
    {
        return Action::make('split')
            ->label('Splitsen')
            ->icon(Heroicon::OutlinedScissors)
            ->iconButton()
            ->tooltip('Uitsplitsen over categorieën')
            ->color('gray')
            ->modalHeading(fn (Transaction $record): string => 'Splitsen · '.Money::format($record->amount_cents))
            ->modalDescription('Book parts of this line on different categories. The parts must add up exactly to the bank amount; remove all parts to undo the split.')
            ->fillForm(fn (Transaction $record): array => [
                'splits' => $record->splits->isNotEmpty()
                    ? $record->splits->map(fn ($split): array => ['category_id' => $split->category_id, 'amount' => $split->amount_cents / 100, 'vat_rate' => $split->vat_rate === null ? '' : (string) $split->vat_rate, 'note' => $split->note])->all()
                    : [['category_id' => $record->category_id, 'amount' => $record->amount_cents / 100, 'vat_rate' => '', 'note' => null], ['category_id' => null, 'amount' => null, 'vat_rate' => '', 'note' => null]],
            ])
            ->schema([
                Repeater::make('splits')
                    ->hiddenLabel()
                    ->addActionLabel('Deel toevoegen')
                    ->reorderable(false)
                    ->live()
                    ->table([
                        Repeater\TableColumn::make('Categorie'),
                        Repeater\TableColumn::make('Bedrag')->width('9rem'),
                        Repeater\TableColumn::make('Btw')->width('8rem'),
                        Repeater\TableColumn::make('Notitie'),
                    ])
                    ->schema([
                        Select::make('category_id')->hiddenLabel()->options(fn (): array => Category::pickerOptions())->searchable()->required(),
                        TextInput::make('amount')->hiddenLabel()->prefix('€')->numeric()->required(),
                        Select::make('vat_rate')->hiddenLabel()->options(['' => 'Standaard', ...static::VatRates])->default('')->selectablePlaceholder(false),
                        TextInput::make('note')->hiddenLabel()->maxLength(500),
                    ]),
                TextEntry::make('remaining')
                    ->hiddenLabel()
                    ->state(function (Get $get, Transaction $record): string {
                        $sum = collect($get('splits') ?? [])->sum(fn (array $split): int => is_numeric($split['amount'] ?? null) ? Money::round((float) $split['amount'] * 100) : 0);
                        $remaining = $record->amount_cents - $sum;

                        return $remaining === 0 ? '✓ De delen tellen precies op.' : 'Nog te verdelen: '.Money::format($remaining);
                    }),
            ])
            ->action(function (Transaction $record, array $data, Bookkeeping $bookkeeping, Action $action): void {
                $splits = collect($data['splits'] ?? [])->map(fn (array $split): array => [
                    'category_id' => (int) $split['category_id'],
                    'amount_cents' => Money::round((float) $split['amount'] * 100),
                    'vat_rate' => ($split['vat_rate'] ?? '') === '' ? null : (float) $split['vat_rate'],
                    'note' => $split['note'] ?? null,
                ])->values()->all();

                try {
                    $bookkeeping->saveSplits($record, $splits, static::user());
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title($splits === [] ? 'Splitsing verwijderd' : 'Gesplitst in '.count($splits).' delen')->send();
            });
    }

    /**
     * Add an invoice by hand, e.g. Mollie costs that are deducted from the payouts.
     */
    public static function addInvoiceAction(): Action
    {
        return Action::make('addInvoice')
            ->label('Factuur toevoegen')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->modalHeading('Factuur handmatig toevoegen')
            ->modalDescription('Voor kosten die niet als losse regel op de bank staan, zoals Mollie-facturen die op de uitbetalingen worden ingehouden. Telt mee in de W&V op de factuurdatum.')
            ->modalSubmitActionLabel('Toevoegen')
            ->modalCancelActionLabel('Annuleren')
            ->fillForm(fn (): array => [
                'booked_on' => now()->toDateString(),
                'direction' => 'out',
                'vat_rate' => '',
            ])
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('booked_on')->label('Factuurdatum')->required(),
                    TextInput::make('counterparty_name')->label('Leverancier')->placeholder('bijv. Mollie')->required()->maxLength(500),
                    TextInput::make('description')->label('Factuurnummer / omschrijving')->maxLength(500),
                    Select::make('direction')->label('Soort')->options(['out' => 'Kosten', 'in' => 'Inkomsten'])->selectablePlaceholder(false)->required(),
                    TextInput::make('amount')->label('Bedrag incl. btw')->prefix('€')->numeric()->minValue(0.01)->required(),
                    Select::make('vat_rate')->label('Btw')->options(['' => 'Standaard van de categorie', ...static::VatRates, 'amount' => 'Ander bedrag (zoals op de factuur)'])->default('')->selectablePlaceholder(false)->live(),
                    TextInput::make('vat_amount')
                        ->label('Btw-bedrag')
                        ->prefix('€')
                        ->numeric()
                        ->minValue(0)
                        ->lte('amount')
                        ->required()
                        ->visible(fn (Get $get): bool => $get('vat_rate') === 'amount')
                        ->helperText(fn (Get $get): ?string => is_numeric($get('amount')) && is_numeric($get('vat_amount')) ? 'Excl. btw: '.Money::format(Money::round(((float) $get('amount') - (float) $get('vat_amount')) * 100)) : null)
                        ->live(debounce: 400),
                    static::categoryField()->columnSpanFull(),
                ]),
            ])
            ->action(function (array $data, Bookkeeping $bookkeeping): void {
                $cents = Money::round((float) $data['amount'] * 100);

                try {
                    $bookkeeping->addManual([
                        // Not a bank movement (e.g. deducted from a payout): always on the manual account.
                        'account_id' => Account::query()->firstOrCreate(['type' => AccountType::Manual], ['name' => 'Handmatig'])->getKey(),
                        'booked_on' => $data['booked_on'],
                        'counterparty_name' => trim($data['counterparty_name']),
                        'description' => $data['description'] ?? null,
                        'amount_cents' => $data['direction'] === 'in' ? $cents : -$cents,
                        'category_id' => (int) $data['category_id'],
                        'vat_rate' => in_array($data['vat_rate'] ?? '', ['', 'amount'], true) ? null : (float) $data['vat_rate'],
                        'vat_cents' => ($data['vat_rate'] ?? '') === 'amount' ? Money::round((float) $data['vat_amount'] * 100) : null,
                    ], static::user());
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Factuur toegevoegd')->body('Hij telt mee in de W&V en staat bij Transacties.')->send();
            });
    }

    /**
     * Delete a line that was added by hand.
     */
    public static function deleteManualAction(): Action
    {
        return Action::make('deleteManual')
            ->label('Verwijderen')
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->color('danger')
            ->tooltip('Handmatige factuur verwijderen')
            ->visible(fn (Transaction $record): bool => $record->mutation_type === 'manual')
            ->requiresConfirmation()
            ->modalHeading(fn (Transaction $record): string => "Factuur van {$record->counterparty_name} verwijderen?")
            ->modalDescription('De regel wordt verwijderd en telt niet meer mee in de W&V.')
            ->action(function (Transaction $record): void {
                $record->delete();

                Notification::make()->success()->title('Factuur verwijderd')->send();
            });
    }

    /**
     * The category picker, with the chosen category's explanation underneath.
     */
    public static function categoryField(string $name = 'category_id'): Select
    {
        return Select::make($name)
            ->label('Categorie')
            ->options(fn (): array => Category::pickerOptions())
            ->searchable()
            ->required()
            ->live()
            ->helperText(fn (mixed $state): ?string => filled($state) ? Category::query()->whereKey($state)->value('description') : null);
    }

    /**
     * The revenue of an order already counts; booking its bank line as revenue counts it twice.
     *
     * @param  list<string>  $orderRefs  The Plug&Pay orders the lines are linked to.
     */
    public static function warnAboutDoubleRevenue(Category $category, array $orderRefs): void
    {
        if ($category->kind !== CategoryKind::Revenue || $orderRefs === []) {
            return;
        }

        $orders = count($orderRefs) === 1 ? "Plug & Pay-order {$orderRefs[0]}" : count($orderRefs).' Plug & Pay-orders ('.implode(', ', array_slice($orderRefs, 0, 5)).(count($orderRefs) > 5 ? ', …' : '').')';

        Notification::make()
            ->warning()
            ->persistent()
            ->title('Let op: dubbele omzet')
            ->body((count($orderRefs) === 1 ? 'Deze bijschrijving hoort' : 'Deze bijschrijvingen horen')." bij {$orders}, en die omzet telt al mee. Boek ze op Klantbetaling per bank (Plug & Pay).")
            ->send();
    }

    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
        ];
    }
}
