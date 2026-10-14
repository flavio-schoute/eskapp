<?php

namespace App\Filament\Pages;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\EmailTemplateType;
use App\Enums\Integration;
use App\Enums\InvoiceLanguage;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Models\Affiliate;
use App\Models\EmailTemplate;
use App\Services\AffiliateMollieSync;
use App\Services\IntegrationErrorLogger;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Mollie\Api\Types\PaymentTerm;
use Throwable;
use UnitEnum;

/**
 * Invoicing affiliates: who can be invoiced, their Mollie customer and last invoice, and "Generate invoice".
 */
class AffiliateInvoices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Invoices';

    protected static ?string $slug = 'invoices';

    /**
     * The email template option for writing a custom email for one invoice.
     */
    private const CustomEmail = 'custom';

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Invoice providers')
                    ->tabs([
                        Tab::make('Mollie')
                            ->icon(Heroicon::OutlinedCreditCard)
                            ->badge(fn (): ?int => $this->invoicedAffiliatesQuery()->whereNull('mollie_customer_id')->count() ?: null)
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
            ->description(fn (): string => $this->tableDescription())
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
                TextColumn::make('mollie_customer_id')
                    ->label('Mollie customer')
                    ->formatStateUsing(fn (): string => 'In Mollie')
                    ->badge()
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->description(fn (Affiliate $record): ?string => $record->mollie_customer_id)
                    ->copyable()
                    ->copyableState(fn (Affiliate $record): ?string => $record->mollie_customer_id)
                    ->copyMessage('Mollie customer ID copied')
                    ->placeholder('Not in Mollie yet')
                    ->tooltip(fn (Affiliate $record): string => filled($record->mollie_customer_id)
                        ? 'Click to copy the Mollie customer ID.'
                        : ($this->missingDetailsTooltip($record) ?? 'Click "Add to Mollie" to create the customer now.')),
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
                $this->generateInvoiceAction(),
            ])
            ->recordActions([
                Action::make('addToMollie')
                    ->label('Add to Mollie')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->color('gray')
                    ->link()
                    ->visible(fn (Affiliate $record): bool => blank($record->mollie_customer_id) && $record->hasCompleteInvoiceDetails())
                    ->action(function (Affiliate $record, AffiliateMollieSync $sync, IntegrationErrorLogger $errorLogger): void {
                        try {
                            $customerId = $sync->ensureCustomer($record);
                        } catch (Throwable $exception) {
                            $errorLogger->record(Integration::Mollie, 'Create customer', $exception, $record);

                            Notification::make()
                                ->danger()
                                ->title("Could not add {$record->name} to Mollie")
                                ->body(IntegrationErrorLogger::readableMessage($exception))
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title("{$record->name} is now in Mollie")
                            ->body("Mollie customer ID: {$customerId}")
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
            ->whereNotNull('invoice_kvk_number')->where('invoice_kvk_number', '!=', '')
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
     * complete. The Sales Invoices API sends the full customer details with every invoice.
     */
    protected function whyNotInvoiceable(Affiliate $affiliate): ?string
    {
        return $this->missingDetailsTooltip($affiliate);
    }

    /**
     * A short reason shown next to an affiliate that cannot be picked in the generate invoice form.
     */
    protected function shortReasonNotInvoiceable(Affiliate $affiliate): ?string
    {
        return $affiliate->hasCompleteInvoiceDetails() ? null : 'invoice details incomplete';
    }

    protected function tableDescription(): string
    {
        $notInMollie = $this->invoicedAffiliatesQuery()->whereNull('mollie_customer_id')->count();

        return 'Active affiliates with payment method "Invoice". Partners with complete invoice details get a Mollie customer automatically, and "Generate invoice" creates and emails the invoice through Mollie.'
            .($notInMollie > 0 ? ' '.trans_choice('{1} 1 partner is not in Mollie yet.|[2,*] :count partners are not in Mollie yet.', $notInMollie) : '');
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
                    ->helperText('Greyed-out affiliates need complete invoice details first.')
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $set('description', $this->defaultDescription($get('affiliate_id'), $get('period')));

                        if ($get('email_template') !== self::CustomEmail) {
                            $set('email_template', $this->defaultEmailTemplateId($get('affiliate_id')));
                        }
                    }),
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
                Select::make('email_template')
                    ->label('Email template')
                    ->options(fn (): array => $this->emailTemplateOptions())
                    ->helperText(fn (Get $get): ?string => $this->emailTemplateHint($get('affiliate_id')))
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if ($state === self::CustomEmail) {
                            $email = $this->renderEmail($get('affiliate_id'), $get('period'), $this->defaultEmailTemplateId($get('affiliate_id')));
                            $set('email_subject', $email['subject'] ?? '');
                            $set('email_body', $email['body'] ?? '');
                        }
                    }),
                TextEntry::make('email_preview')
                    ->label('Email')
                    ->state(fn (Get $get): ?Htmlable => ($email = $this->renderEmail($get('affiliate_id'), $get('period'), $get('email_template')))
                        ? $this->emailPreview($email)
                        : null)
                    ->placeholder('Pick an affiliate to see the email.')
                    ->visible(fn (Get $get): bool => $get('email_template') !== self::CustomEmail),
                TextInput::make('email_subject')
                    ->label('Email subject')
                    ->required()
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('email_template') === self::CustomEmail),
                Textarea::make('email_body')
                    ->label('Email text')
                    ->rows(8)
                    ->required()
                    ->maxLength(5000)
                    ->helperText(EmailTemplateResource::placeholderHelp(EmailTemplateType::MollieInvoice))
                    ->visible(fn (Get $get): bool => $get('email_template') === self::CustomEmail),
                TextEntry::make('invoice_summary')
                    ->label('Invoice')
                    ->state(fn (Get $get): ?array => $this->invoiceSummary($get('affiliate_id'), $get('amount')))
                    ->listWithLineBreaks()
                    ->placeholder('Pick an affiliate and an amount to see the VAT and total.'),
            ])
            ->action(function (array $data, MollieSalesInvoices $invoices, IntegrationErrorLogger $errorLogger): void {
                $affiliate = $this->invoiceableAffiliatesQuery()->findOrFail($data['affiliate_id']);

                abort_if($this->whyNotInvoiceable($affiliate) !== null, 422);

                $period = Carbon::parse($data['period']);
                $email = $data['email_template'] === self::CustomEmail
                    ? [
                        'subject' => EmailTemplate::fillPlaceholders($data['email_subject'], $affiliate, $period, $this->languageOf($affiliate)),
                        'body' => EmailTemplate::fillPlaceholders($data['email_body'], $affiliate, $period, $this->languageOf($affiliate)),
                    ]
                    : EmailTemplate::findOrFail($data['email_template'])->renderFor($affiliate, $period);

                try {
                    $invoice = $invoices->issue(
                        $affiliate,
                        $period,
                        (string) $data['amount'],
                        $data['description'],
                        $data['payment_term'],
                        $email['subject'],
                        $email['body'],
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
     * @return array{affiliate_id: ?int, period: string, amount: null, description: string, payment_term: string, email_template: ?string}
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
            'email_template' => $this->defaultEmailTemplateId($affiliate?->getKey()),
        ];
    }

    /**
     * The Mollie invoice email templates to choose from, plus writing a custom email.
     *
     * @return array<string, array<string, string>>
     */
    protected function emailTemplateOptions(): array
    {
        return EmailTemplate::query()
            ->where('type', EmailTemplateType::MollieInvoice)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (EmailTemplate $template): string => $template->language->getLabel())
            ->map(fn ($templates): array => $templates->mapWithKeys(fn (EmailTemplate $template): array => [(string) $template->getKey() => $template->name])->all())
            ->put('Other', [self::CustomEmail => 'Custom – write the email for this invoice'])
            ->all();
    }

    /**
     * The default Mollie invoice template in the affiliate's language.
     */
    protected function defaultEmailTemplateId(mixed $affiliateId): ?string
    {
        $affiliate = filled($affiliateId) ? Affiliate::find($affiliateId) : null;
        $template = EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, $affiliate ? $this->languageOf($affiliate) : InvoiceLanguage::Dutch);

        return $template ? (string) $template->getKey() : null;
    }

    /**
     * A template's subject and body filled in for the affiliate and period.
     *
     * @return array{subject: string, body: string}|null
     */
    protected function renderEmail(mixed $affiliateId, ?string $period, mixed $templateId): ?array
    {
        $affiliate = filled($affiliateId) ? Affiliate::find($affiliateId) : null;
        $template = filled($templateId) && $templateId !== self::CustomEmail ? EmailTemplate::find($templateId) : null;

        if (! $affiliate || ! $template) {
            return null;
        }

        return $template->renderFor($affiliate, $period ? Carbon::parse($period) : now()->subMonthNoOverflow());
    }

    /**
     * Explains which template language was picked for the affiliate, and why.
     */
    protected function emailTemplateHint(mixed $affiliateId): ?string
    {
        $affiliate = filled($affiliateId) ? Affiliate::find($affiliateId) : null;

        if (! $affiliate) {
            return 'The default template in the partner\'s preferred language is picked automatically.';
        }

        $language = $this->languageOf($affiliate)->getLabel();
        $reason = $affiliate->invoice_language ? "preferred language ({$language})" : "country ({$language})";

        return "Picked automatically from {$affiliate->name}'s {$reason}. Choose another template or Custom to change the email.";
    }

    /**
     * The email as it will be sent: the subject in bold and the body with its line breaks and blank lines.
     *
     * @param  array{subject: string, body: string}  $email
     */
    protected function emailPreview(array $email): Htmlable
    {
        return new HtmlString(
            '<div style="border: 1px solid rgb(127 127 127 / 0.25); border-radius: 0.5rem; padding: 0.75rem 1rem;">'
                .'<div style="font-weight: 600; padding-bottom: 0.5rem; margin-bottom: 0.5rem; border-bottom: 1px solid rgb(127 127 127 / 0.2);">'.e($email['subject']).'</div>'
                .'<div style="white-space: pre-line;">'.e($email['body']).'</div>'
            .'</div>'
        );
    }

    protected function languageOf(Affiliate $affiliate): InvoiceLanguage
    {
        return $affiliate->invoice_language ?? InvoiceLanguage::suggestedFor($affiliate->invoice_country);
    }

    protected function defaultDescription(mixed $affiliateId, ?string $period): string
    {
        $affiliate = filled($affiliateId) ? Affiliate::find($affiliateId) : null;

        return app(MollieSalesInvoices::class)->defaultDescription($affiliate, $period ? Carbon::parse($period) : now()->subMonthNoOverflow());
    }
}
