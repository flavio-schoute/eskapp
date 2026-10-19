<?php

namespace App\Filament\Pages\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Filament\Concerns\FinanceOnly;
use App\Filament\Resources\Finance\Rules\RuleResource;
use App\Filament\Resources\Finance\Transactions\TransactionResource;
use App\Models\Finance\Category;
use App\Models\Finance\Transaction;
use App\Services\Finance\Bookkeeping;
use App\Services\Finance\Suggester;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use UnitEnum;

/**
 * The lines that still need a decision: not categorized yet, or categorized by a rule and waiting to be confirmed.
 */
class Inbox extends Page implements HasTable
{
    use FinanceOnly;
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Inbox';

    protected static ?string $slug = 'finance/inbox';

    private ?Suggester $suggester = null;

    public static function getNavigationBadge(): ?string
    {
        $open = Transaction::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $open = Transaction::query()->where('status', TransactionStatus::Uncategorized)->count();
        $proposed = Transaction::query()->where('status', TransactionStatus::Auto)->count();

        return $open + $proposed > 0 ? "{$open} open · {$proposed} voorgesteld door regels" : 'Alles is gecategoriseerd.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.finance.notice-banner')
                ->viewData(fn (): array => ['items' => ($open = Transaction::query()->open()->count()) > 0
                    ? [['text' => $open.($open === 1 ? ' bankregel wacht op een beslissing en telt' : ' bankregels wachten op een beslissing en tellen').' nog niet (volledig) mee in de W&V', 'url' => null]]
                    : []]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return TransactionResource::configureTransactionTable($table)
            ->query(fn (): Builder => Transaction::query()->open()->with('rule:id,name'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw("case when status = 'uncategorized' then 0 else 1 end")->orderByDesc('booked_on')->orderBy('id'))
            ->pushColumns([
                TextColumn::make('proposal')
                    ->label('Voorstel')
                    ->state(fn (Transaction $record): ?string => $this->proposalLabel($record))
                    ->description(fn (Transaction $record): ?string => $this->proposalBasis($record))
                    ->color('info')
                    ->placeholder('-')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Toon')
                    ->options([TransactionStatus::Uncategorized->value => 'Open', TransactionStatus::Auto->value => 'Voorgesteld door regels']),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label('Akkoord')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->button()
                    ->size('sm')
                    ->extraAttributes(['data-shortcut' => 'accept'])
                    ->visible(fn (Transaction $record): bool => $record->status === TransactionStatus::Auto || $this->suggestion($record) !== null)
                    ->action(function (Transaction $record, Bookkeeping $bookkeeping): void {
                        if ($record->status === TransactionStatus::Auto) {
                            $bookkeeping->confirm([$record->getKey()], TransactionResource::user());
                        } elseif ($suggestion = $this->suggestion($record)) {
                            $bookkeeping->setCategory([$record->getKey()], Category::findOrFail($suggestion['categoryId']), TransactionResource::user());
                        }

                        Notification::make()->success()->title('Bevestigd')->send();
                    }),
                Action::make('createRule')
                    ->label('Regel maken')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->iconButton()
                    ->color('gray')
                    ->tooltip('Regel maken vanaf deze transactie (r)')
                    ->extraAttributes(['data-shortcut' => 'rule'])
                    ->modalHeading('Nieuwe regel')
                    ->modalDescription('The line gets this category now, and the rule categorizes future lines from this counterparty automatically.')
                    ->fillForm(fn (Transaction $record): array => [
                        'name' => $record->counterparty_name,
                        'value' => $record->counterparty_name,
                        'category_id' => $this->suggestion($record)['categoryId'] ?? $record->category_id,
                        'vat_rate' => $record->vat_rate === null ? '' : (string) $record->vat_rate,
                    ])
                    ->schema([
                        TextInput::make('name')->label('Naam')->required()->maxLength(120),
                        TextInput::make('value')->label('Tegenpartij is')->required()->maxLength(300),
                        TransactionResource::categoryField(),
                        Select::make('vat_rate')->label('Btw')->options(['' => 'Standaard', ...TransactionResource::VatRates])->default('')->selectablePlaceholder(false),
                    ])
                    ->action(function (Transaction $record, array $data, Bookkeeping $bookkeeping): void {
                        $category = Category::findOrFail($data['category_id']);

                        try {
                            $bookkeeping->setCategory([$record->getKey()], $category, TransactionResource::user(), $data['vat_rate'] === '' ? null : (float) $data['vat_rate']);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        RuleResource::save([
                            'name' => "{$data['name']} → {$category->name}",
                            'conditions' => [['field' => 'counterparty', 'op' => 'equals', 'value' => $data['value']]],
                            'outcome' => 'categorize',
                            'category_id' => $category->getKey(),
                            'vat_rate' => $data['vat_rate'],
                            'apply_now' => true,
                        ], source: 'inbox');

                        Notification::make()->success()->title('Regel gemaakt')->send();
                    }),
                Action::make('exclude')
                    ->label('Uitsluiten')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->iconButton()
                    ->color('gray')
                    ->tooltip('Uitsluiten van de W&V (x)')
                    ->extraAttributes(['data-shortcut' => 'exclude'])
                    ->action(function (Transaction $record, Bookkeeping $bookkeeping): void {
                        $bookkeeping->exclude([$record->getKey()], TransactionResource::user());

                        Notification::make()->success()->title('Uitgesloten van de W&V')->send();
                    }),
                TransactionResource::detailsAction(),
                TransactionResource::splitAction(),
            ])
            ->description(new HtmlString('<span style="font-size: 0.8rem; opacity: 0.75;"><kbd>j</kbd>/<kbd>k</kbd> bewegen · <kbd>Enter</kbd> categorie · <kbd>a</kbd> voorstel of suggestie akkoord · <kbd>r</kbd> regel maken · <kbd>x</kbd> uitsluiten · <kbd>spatie</kbd> selecteren · <kbd>Esc</kbd> stoppen</span>'))
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('Inbox leeg')
            ->emptyStateDescription('Alle transacties zijn gecategoriseerd.');
    }

    private function proposalLabel(Transaction $record): ?string
    {
        if ($record->status === TransactionStatus::Auto) {
            return $record->category?->label() ?? ($record->rule?->excludes() ? 'Uitsluiten' : null);
        }

        $suggestion = $this->suggestion($record);

        return $suggestion ? Category::with('parent')->find($suggestion['categoryId'])?->label() : null;
    }

    private function proposalBasis(Transaction $record): ?string
    {
        if ($record->status === TransactionStatus::Auto) {
            return match (true) {
                $record->rule !== null => "regel: {$record->rule->name}",
                $record->order_ref !== null => "Plug & Pay-order {$record->order_ref}".($record->order_match === 'open' ? ' (staat nog open)' : ''),
                default => null,
            };
        }

        $suggestion = $this->suggestion($record);

        return $suggestion ? "suggestie: {$suggestion['support']} van {$suggestion['total']} keer eerder zo geboekt" : null;
    }

    /**
     * A suggestion from the confirmed history of the same IBAN or counterparty, for lines no rule matched.
     *
     * @return array{categoryId: int|string, basis: string, support: int, total: int}|null
     */
    private function suggestion(Transaction $record): ?array
    {
        if ($record->status !== TransactionStatus::Uncategorized) {
            return null;
        }

        $this->suggester ??= $this->buildSuggester();

        return $this->suggester->suggest($record->counterparty_name, $record->counterparty_iban);
    }

    /**
     * Only the confirmed history of the counterparties waiting in the inbox, not every line in the books.
     */
    private function buildSuggester(): Suggester
    {
        $open = Transaction::query()->where('status', TransactionStatus::Uncategorized)->get(['counterparty_name', 'counterparty_iban']);

        $history = Transaction::query()
            ->where('status', TransactionStatus::Confirmed)
            ->whereNotNull('category_id')
            ->where(fn (Builder $query) => $query
                ->whereIn('counterparty_iban', $open->pluck('counterparty_iban')->filter()->unique()->all())
                ->orWhereIn('counterparty_name', $open->pluck('counterparty_name')->filter()->unique()->all()))
            ->get(['counterparty_name', 'counterparty_iban', 'category_id'])
            ->map(fn (Transaction $transaction): array => ['counterpartyName' => $transaction->counterparty_name, 'counterpartyIban' => $transaction->counterparty_iban, 'categoryId' => $transaction->category_id]);

        return new Suggester($history);
    }
}
