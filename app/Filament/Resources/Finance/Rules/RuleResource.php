<?php

namespace App\Filament\Resources\Finance\Rules;

use App\Filament\Concerns\FinanceOnly;
use App\Filament\Resources\Finance\Rules\Pages\ManageRules;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Rule;
use App\Services\Finance\Bookkeeping;
use App\Services\Finance\Money;
use App\Services\Finance\RuleEngine;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Rules categorize new bank lines automatically. The first rule (from the top) that matches wins; what a person
 * confirmed is never overwritten.
 */
class RuleResource extends Resource
{
    use FinanceOnly;

    protected static ?string $model = Rule::class;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Regels';

    protected static ?string $modelLabel = 'regel';

    protected static ?string $pluralModelLabel = 'regels';

    protected static ?string $slug = 'finance/rules';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Naam')
                    ->placeholder('Bijv. Google Ads → Advertenties')
                    ->required()
                    ->maxLength(120),
                Repeater::make('conditions')
                    ->label('Als alle voorwaarden kloppen')
                    ->addActionLabel('Voorwaarde')
                    ->minItems(1)
                    ->maxItems(10)
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->live()
                    ->table([
                        Repeater\TableColumn::make('')->width('4rem'),
                        Repeater\TableColumn::make('Veld')->width('11rem'),
                        Repeater\TableColumn::make('')->width('9rem'),
                        Repeater\TableColumn::make('Waarde'),
                    ])
                    ->schema([
                        // "Als" before the first condition and "en" before the others: all of them must match.
                        TextEntry::make('joiner')
                            ->hiddenLabel()
                            ->state(fn (Get $get, TextEntry $component): string => str($component->getContainer()->getStatePath())->afterLast('.')->toString() === (string) array_key_first($get('../../conditions') ?? []) ? 'Als' : 'EN')
                            ->weight('semibold')
                            ->color(fn (?string $state): string => $state === 'EN' ? 'primary' : 'gray')
                            ->alignCenter(),
                        Select::make('field')
                            ->hiddenLabel()
                            ->options(RuleEngine::FieldLabels)
                            ->default('counterparty')
                            ->selectablePlaceholder(false)
                            ->live(),
                        Select::make('op')
                            ->hiddenLabel()
                            ->options(fn (Get $get): array => match ($get('field')) {
                                'direction', 'account_id' => ['equals' => 'is'],
                                'amount' => ['gte' => '≥', 'lte' => '≤', 'between' => 'tussen'],
                                default => ['contains' => 'bevat', 'equals' => 'is', 'starts_with' => 'begint met', 'regex' => 'matcht (regex)'],
                            })
                            ->default('contains')
                            ->selectablePlaceholder(false)
                            ->live(),
                        Grid::make(2)->schema([
                            TextInput::make('value')
                                ->hiddenLabel()
                                ->placeholder('tekst')
                                ->visible(fn (Get $get): bool => in_array($get('field'), ['counterparty', 'iban', 'description', 'mutation_code'], true))
                                ->required(fn (Get $get): bool => in_array($get('field'), ['counterparty', 'iban', 'description', 'mutation_code'], true))
                                ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if ($get('op') === 'regex' && @preg_match('~'.str_replace('~', '\~', (string) $value).'~', '') === false) {
                                        $fail("Ongeldige reguliere expressie: {$value}");
                                    }
                                })
                                ->columnSpanFull(),
                            Select::make('direction')
                                ->hiddenLabel()
                                ->options(['in' => 'inkomend (bij)', 'out' => 'uitgaand (af)'])
                                ->default('in')
                                ->selectablePlaceholder(false)
                                ->visible(fn (Get $get): bool => $get('field') === 'direction')
                                ->columnSpanFull(),
                            Select::make('account')
                                ->hiddenLabel()
                                ->options(fn (): array => Account::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->visible(fn (Get $get): bool => $get('field') === 'account_id')
                                ->required(fn (Get $get): bool => $get('field') === 'account_id')
                                ->columnSpanFull(),
                            TextInput::make('amount')
                                ->hiddenLabel()
                                ->prefix('€')
                                ->numeric()
                                ->minValue(0)
                                ->visible(fn (Get $get): bool => $get('field') === 'amount')
                                ->required(fn (Get $get): bool => $get('field') === 'amount')
                                ->columnSpan(fn (Get $get): int => $get('op') === 'between' ? 1 : 2),
                            TextInput::make('amount2')
                                ->hiddenLabel()
                                ->prefix('€')
                                ->numeric()
                                ->minValue(0)
                                ->visible(fn (Get $get): bool => $get('field') === 'amount' && $get('op') === 'between')
                                ->required(fn (Get $get): bool => $get('field') === 'amount' && $get('op') === 'between'),
                        ]),
                    ]),
                Radio::make('outcome')
                    ->label('Dan')
                    ->options(['categorize' => 'Categorie toewijzen', 'exclude' => 'Uitsluiten van de W&V'])
                    ->default('categorize')
                    ->inline()
                    ->live(),
                Grid::make(3)
                    ->visible(fn (Get $get): bool => $get('outcome') !== 'exclude')
                    ->schema([
                        Select::make('category_id')
                            ->label('Categorie')
                            ->options(fn (): array => Category::pickerOptions())
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('outcome') !== 'exclude')
                            ->columnSpan(2),
                        Select::make('vat_rate')
                            ->label('Btw')
                            ->options(['' => 'Standaard', '0.21' => '21%', '0.09' => '9%', '0' => '0%'])
                            ->default('')
                            ->selectablePlaceholder(false),
                    ]),
                TextInput::make('note')
                    ->label('Notitie op de transactie (optioneel)')
                    ->maxLength(300),
                TextEntry::make('preview')
                    ->hiddenLabel()
                    ->state(fn (Get $get): Htmlable => static::previewHtml(static::conditionsFromForm($get('conditions') ?? []))),
                Toggle::make('apply_now')
                    ->label('Meteen toepassen op open transacties (bevestigde blijven onaangeroerd)')
                    ->default(true)
                    ->dehydrated(),
            ]);
    }

    public static function table(Table $table): Table
    {
        $accountNames = Account::query()->pluck('name', 'id')->all();
        $categories = Category::query()->with('parent')->get()->keyBy('id');

        return $table
            ->description('Rules categorize new bank lines automatically. The first rule from the top that matches wins. What a person confirmed is never overwritten.')
            ->defaultSort('priority')
            ->reorderable('priority')
            ->columns([
                TextColumn::make('name')
                    ->label('Regel')
                    ->description(fn (Rule $record): ?string => match ($record->source) {
                        'inbox' => 'uit inbox',
                        'seed' => 'standaard',
                        default => null,
                    })
                    ->searchable()
                    ->wrap(),
                TextColumn::make('conditions')
                    ->label('Als…')
                    ->state(fn (Rule $record): HtmlString => new HtmlString(collect($record->conditions)->map(fn (array $condition): string => e(RuleEngine::describeCondition($condition, $accountNames)))->implode('<br>')))
                    ->wrap(),
                TextColumn::make('action')
                    ->label('Dan')
                    ->state(fn (Rule $record): string => $record->excludes()
                        ? 'Uitsluiten van de W&V'
                        : ($categories->get($record->action['categoryId'] ?? 0)?->label() ?? '—')
                            .(isset($record->action['vatRate']) && $record->action['vatRate'] !== null ? ' · '.round(((float) $record->action['vatRate']) * 100).'% btw' : ''))
                    ->wrap(),
                TextColumn::make('hit_count')
                    ->label('Treffers')
                    ->alignEnd(),
                ToggleColumn::make('active')
                    ->label('Actief'),
            ])
            ->filters([
                TernaryFilter::make('active')->label('Actief'),
                SelectFilter::make('source')->label('Bron')->options(['manual' => 'Handmatig', 'inbox' => 'Uit inbox', 'seed' => 'Standaard']),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Regel bewerken')
                    ->mutateRecordDataUsing(fn (array $data): array => static::toForm($data))
                    ->using(fn (Rule $record, array $data): Rule => static::save($data, $record))
                    ->successNotificationTitle('Regel opgeslagen'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label('Activeren')
                        ->icon(Heroicon::OutlinedPlay)
                        ->action(fn (Collection $records) => Rule::query()->whereKey($records->modelKeys())->update(['active' => true])),
                    BulkAction::make('deactivate')
                        ->label('Pauzeren')
                        ->icon(Heroicon::OutlinedPause)
                        ->action(fn (Collection $records) => Rule::query()->whereKey($records->modelKeys())->update(['active' => false])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Save a rule from the form, and apply the rules to the open lines when asked.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?Rule $rule = null, string $source = 'manual'): Rule
    {
        $attributes = [
            'name' => $data['name'],
            'conditions' => static::conditionsFromForm($data['conditions'] ?? []),
            'action' => ($data['outcome'] ?? 'categorize') === 'exclude'
                ? array_filter(['status' => 'excluded', 'note' => $data['note'] ?? null])
                : array_filter([
                    'categoryId' => (int) $data['category_id'],
                    'vatRate' => ($data['vat_rate'] ?? '') === '' ? null : (float) $data['vat_rate'],
                    'note' => $data['note'] ?? null,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        if ($rule) {
            $rule->update($attributes);
        } else {
            $rule = Rule::create([...$attributes, 'priority' => ((int) Rule::query()->max('priority')) + 10, 'active' => true, 'source' => $source]);
        }

        if ($data['apply_now'] ?? false) {
            $result = app(Bookkeeping::class)->applyRulesToOpenLines();

            Notification::make()->info()->title(trans_choice('{0} Geen open transacties veranderd|{1} 1 open transactie gecategoriseerd|[2,*] :count open transacties gecategoriseerd', $result['changed']))->send();
        }

        return $rule;
    }

    /**
     * The stored conditions from the form's rows: amounts in euros become cents.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function conditionsFromForm(array $rows): array
    {
        return collect($rows)->map(fn (array $row): ?array => match ($row['field'] ?? null) {
            'direction' => ['field' => 'direction', 'op' => 'equals', 'value' => $row['direction'] ?? 'in'],
            'account_id' => filled($row['account'] ?? null) ? ['field' => 'account_id', 'op' => 'equals', 'value' => (string) $row['account']] : null,
            'amount' => is_numeric($row['amount'] ?? null) ? array_filter([
                'field' => 'amount',
                'op' => $row['op'] ?? 'gte',
                'value' => Money::round((float) $row['amount'] * 100),
                'value2' => ($row['op'] ?? null) === 'between' && is_numeric($row['amount2'] ?? null) ? Money::round((float) $row['amount2'] * 100) : null,
            ], fn (mixed $value): bool => $value !== null) : null,
            null => null,
            default => filled($row['value'] ?? null) ? ['field' => $row['field'], 'op' => $row['op'] ?? 'contains', 'value' => trim($row['value'])] : null,
        })->filter()->values()->all();
    }

    /**
     * The form's fields for a stored rule.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toForm(array $data): array
    {
        $action = $data['action'] ?? [];

        return [
            'name' => $data['name'] ?? '',
            'conditions' => collect($data['conditions'] ?? [])->map(fn (array $condition): array => [
                'field' => $condition['field'],
                'op' => $condition['op'],
                'value' => in_array($condition['field'], ['direction', 'amount', 'account_id'], true) ? null : $condition['value'],
                'direction' => $condition['field'] === 'direction' ? $condition['value'] : null,
                'account' => $condition['field'] === 'account_id' ? $condition['value'] : null,
                'amount' => $condition['field'] === 'amount' ? ((int) $condition['value']) / 100 : null,
                'amount2' => isset($condition['value2']) ? ((int) $condition['value2']) / 100 : null,
            ])->all(),
            'outcome' => ($action['status'] ?? null) === 'excluded' ? 'exclude' : 'categorize',
            'category_id' => $action['categoryId'] ?? null,
            'vat_rate' => isset($action['vatRate']) ? (string) (float) $action['vatRate'] : '',
            'note' => $action['note'] ?? null,
            'apply_now' => false,
        ];
    }

    /**
     * How many existing lines the conditions match, with a few examples.
     *
     * @param  list<array<string, mixed>>  $conditions
     */
    public static function previewHtml(array $conditions): Htmlable
    {
        $box = '<div style="border-radius: 0.5rem; padding: 0.75rem 1rem; background: rgb(127 127 127 / 0.08); font-size: 0.875rem;">';

        if ($conditions === []) {
            return new HtmlString($box.'<span style="opacity: 0.7;">Vul de voorwaarden in om te zien welke transacties passen.</span></div>');
        }

        $preview = app(Bookkeeping::class)->previewConditions($conditions);
        $categories = Category::query()->with('parent')->get()->keyBy('id');

        $rows = $preview['sample']->map(fn ($transaction): string => '<div style="display: flex; gap: 0.75rem; padding: 0.125rem 0;">'
            .'<span style="opacity: 0.6; white-space: nowrap; font-family: ui-monospace, monospace;">'.$transaction->booked_on->format('Y-m-d').'</span>'
            .'<span style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">'.e($transaction->counterparty_name).' <span style="opacity: 0.6;">— '.e($transaction->description).'</span></span>'
            .'<span style="white-space: nowrap; opacity: 0.7;">'.e($categories->get($transaction->category_id)?->label() ?? ($transaction->status->value === 'excluded' ? 'Uitgesloten' : 'Open')).'</span>'
            .'<span style="white-space: nowrap; min-width: 6rem; text-align: right;">'.Money::format($transaction->amount_cents).'</span></div>')->implode('');

        return new HtmlString($box.'<div style="margin-bottom: 0.375rem;">'
            .trans_choice('{0} Geen bestaande transacties passen|{1} <b>1</b> bestaande transactie past|[2,*] <b>:count</b> bestaande transacties passen', $preview['total'])
            .($preview['total'] ? ', waarvan <b>'.$preview['open'].'</b> nog open.' : '.').'</div>'.$rows.'</div>');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRules::route('/'),
        ];
    }
}
