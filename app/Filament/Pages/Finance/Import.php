<?php

namespace App\Filament\Pages\Finance;

use App\Filament\Concerns\FinanceOnly;
use App\Jobs\Finance\SyncFinanceOrders;
use App\Models\Finance\Account;
use App\Models\Finance\Import as FinanceImport;
use App\Models\Finance\Order;
use App\Models\Product;
use App\Services\Finance\BankCsvParser;
use App\Services\Finance\BankImporter;
use App\Services\Finance\Csv;
use App\Services\Finance\Money;
use App\Services\PlugAndPay;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use UnitEnum;

/**
 * Upload bank exports (ING, Rabobank, American Express or any CSV with a column mapping). Lines already imported are
 * skipped. The Plug&Pay orders are fetched automatically through the API.
 */
class Import extends Page implements HasTable
{
    use FinanceOnly;
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Importeren';

    protected static ?string $slug = 'finance/import';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(['account_id' => Account::query()->where('archived', false)->orderBy('name')->value('id')]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Upload a bank export (ING, Rabobank, American Express or another CSV). Duplicate lines are skipped automatically.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('Import')
                    ->tabs([
                        Tab::make('Bankexport')
                            ->icon(Heroicon::OutlinedBuildingLibrary)
                            ->schema([
                                Select::make('account_id')
                                    ->label('Rekening')
                                    ->options(fn (): array => Account::query()->where('archived', false)->orderBy('name')->pluck('name', 'id')->all())
                                    ->required()
                                    ->live(),
                                FileUpload::make('file')
                                    ->label('CSV-bestand')
                                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                                    ->storeFiles(false)
                                    ->maxSize(20480)
                                    ->live(),
                                Section::make('Kolomindeling')
                                    ->description('Unknown format: choose once which column is what. It is remembered for this account.')
                                    ->visible(fn (Get $get): bool => (bool) ($this->preview($get)['needs_mapping'] ?? false))
                                    ->columns(3)
                                    ->schema([
                                        Select::make('mapping.date')->label('Datum')->options(fn (Get $get): array => $this->headerOptions($get))->required()->live(),
                                        Select::make('mapping.dateFormat')->label('Datumnotatie')->options(['DD-MM-YYYY' => 'DD-MM-JJJJ', 'YYYY-MM-DD' => 'JJJJ-MM-DD', 'YYYYMMDD' => 'JJJJMMDD', 'DD/MM/YYYY' => 'DD/MM/JJJJ', 'MM/DD/YYYY' => 'MM/DD/JJJJ'])->default('DD-MM-YYYY')->required()->live(),
                                        Select::make('mapping.amount')->label('Bedrag')->options(fn (Get $get): array => $this->headerOptions($get))->required()->live(),
                                        Select::make('mapping.counterparty')->label('Tegenpartij')->options(fn (Get $get): array => $this->headerOptions($get))->required()->live(),
                                        Select::make('mapping.description')->label('Omschrijving')->options(fn (Get $get): array => $this->headerOptions($get))->live(),
                                        Select::make('mapping.iban')->label('IBAN tegenpartij')->options(fn (Get $get): array => $this->headerOptions($get))->live(),
                                        Select::make('mapping.direction.column')->label('Af/bij-kolom (optioneel)')->options(fn (Get $get): array => $this->headerOptions($get))->live(),
                                        TagsInput::make('mapping.direction.outValues')->label('Waarden voor geld uit')->placeholder('bijv. Debit, Af')->live()->columnSpan(2),
                                    ]),
                                TextEntry::make('preview')
                                    ->hiddenLabel()
                                    ->state(fn (Get $get): ?Htmlable => $this->previewHtml($get))
                                    ->visible(fn (Get $get): bool => filled($get('file'))),
                                Actions::make([
                                    Action::make('commit')
                                        ->label(fn (Get $get): string => 'Importeer '.(($this->preview($get)['new'] ?? 0)).' transacties')
                                        ->icon(Heroicon::OutlinedArrowUpTray)
                                        ->disabled(fn (Get $get): bool => ($this->preview($get)['new'] ?? 0) === 0)
                                        ->action(fn () => $this->commit()),
                                ])->visible(fn (Get $get): bool => filled($get('file'))),
                            ]),
                        Tab::make('Plug&Pay-orders')
                            ->icon(Heroicon::OutlinedShoppingCart)
                            ->schema([
                                TextEntry::make('orders')
                                    ->hiddenLabel()
                                    ->state(fn (): Htmlable => $this->ordersHtml()),
                                Actions::make([
                                    Action::make('syncOrders')
                                        ->label('Nu synchroniseren')
                                        ->icon(Heroicon::OutlinedArrowPath)
                                        ->color('gray')
                                        ->disabled(fn (): bool => ! app(PlugAndPay::class)->isConfigured())
                                        ->action(function (): void {
                                            SyncFinanceOrders::dispatch(null);

                                            Notification::make()->success()->title('Orders worden gesynchroniseerd')->body('All invoiced orders are fetched from Plug&Pay in the background (about a minute).')->send();
                                        }),
                                ]),
                            ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Section::make('Eerdere imports')->schema([EmbeddedTable::make()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => FinanceImport::query()->with('account:id,name'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('filename')->label('Bestand')->limit(40)->tooltip(fn (FinanceImport $record): string => $record->filename),
                TextColumn::make('account.name')->label('Bron')->description(fn (FinanceImport $record): string => BankCsvParser::Formats[$record->format] ?? $record->format),
                TextColumn::make('date_from')->label('Periode')->state(fn (FinanceImport $record): string => ($record->date_from?->translatedFormat('j M y') ?? '?').' – '.($record->date_to?->translatedFormat('j M y') ?? '?')),
                TextColumn::make('rows_new')->label('Nieuw')->alignEnd(),
                TextColumn::make('rows_duplicate')->label('Dubbel')->color('gray')->alignEnd(),
                TextColumn::make('rows_auto')->label('Via regel')->alignEnd(),
                TextColumn::make('created_at')->label('Geüpload')->dateTime('d-m-Y, H:i')->description(fn (FinanceImport $record): ?string => $record->uploader?->name),
            ])
            ->recordActions([
                Action::make('undo')
                    ->label('Ongedaan maken')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->link()
                    ->requiresConfirmation()
                    ->modalDescription(fn (FinanceImport $record): string => "Deletes all {$record->rows_new} lines from this import, also the ones that were categorized since.")
                    ->action(function (FinanceImport $record, BankImporter $importer): void {
                        $importer->undo($record);

                        Notification::make()->success()->title('Import ongedaan gemaakt')->send();
                    }),
            ])
            ->emptyStateHeading('Nog geen imports');
    }

    public function commit(): void
    {
        $account = Account::find($this->data['account_id'] ?? null);
        $file = $this->uploadedFile();

        if (! $account || ! $file) {
            return;
        }

        try {
            $result = app(BankImporter::class)->commit($account, $file->getClientOriginalName(), (string) file_get_contents($file->getRealPath()), $this->mapping(), auth()->user());
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Import mislukt')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title("{$result['new']} transacties geïmporteerd")
            ->body("{$result['auto']} gecategoriseerd door een regel, {$result['duplicates']} dubbele overgeslagen. De rest staat in de inbox.")
            ->actions([Action::make('inbox')->label('Naar de inbox')->url(Inbox::getUrl())])
            ->send();

        $this->form->fill(['account_id' => $account->getKey()]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function preview(Get $get): ?array
    {
        $account = Account::find($get('account_id'));
        $file = $this->uploadedFile($get('file'));

        if (! $account || ! $file) {
            return null;
        }

        try {
            return app(BankImporter::class)->preview($account, (string) file_get_contents($file->getRealPath()), $this->mapping($get('mapping')));
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage(), 'new' => 0];
        }
    }

    private function previewHtml(Get $get): ?Htmlable
    {
        $preview = $this->preview($get);

        if (! $preview) {
            return null;
        }

        if (isset($preview['error'])) {
            return new HtmlString('<p style="color: var(--danger-600);">'.e($preview['error']).'</p>');
        }

        $account = Account::find($get('account_id'));
        $warnings = [];

        if ($account?->iban && $preview['own_ibans'] !== [] && ! in_array($account->iban, array_map(fn (string $iban): string => strtoupper(str_replace(' ', '', $iban)), $preview['own_ibans']), true)) {
            $warnings[] = 'Dit bestand hoort bij IBAN '.e(implode(', ', $preview['own_ibans'])).', niet bij '.e($account->iban).'. Heb je de goede rekening gekozen?';
        }

        foreach (array_slice($preview['errors'], 0, 5) as $error) {
            $warnings[] = ($error['line'] ? "Regel {$error['line']}: " : '').e($error['message']);
        }

        $sample = collect($preview['sample'])->map(fn ($row): string => '<tr><td style="padding: 0.125rem 0.75rem 0.125rem 0; opacity: 0.6; white-space: nowrap;">'.e($row->bookedOn).'</td><td style="padding: 0.125rem 0.75rem 0.125rem 0;">'.e($row->counterpartyName).' <span style="opacity: 0.6;">'.e(str($row->description)->limit(60)).'</span></td><td style="text-align: right; white-space: nowrap;">'.Money::format($row->amountCents).'</td></tr>')->implode('');

        return new HtmlString(
            '<div style="display: flex; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 0.75rem;">'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Formaat</div><div style="font-weight: 600;">'.e(BankCsvParser::Formats[$preview['format']] ?? $preview['format']).'</div></div>'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Periode</div><div style="font-weight: 600;">'.e(($preview['date_from'] ?? '?').' – '.($preview['date_to'] ?? '?')).'</div></div>'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Nieuw</div><div style="font-weight: 600;">'.$preview['new'].'</div></div>'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Al geïmporteerd</div><div style="font-weight: 600;">'.$preview['duplicates'].'</div></div>'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">In</div><div style="font-weight: 600; color: var(--success-600);">'.Money::format($preview['sum_in']).'</div></div>'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Uit</div><div style="font-weight: 600;">'.Money::format($preview['sum_out']).'</div></div>'
            .'</div>'
            .implode('', array_map(fn (string $warning): string => '<div style="margin-bottom: 0.5rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; background: rgb(245 158 11 / 0.12);">⚠️ '.$warning.'</div>', $warnings))
            .($sample ? '<table style="font-size: 0.85rem;">'.$sample.'</table>' : '')
        );
    }

    private function ordersHtml(): Htmlable
    {
        $lastSync = Order::query()->max('synced_at');
        $lastPaid = Order::query()->where('status', 'paid')->max('paid_on');
        $paidLines = Order::query()->where('status', 'paid')->count();
        $unmapped = Product::query()->whereNull('revenue_stream_id')->whereIn('plug_and_pay_product_id', Order::query()->where('status', 'paid')->select('plug_and_pay_product_id'))->count();

        return new HtmlString(
            '<p style="margin-bottom: 0.75rem;">The Plug&Pay orders are fetched automatically through the API: the last 4 months every hour, all orders every night. Only paid order lines count as revenue, on their payment date, excl. VAT. Orders whose status changed (e.g. from open to paid) are updated.</p>'
            .'<div style="display: flex; flex-wrap: wrap; gap: 1.5rem;">'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Laatst gesynchroniseerd</div><div style="font-weight: 600;">'.($lastSync ? Carbon::parse($lastSync)->timezone('Europe/Amsterdam')->translatedFormat('j M Y, H:i') : 'Nog niet').'</div></div>'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Betaalde orderregels</div><div style="font-weight: 600;">'.number_format($paidLines, 0, ',', '.').'</div></div>'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Laatste betaling</div><div style="font-weight: 600;">'.e($lastPaid ?? '—').'</div></div>'
                .'<div><div style="opacity: 0.6; font-size: 0.8rem;">Producten zonder omzetstroom</div><div style="font-weight: 600; '.($unmapped ? 'color: var(--warning-600);' : '').'">'.$unmapped.'</div></div>'
            .'</div>'
        );
    }

    /**
     * @return array<string, string>
     */
    private function headerOptions(Get $get): array
    {
        $file = $this->uploadedFile($get('file'));

        if (! $file) {
            return [];
        }

        $headers = Csv::read((string) file_get_contents($file->getRealPath()))['headers'];

        return array_combine($headers, $headers);
    }

    /**
     * @param  array<string, mixed>|null  $mapping
     * @return array<string, mixed>|null
     */
    private function mapping(?array $mapping = null): ?array
    {
        $mapping ??= $this->data['mapping'] ?? null;

        if (blank($mapping['date'] ?? null) || blank($mapping['amount'] ?? null) || blank($mapping['counterparty'] ?? null)) {
            return null;
        }

        if (blank($mapping['direction']['column'] ?? null)) {
            unset($mapping['direction']);
        }

        return $mapping;
    }

    private function uploadedFile(mixed $state = null): ?TemporaryUploadedFile
    {
        $state ??= $this->data['file'] ?? null;
        $file = is_array($state) ? reset($state) : $state;

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }
}
