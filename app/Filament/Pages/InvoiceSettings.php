<?php

namespace App\Filament\Pages;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\Integration;
use App\Enums\MollieExportStatus;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Models\Affiliate;
use App\Services\IntegrationErrorLogger;
use App\Services\MollieInvoicingCustomersExport;
use App\Services\MollieSalesInvoices;
use App\Support\InvoiceAddressFormat;
use App\Support\InvoiceVat;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                $this->generateInvoiceAction(),
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
                    ->tooltip(fn (Affiliate $record): ?string => $this->whyNotInvoiceable($record))
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
                        ->body("Mollie: {$exception->getMessage()} Nothing was sent. The error is logged under System → Integration errors.")
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
