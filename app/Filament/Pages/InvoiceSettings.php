<?php

namespace App\Filament\Pages;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\Integration;
use App\Enums\MollieExportStatus;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Models\Affiliate;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\IntegrationErrorLogger;
use App\Services\MollieInvoicingCustomersExport;
use App\Services\MollieSalesInvoices;
use App\Support\InvoiceAddressFormat;
use App\Support\InvoiceVat;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Mollie\Api\Types\PaymentTerm;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class InvoiceSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Invoice settings';

    /**
     * @var array{create_invoices_via_api: bool}
     */
    public array $settings = ['create_invoices_via_api' => false];

    public function mount(): void
    {
        $this->settings['create_invoices_via_api'] = $this->createsInvoicesViaApi();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Settings')
                    ->compact()
                    ->schema([
                        Toggle::make('settings.create_invoices_via_api')
                            ->label(fn (): Htmlable => $this->comingSoonLabel('Create invoices via the Mollie API'))
                            ->helperText(fn (): string => $this->canManageSettings()
                                ? 'On: "Generate invoice" creates and emails the invoice from here. Off: it opens Mollie Invoicing → Facturen to create it there.'
                                : 'Only '.User::InvoiceSettingsManagerEmail.' can change this.')
                            ->disabled(fn (): bool => ! $this->canManageSettings())
                            ->live()
                            ->afterStateUpdated(fn (bool $state) => $this->saveCreateInvoicesViaApi($state)),
                    ]),
                Tabs::make('Invoice providers')
                    ->tabs([
                        Tab::make('Mollie')
                            ->icon(Heroicon::OutlinedCreditCard)
                            ->badge(fn (): ?int => $this->notInMollieAffiliates()->count() ?: null)
                            ->badgeColor('warning')
                            ->schema([
                                EmbeddedTable::make(),
                            ]),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Affiliates paid by invoice')
            ->description(fn (): string => $this->mollieReminder())
            ->query(fn (): Builder => $this->invoicedAffiliatesQuery()->with('latestInvoice'))
            ->defaultSort('name')
            ->recordUrl(fn (Affiliate $record): string => AffiliateResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Partner name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('invoice_company_name')
                    ->label('Invoice to')
                    ->description(fn (Affiliate $record): ?string => $record->invoice_contact_person)
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('invoice_email')
                    ->label('Email')
                    ->placeholder('-'),
                TextColumn::make('invoice_country')
                    ->label('Country')
                    ->formatStateUsing(fn (string $state): ?string => InvoiceAddressFormat::countryName($state))
                    ->placeholder('-'),
                TextColumn::make('mollie_export_status')
                    ->label('In Mollie')
                    ->state(fn (Affiliate $record): MollieExportStatus => $record->mollieExportStatus())
                    ->formatStateUsing(fn (MollieExportStatus $state, Affiliate $record): string => $state === MollieExportStatus::Exported
                        ? 'Exported '.$record->mollie_exported_at->translatedFormat('j M Y')
                        : $state->getLabel())
                    ->badge()
                    ->tooltip(fn (Affiliate $record): ?string => match ($record->mollieExportStatus()) {
                        MollieExportStatus::NotExported => 'Included in the next "Export for Mollie".',
                        MollieExportStatus::ChangedSinceExport => 'Invoice details changed after the export. Update this customer in Mollie Invoicing → Klanten, then click "Updated in Mollie".',
                        MollieExportStatus::Exported => null,
                    }),
                TextColumn::make('invoice_details_status')
                    ->label('Invoice details')
                    ->state(fn (Affiliate $record): string => $record->hasCompleteInvoiceDetails() ? 'Complete' : 'Incomplete')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Complete' ? 'success' : 'warning')
                    ->tooltip(fn (Affiliate $record): ?string => $this->missingDetailsTooltip($record)),
                TextColumn::make('latestInvoice.invoice_number')
                    ->label('Last invoice')
                    ->formatStateUsing(fn (string $state, Affiliate $record): string => $state.' · '.$record->latestInvoice->created_at->translatedFormat('j M Y'))
                    ->description(fn (Affiliate $record): ?string => $record->latestInvoice ? '€ '.number_format((float) ($record->latestInvoice->total_amount ?? $record->latestInvoice->amount), 2, ',', '.') : null)
                    ->url(fn (Affiliate $record): ?string => $record->latestInvoice?->pdf_url, shouldOpenInNewTab: true)
                    ->placeholder('-'),
            ])
            ->headerActions([
                $this->exportForMollieAction(),
                $this->generateInvoiceAction()
                    ->tooltip(fn (): ?string => $this->createsInvoicesViaApi() ? null : 'Opens Mollie Invoicing → Facturen. Click "Aanmaken" there, pick the customer and the "Affiliate commissie" product, and enter the price.'),
            ])
            ->recordActions([
                Action::make('markUpdatedInMollie')
                    ->label('Updated in Mollie')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('warning')
                    ->link()
                    ->visible(fn (Affiliate $record): bool => $record->mollieExportStatus() === MollieExportStatus::ChangedSinceExport)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Affiliate $record): string => "Confirm that {$record->name}'s new invoice details are updated in Mollie Invoicing → Klanten.")
                    ->action(function (Affiliate $record, MollieInvoicingCustomersExport $export): void {
                        $export->markAsExported(collect([$record]));

                        Notification::make()
                            ->success()
                            ->title("{$record->name} is up to date in Mollie")
                            ->send();
                    }),
                $this->generateInvoiceAction('generateInvoiceForAffiliate')
                    ->link()
                    ->disabled(fn (Affiliate $record): bool => $this->whyNotInvoiceable($record) !== null)
                    ->color(fn (Affiliate $record): string => $this->whyNotInvoiceable($record) === null ? 'primary' : 'gray')
                    ->tooltip(fn (Affiliate $record): ?string => $this->whyNotInvoiceable($record)
                        ?? ($this->createsInvoicesViaApi() ? null : "Opens Mollie Invoicing → Facturen. Click \"Aanmaken\" and pick {$record->invoice_company_name}."))
                    ->fillForm(fn (Affiliate $record): array => $this->defaultInvoiceData($record)),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading('No affiliates to invoice')
            ->emptyStateDescription('Active affiliates with payment method "Invoice" will appear here.');
    }

    /**
     * @return Builder<Affiliate>
     */
    protected function invoicedAffiliatesQuery(): Builder
    {
        return Affiliate::query()
            ->where('status', AffiliateStatus::Active)
            ->where('payment_method', AffiliatePaymentMethod::Invoice);
    }

    /**
     * @return Builder<Affiliate>
     */
    protected function invoiceableAffiliatesQuery(): Builder
    {
        return $this->invoicedAffiliatesQuery()
            ->whereNotNull('invoice_company_name')->where('invoice_company_name', '!=', '')
            ->whereNotNull('invoice_address_line_1')->where('invoice_address_line_1', '!=', '')
            ->whereNotNull('invoice_postal_code')->where('invoice_postal_code', '!=', '')
            ->whereNotNull('invoice_city')->where('invoice_city', '!=', '')
            ->whereNotNull('invoice_country')->where('invoice_country', '!=', '')
            ->whereNotNull('invoice_email')->where('invoice_email', '!=', '');
    }

    protected function missingDetailsTooltip(Affiliate $affiliate): ?string
    {
        $missing = $affiliate->missingInvoiceDetails();

        return $missing ? 'Add the '.collect($missing)->join(', ', ' and ').' to the invoice details first.' : null;
    }

    /**
     * Why an invoice cannot be generated for the affiliate yet, or null when it can: its invoice details must be
     * complete and it must be in Mollie Invoicing → Klanten with its current details.
     */
    protected function whyNotInvoiceable(Affiliate $affiliate): ?string
    {
        if ($missing = $this->missingDetailsTooltip($affiliate)) {
            return $missing;
        }

        return match ($affiliate->mollieExportStatus()) {
            MollieExportStatus::NotExported => 'Not in Mollie yet: click "Export for Mollie" and upload the file in Invoicing → Klanten first.',
            MollieExportStatus::ChangedSinceExport => 'Invoice details changed since the export: update this customer in Mollie, then click "Updated in Mollie".',
            MollieExportStatus::Exported => null,
        };
    }

    /**
     * A short reason shown next to an affiliate that cannot be picked in the generate invoice form.
     */
    protected function shortReasonNotInvoiceable(Affiliate $affiliate): ?string
    {
        if (! $affiliate->hasCompleteInvoiceDetails()) {
            return 'invoice details incomplete';
        }

        return match ($affiliate->mollieExportStatus()) {
            MollieExportStatus::NotExported => 'not in Mollie yet',
            MollieExportStatus::ChangedSinceExport => 'changed since export',
            MollieExportStatus::Exported => null,
        };
    }

    /**
     * Download the partners with complete invoice details as a CSV for Mollie Invoicing → Klanten → Uploaden.
     */
    protected function exportForMollieAction(): Action
    {
        return Action::make('exportForMollie')
            ->label(fn (): string => ($count = $this->notInMollieAffiliates()->count()) > 0 ? "Export for Mollie ({$count})" : 'Export for Mollie')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->tooltip('CSV of partners that are not in Mollie yet, for Invoicing → Klanten → Uploaden.')
            ->action(function (MollieInvoicingCustomersExport $export): ?StreamedResponse {
                $affiliates = $this->notInMollieAffiliates();

                if ($affiliates->isEmpty()) {
                    Notification::make()
                        ->info()
                        ->title('Nothing new to export')
                        ->body('Every partner with complete invoice details has already been exported.')
                        ->send();

                    return null;
                }

                $csv = $export->toCsv($affiliates);
                $export->markAsExported($affiliates);

                Notification::make()
                    ->success()
                    ->title(trans_choice('{1} Exported 1 partner for Mollie|[2,*] Exported :count partners for Mollie', $affiliates->count()))
                    ->body('Upload the file in Mollie Invoicing → Klanten → Uploaden.')
                    ->send();

                return response()->streamDownload(fn () => print ($csv), 'mollie-customers-'.now()->toDateString().'.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            });
    }

    /**
     * Partners with complete invoice details that have never been exported to Mollie.
     *
     * @return Collection<int, Affiliate>
     */
    protected function notInMollieAffiliates(): Collection
    {
        return $this->invoiceableAffiliatesQuery()
            ->whereNull('mollie_export_fingerprint')
            ->orderBy('name')
            ->get();
    }

    protected function mollieReminder(): string
    {
        $invoiceable = $this->invoiceableAffiliatesQuery()->get();
        $notExported = $invoiceable->filter(fn (Affiliate $affiliate): bool => $affiliate->mollieExportStatus() === MollieExportStatus::NotExported)->count();
        $changed = $invoiceable->filter(fn (Affiliate $affiliate): bool => $affiliate->mollieExportStatus() === MollieExportStatus::ChangedSinceExport)->count();

        $messages = array_filter([
            $notExported > 0 ? trans_choice('{1} 1 partner is not in Mollie yet: click "Export for Mollie" and upload the file in Invoicing → Klanten.|[2,*] :count partners are not in Mollie yet: click "Export for Mollie" and upload the file in Invoicing → Klanten.', $notExported) : null,
            $changed > 0 ? trans_choice('{1} 1 partner changed since the export: update it in Mollie by hand.|[2,*] :count partners changed since the export: update them in Mollie by hand.', $changed) : null,
        ]);

        return $messages
            ? implode(' ', $messages)
            : 'Active affiliates with payment method "Invoice". Everyone with complete invoice details is in Mollie.';
    }

    protected function generateInvoiceAction(string $name = 'generateInvoice'): Action
    {
        return Action::make($name)
            ->label('Generate invoice')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->url(fn (): ?string => $this->createsInvoicesViaApi() ? null : $this->mollieInvoicesUrl(), shouldOpenInNewTab: true)
            ->modalHeading('Generate invoice')
            ->modalDescription('Mollie creates the invoice in Invoicing → Facturen and emails it, with a payment link, to the partner\'s invoice email address.')
            ->modalSubmitActionLabel('Create and send invoice')
            ->fillForm(fn (): array => $this->defaultInvoiceData())
            ->schema([
                Select::make('affiliate_id')
                    ->label('Affiliate')
                    ->options(fn (): array => $this->invoicedAffiliatesQuery()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Affiliate $affiliate): array => [
                            $affiliate->getKey() => ($reason = $this->shortReasonNotInvoiceable($affiliate))
                                ? "{$affiliate->name} ({$reason})"
                                : $affiliate->name,
                        ])
                        ->all())
                    ->disableOptionWhen(fn (string $value): bool => ($affiliate = $this->invoicedAffiliatesQuery()->find($value)) === null || $this->whyNotInvoiceable($affiliate) !== null)
                    ->helperText('Greyed-out affiliates need complete invoice details and must be in Mollie first.')
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('description', $this->defaultDescription($get('affiliate_id'), $get('period')))),
                Select::make('period')
                    ->label('Invoice period')
                    ->options(fn (): array => $this->periodOptions())
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('description', $this->defaultDescription($get('affiliate_id'), $get('period')))),
                TextInput::make('amount')
                    ->label('Commission amount (excl. VAT)')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->prefix('€')
                    ->required()
                    ->live(debounce: 400),
                Textarea::make('description')
                    ->label('Invoice line')
                    ->rows(2)
                    ->required()
                    ->maxLength(255),
                Select::make('payment_term')
                    ->label('Payment term')
                    ->options([
                        PaymentTerm::DAYS_7 => '7 days',
                        PaymentTerm::DAYS_14 => '14 days',
                        PaymentTerm::DAYS_30 => '30 days',
                    ])
                    ->selectablePlaceholder(false)
                    ->required(),
                TextEntry::make('invoice_summary')
                    ->label('Invoice')
                    ->state(fn (Get $get): ?array => $this->invoiceSummary($get('affiliate_id'), $get('amount')))
                    ->listWithLineBreaks()
                    ->placeholder('Pick an affiliate and an amount to see the VAT and total.'),
            ])
            ->action(function (array $data, MollieSalesInvoices $invoices, IntegrationErrorLogger $errorLogger): void {
                $affiliate = $this->invoiceableAffiliatesQuery()->findOrFail($data['affiliate_id']);

                abort_if($this->whyNotInvoiceable($affiliate) !== null, 422);

                try {
                    $invoice = $invoices->issue(
                        $affiliate,
                        Carbon::parse($data['period']),
                        (string) $data['amount'],
                        $data['description'],
                        $data['payment_term'],
                        auth()->user(),
                    );
                } catch (Throwable $exception) {
                    $errorLogger->record(Integration::Mollie, 'Generate invoice', $exception, $affiliate);

                    Notification::make()
                        ->danger()
                        ->title("Could not create the invoice for {$affiliate->name}")
                        ->body('Mollie: '.IntegrationErrorLogger::readableMessage($exception).' The app did not store an invoice; check Mollie Invoicing → Facturen before trying again. The error is logged under System → Integration errors.')
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Invoice '.($invoice->invoice_number ?? '').' sent to '.$invoice->sent_to)
                    ->body("{$affiliate->name} · {$invoice->description} · € ".number_format((float) ($invoice->total_amount ?? $invoice->amount), 2, ',', '.').' incl. VAT')
                    ->send();
            });
    }

    /**
     * Whether "Generate invoice" creates the invoice through the Mollie API (otherwise it opens the Mollie dashboard).
     */
    protected function createsInvoicesViaApi(): bool
    {
        return (bool) AppSetting::value(AppSetting::CreateInvoicesViaMollieApi, config('services.mollie.create_invoices_via_api'));
    }

    /**
     * A label with a lock icon and a "Coming soon" badge, for features that are not available yet.
     */
    protected function comingSoonLabel(string $label): Htmlable
    {
        $lock = svg('heroicon-m-lock-closed', '', ['style' => 'width: 1rem; height: 1rem; color: rgb(156 163 175); flex-shrink: 0;'])->toHtml();
        $sparkles = svg('heroicon-m-sparkles', '', ['style' => 'width: 0.75rem; height: 0.75rem;'])->toHtml();

        return new HtmlString(
            '<span style="display: inline-flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">'
                .$lock
                .'<span>'.e($label).'</span>'
                .'<span style="display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.125rem 0.625rem; border-radius: 9999px;'
                .' font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: #fff;'
                .' background: linear-gradient(135deg, #f59e0b 0%, #f97316 55%, #ec4899 100%);'
                .' box-shadow: 0 1px 2px rgb(0 0 0 / 0.15), 0 0 0 1px rgb(255 255 255 / 0.15) inset;">'
                .$sparkles.'Coming soon'
                .'</span>'
            .'</span>'
        );
    }

    protected function canManageSettings(): bool
    {
        return (bool) auth()->user()?->canManageInvoiceSettings();
    }

    protected function saveCreateInvoicesViaApi(bool $enabled): void
    {
        if (! $this->canManageSettings()) {
            $this->settings['create_invoices_via_api'] = $this->createsInvoicesViaApi();

            Notification::make()
                ->danger()
                ->title('Only '.User::InvoiceSettingsManagerEmail.' can change this setting')
                ->send();

            return;
        }

        AppSetting::put(AppSetting::CreateInvoicesViaMollieApi, $enabled, auth()->user());

        Notification::make()
            ->success()
            ->title($enabled ? 'Invoices are now created via the Mollie API' : 'Invoices are now created in the Mollie dashboard')
            ->body($enabled ? '"Generate invoice" opens the form and sends the invoice from here.' : '"Generate invoice" opens Mollie Invoicing → Facturen.')
            ->send();
    }

    /**
     * Mollie Invoicing → Facturen in the dashboard, where "Aanmaken" creates a new invoice.
     */
    protected function mollieInvoicesUrl(): string
    {
        $organizationId = config('services.mollie.organization_id');

        return $organizationId
            ? "https://my.mollie.com/dashboard/{$organizationId}/invoice-ar/invoices"
            : 'https://my.mollie.com/dashboard';
    }

    /**
     * The months that can be invoiced: the current month and the 6 months before it, newest first.
     * Built from today's date, so a new month appears automatically when it starts.
     *
     * @return array<string, string>
     */
    protected function periodOptions(): array
    {
        return collect(range(0, 6))
            ->map(fn (int $monthsAgo): Carbon => now()->startOfMonth()->subMonthsNoOverflow($monthsAgo))
            ->mapWithKeys(fn (Carbon $month): array => [$month->toDateString() => $month->translatedFormat('F Y')])
            ->all();
    }

    /**
     * @return list<string>|null
     */
    protected function invoiceSummary(mixed $affiliateId, mixed $amount): ?array
    {
        $affiliate = filled($affiliateId) ? $this->invoicedAffiliatesQuery()->find($affiliateId) : null;

        if (! $affiliate || ! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        $net = (float) $amount;
        $vat = round($net * (float) InvoiceVat::rateFor($affiliate) / 100, 2);
        $format = fn (float $value): string => '€ '.number_format($value, 2, ',', '.');

        return [
            'To: '.$affiliate->invoice_company_name.' <'.$affiliate->invoice_email.'>',
            'Commission: '.$format($net),
            'VAT: '.InvoiceVat::explanation($affiliate).' = '.$format($vat),
            'Total: '.$format($net + $vat),
        ];
    }

    /**
     * @return array{affiliate_id: ?int, period: string, amount: null, description: string, payment_term: string}
     */
    protected function defaultInvoiceData(?Affiliate $affiliate = null): array
    {
        $period = now()->subMonthNoOverflow()->startOfMonth()->toDateString();

        return [
            'affiliate_id' => $affiliate?->getKey(),
            'period' => $period,
            'amount' => null,
            'description' => $this->defaultDescription($affiliate?->getKey(), $period),
            'payment_term' => PaymentTerm::DAYS_30,
        ];
    }

    protected function defaultDescription(mixed $affiliateId, ?string $period): string
    {
        $affiliate = filled($affiliateId) ? Affiliate::find($affiliateId) : null;

        return app(MollieSalesInvoices::class)->defaultDescription($affiliate, $period ? Carbon::parse($period) : now()->subMonthNoOverflow());
    }
}
