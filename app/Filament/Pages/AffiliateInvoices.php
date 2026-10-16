<?php

namespace App\Filament\Pages;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\Integration;
use App\Enums\InvoiceLanguage;
use App\Enums\MessageTemplateType;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Jobs\IssueAffiliateInvoice;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\MessageTemplate;
use App\Services\AffiliateMollieSync;
use App\Services\IntegrationErrorLogger;
use App\Services\MollieSalesInvoices;
use App\Services\SlackInvoiceMessages;
use App\Support\InvoiceAddressFormat;
use App\Support\InvoiceVat;
use App\Support\SlackMessagePreview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyEuro;

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
                            ->badge(fn (): ?int => $this->overdueInvoicesCount() ?: null)
                            ->badgeColor('danger')
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
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->state(fn (Affiliate $record): ?string => $record->latestInvoice ? $this->paymentLabel($record->latestInvoice) : null)
                    ->badge()
                    ->color(fn (Affiliate $record): string => $this->paymentColor($record->latestInvoice))
                    ->icon(fn (Affiliate $record): ?Heroicon => match (true) {
                        $record->latestInvoice === null => null,
                        $record->latestInvoice->isPaid() => Heroicon::OutlinedCheckCircle,
                        in_array($record->latestInvoice->status, ['failed', 'payment_reversed'], true) => Heroicon::OutlinedExclamationTriangle,
                        ! $record->latestInvoice->isAwaitingPayment() => Heroicon::OutlinedXCircle,
                        ($record->latestInvoice->daysUntilDue() ?? 1) < 0 => Heroicon::OutlinedExclamationTriangle,
                        default => Heroicon::OutlinedClock,
                    })
                    ->description(fn (Affiliate $record): ?string => $record->latestInvoice ? $this->paymentDescription($record->latestInvoice) : null)
                    ->tooltip(fn (Affiliate $record): ?string => $record->latestInvoice?->status_checked_at
                        ? 'Checked with Mollie '.$record->latestInvoice->status_checked_at->diffForHumans()
                        : null)
                    ->placeholder('-'),
            ])
            ->headerActions([
                Action::make('refreshPaymentStatus')
                    ->label('Refresh payment status')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(function (MollieSalesInvoices $invoices, IntegrationErrorLogger $errorLogger): void {
                        $openInvoices = AffiliateInvoice::query()->with('affiliate')->notFinal()->get();
                        $failed = 0;

                        foreach ($openInvoices as $invoice) {
                            try {
                                $invoices->refreshStatus($invoice);
                            } catch (Throwable $exception) {
                                $failed++;
                                $errorLogger->record(Integration::Mollie, 'Check payment status', $exception, $invoice->affiliate);
                            }
                        }

                        Notification::make()
                            ->{$failed > 0 ? 'warning' : 'success'}()
                            ->title(trans_choice('{0} No open invoices to check|{1} Checked 1 open invoice|[2,*] Checked :count open invoices', $openInvoices->count()))
                            ->body($failed > 0 ? "{$failed} could not be checked; see System → Integration errors." : null)
                            ->send();
                    }),
                $this->monthlyInvoicesAction(),
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
        $overdue = $this->overdueInvoicesCount();

        return 'Active affiliates with payment method "Invoice". "Generate invoice" creates and emails the invoice through Mollie; the payment status is checked with Mollie every hour.'
            .($overdue > 0 ? ' '.trans_choice('{1} 1 invoice is overdue.|[2,*] :count invoices are overdue.', $overdue) : '');
    }

    /**
     * The number of unpaid invoices past their due date.
     */
    protected function overdueInvoicesCount(): int
    {
        return AffiliateInvoice::query()->overdue()->count();
    }

    protected function paymentLabel(AffiliateInvoice $invoice): string
    {
        $finalLabel = match ($invoice->status) {
            'paid' => 'Paid',
            'cancelled' => 'Cancelled',
            'expired' => 'Expired',
            'failed' => 'Failed',
            'payment_reversed' => 'Payment reversed',
            'draft' => 'Draft',
            default => null,
        };

        if ($finalLabel !== null) {
            return $finalLabel;
        }

        $days = $invoice->daysUntilDue();

        return match (true) {
            $days === null => $invoice->status === 'pending-payment' ? 'Payment pending' : 'Open',
            $days > 1 => "Due in {$days} days",
            $days === 1 => 'Due tomorrow',
            $days === 0 => 'Due today',
            $days === -1 => 'Overdue · 1 day',
            default => 'Overdue · '.abs($days).' days',
        };
    }

    protected function paymentColor(?AffiliateInvoice $invoice): string
    {
        if (! $invoice) {
            return 'gray';
        }

        $days = $invoice->daysUntilDue();

        return match (true) {
            $invoice->isPaid() => 'success',
            in_array($invoice->status, ['failed', 'payment_reversed'], true) => 'danger',
            ! $invoice->isAwaitingPayment() => 'gray',
            $days === null => 'info',
            $days < 0 => 'danger',
            $days <= 3 => 'warning',
            default => 'info',
        };
    }

    protected function paymentDescription(AffiliateInvoice $invoice): ?string
    {
        if ($invoice->isPaid()) {
            return $invoice->paid_at ? 'Paid on '.$invoice->paid_at->translatedFormat('j M Y') : null;
        }

        return $invoice->isAwaitingPayment() && $invoice->due_at ? 'Due '.$invoice->due_at->translatedFormat('j M Y') : null;
    }

    /**
     * Invoice every affiliate for one month at once: fill in the amounts and send them all in the background.
     */
    protected function monthlyInvoicesAction(): Action
    {
        return Action::make('monthlyInvoices')
            ->label('Monthly invoices')
            ->icon(Heroicon::OutlinedQueueList)
            ->color('gray')
            ->modalHeading('Monthly invoices')
            ->modalDescription('Fill in the commission for each affiliate and send all invoices at once. Each one is created and emailed by Mollie with the default email template in the partner\'s language. Leave an amount empty to skip that affiliate.')
            ->modalWidth(Width::SixExtraLarge)
            ->modalSubmitActionLabel('Send all invoices')
            ->fillForm(fn (): array => [
                'period' => $period = now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'payment_term' => PaymentTerm::DAYS_30,
                'post_to_slack' => true,
                'invoices' => $this->monthlyInvoiceRows($period),
            ])
            ->schema([
                Grid::make(3)->schema([
                    Select::make('period')
                        ->label('Invoice period')
                        ->options(fn (): array => $this->periodOptions())
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => $set('invoices', $this->monthlyInvoiceRows($state, $get('invoices') ?? []))),
                    Select::make('payment_term')
                        ->label('Payment term')
                        ->options([
                            PaymentTerm::DAYS_7 => '7 days',
                            PaymentTerm::DAYS_14 => '14 days',
                            PaymentTerm::DAYS_30 => '30 days',
                        ])
                        ->selectablePlaceholder(false)
                        ->required(),
                    Toggle::make('post_to_slack')
                        ->label('Post in Slack')
                        ->helperText('Message with the invoice PDF in each partner\'s channel.')
                        ->inline(false),
                ]),
                Repeater::make('invoices')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Affiliate'),
                        TableColumn::make('Amount (excl. VAT)')->width('11rem'),
                        TableColumn::make('Invoice line'),
                        TableColumn::make('Last invoice')->width('13rem'),
                    ])
                    ->schema([
                        Hidden::make('affiliate_id'),
                        Hidden::make('blocked_reason'),
                        TextEntry::make('name')
                            ->hiddenLabel()
                            ->weight('medium')
                            ->helperText(fn (Get $get): ?string => $get('blocked_reason')),
                        TextInput::make('amount')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->prefix('€')
                            ->placeholder('Skip')
                            ->disabled(fn (Get $get): bool => filled($get('blocked_reason'))),
                        TextInput::make('description')
                            ->hiddenLabel()
                            ->maxLength(100)
                            ->required(fn (Get $get): bool => filled($get('amount')))
                            ->disabled(fn (Get $get): bool => filled($get('blocked_reason'))),
                        TextEntry::make('previous')
                            ->hiddenLabel()
                            ->color('gray')
                            ->placeholder('Not invoiced yet'),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->live(),
                TextEntry::make('monthly_summary')
                    ->hiddenLabel()
                    ->state(function (Get $get): string {
                        $rows = collect($get('invoices') ?? [])->filter(fn (array $row): bool => blank($row['blocked_reason'] ?? null) && is_numeric($row['amount'] ?? null) && (float) $row['amount'] > 0);

                        return trans_choice('{0} No invoices to send yet: fill in an amount.|{1} 1 invoice of :total excl. VAT will be sent.|[2,*] :count invoices, together :total excl. VAT, will be sent.', $rows->count(), [
                            'total' => '€ '.number_format($rows->sum(fn (array $row): float => (float) $row['amount']), 2, ',', '.'),
                        ]);
                    })
                    ->weight('medium'),
            ])
            ->action(function (array $data, Action $action): void {
                $period = Carbon::parse($data['period']);
                $rows = collect($data['invoices'] ?? [])->filter(fn (array $row): bool => is_numeric($row['amount'] ?? null) && (float) $row['amount'] > 0);
                $affiliates = $this->invoiceableAffiliatesQuery()->whereKey($rows->pluck('affiliate_id'))->get()->keyBy('id');
                $queued = 0;

                foreach ($rows as $row) {
                    $affiliate = $affiliates->get($row['affiliate_id']);

                    if (! $affiliate || AffiliateInvoice::invoicedFor($affiliate, $period)) {
                        continue;
                    }

                    IssueAffiliateInvoice::dispatch(
                        $affiliate->getKey(),
                        $period->toDateString(),
                        (string) $row['amount'],
                        $row['description'],
                        $data['payment_term'],
                        (bool) ($data['post_to_slack'] ?? false),
                        auth()->id(),
                    );

                    $queued++;
                }

                if ($queued === 0) {
                    Notification::make()
                        ->warning()
                        ->title('No invoices to send')
                        ->body('Fill in an amount for at least one affiliate that has not been invoiced for '.$period->translatedFormat('F Y').' yet.')
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title(trans_choice('{1} Sending 1 invoice|[2,*] Sending :count invoices', $queued).' for '.$period->translatedFormat('F Y'))
                    ->body('Mollie creates and emails them in the background; they show up in this list within a minute. Problems show up under System → Integration errors.')
                    ->send();
            });
    }

    /**
     * One row per affiliate paid by invoice for the month, keeping amounts already typed in when the month changes.
     *
     * @param  array<array-key, array<string, mixed>>  $previousRows
     * @return list<array{affiliate_id: int, name: string, blocked_reason: ?string, amount: mixed, description: string, previous: ?string}>
     */
    protected function monthlyInvoiceRows(?string $period, array $previousRows = []): array
    {
        $period = Carbon::parse($period ?? now()->subMonthNoOverflow())->startOfMonth();
        $typedAmounts = collect($previousRows)->pluck('amount', 'affiliate_id');

        return $this->invoicedAffiliatesQuery()
            ->with('latestInvoice')
            ->orderBy('name')
            ->get()
            ->map(function (Affiliate $affiliate) use ($period, $typedAmounts): array {
                $invoiced = AffiliateInvoice::invoicedFor($affiliate, $period);
                $last = $affiliate->latestInvoice;

                return [
                    'affiliate_id' => $affiliate->getKey(),
                    'name' => $affiliate->name,
                    'blocked_reason' => match (true) {
                        $invoiced !== null => 'Already invoiced'.($invoiced->invoice_number ? " ({$invoiced->invoice_number})" : ''),
                        ! $affiliate->hasCompleteInvoiceDetails() => 'Invoice details incomplete',
                        default => null,
                    },
                    'amount' => $invoiced ? null : $typedAmounts->get($affiliate->getKey()),
                    'description' => $this->defaultDescription($affiliate->getKey(), $period->toDateString()),
                    'previous' => $last
                        ? '€ '.number_format((float) $last->amount, 2, ',', '.').' · '.$last->period->translatedFormat('M Y')
                        : null,
                ];
            })
            ->values()
            ->all();
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
                TextInput::make('description')
                    ->label('Invoice line')
                    ->required()
                    ->maxLength(100)
                    ->live(debounce: 400)
                    ->helperText(fn (?string $state): string => mb_strlen((string) $state).' / 100 characters (Mollie\'s limit for an invoice line)'),
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
                    ->helperText(MessageTemplateResource::placeholderHelp(MessageTemplateType::MollieInvoice))
                    ->visible(fn (Get $get): bool => $get('email_template') === self::CustomEmail),
                Toggle::make('post_to_slack')
                    ->label(fn (Get $get): string => ($channel = $this->slackChannelName($get('affiliate_id'))) ? "Post in Slack (#{$channel})" : 'Post in Slack')
                    ->helperText('Posts a message with the invoice PDF in the partner\'s Slack channel after the invoice was sent.')
                    ->default(true)
                    ->live()
                    ->visible(fn (Get $get): bool => $this->slackChannelName($get('affiliate_id')) !== null),
                TextEntry::make('slack_preview')
                    ->label('Slack message')
                    ->state(fn (Get $get): ?Htmlable => ($message = $this->slackPreview($get('affiliate_id'), $get('period'), $get('amount')))
                        ? SlackMessagePreview::html($message, 'Invoice PDF')
                        : null)
                    ->placeholder('Fill in the amount to see the Slack message.')
                    ->visible(fn (Get $get): bool => $get('post_to_slack') && $this->slackChannelName($get('affiliate_id')) !== null),
                TextEntry::make('invoice_summary')
                    ->label('Invoice')
                    ->state(fn (Get $get): ?array => $this->invoiceSummary($get('affiliate_id'), $get('amount')))
                    ->listWithLineBreaks()
                    ->placeholder('Pick an affiliate and an amount to see the VAT and total.'),
            ])
            ->action(function (array $data, MollieSalesInvoices $invoices, SlackInvoiceMessages $slackMessages, IntegrationErrorLogger $errorLogger): void {
                $affiliate = $this->invoiceableAffiliatesQuery()->findOrFail($data['affiliate_id']);

                abort_if($this->whyNotInvoiceable($affiliate) !== null, 422);

                $period = Carbon::parse($data['period']);
                $email = $data['email_template'] === self::CustomEmail
                    ? [
                        'subject' => MessageTemplate::fillPlaceholders($data['email_subject'], $affiliate, $period, $this->languageOf($affiliate)),
                        'body' => MessageTemplate::fillPlaceholders($data['email_body'], $affiliate, $period, $this->languageOf($affiliate)),
                    ]
                    : MessageTemplate::findOrFail($data['email_template'])->renderFor($affiliate, $period);

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

                if (($data['post_to_slack'] ?? false) && $slackMessages->canPost($affiliate)) {
                    try {
                        $slackMessages->post($affiliate, $invoice);

                        Notification::make()
                            ->success()
                            ->title("Posted in Slack #{$affiliate->slack_channel_name}")
                            ->send();
                    } catch (Throwable $exception) {
                        $errorLogger->record(Integration::Slack, 'Post invoice message', $exception, $affiliate);

                        Notification::make()
                            ->warning()
                            ->title('The invoice was sent, but the Slack message failed')
                            ->body(IntegrationErrorLogger::readableMessage($exception).' The error is logged under System → Integration errors.')
                            ->persistent()
                            ->send();
                    }
                }
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
     * @return array{affiliate_id: ?int, period: string, amount: null, description: string, payment_term: string, email_template: ?string, post_to_slack: bool}
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
            'post_to_slack' => true,
        ];
    }

    /**
     * The Mollie invoice email templates to choose from, plus writing a custom email.
     *
     * @return array<string, array<string, string>>
     */
    protected function emailTemplateOptions(): array
    {
        return MessageTemplate::query()
            ->where('type', MessageTemplateType::MollieInvoice)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->sortBy(fn (MessageTemplate $template): int => array_search($template->language, InvoiceLanguage::cases(), true))
            ->groupBy(fn (MessageTemplate $template): string => $template->language->getLabel())
            ->map(fn ($templates): array => $templates->mapWithKeys(fn (MessageTemplate $template): array => [(string) $template->getKey() => $template->name])->all())
            ->put('Other', [self::CustomEmail => 'Custom – write the email for this invoice'])
            ->all();
    }

    /**
     * The default Mollie invoice template in the affiliate's language.
     */
    protected function defaultEmailTemplateId(mixed $affiliateId): ?string
    {
        $affiliate = filled($affiliateId) ? Affiliate::find($affiliateId) : null;
        $template = MessageTemplate::defaultFor(MessageTemplateType::MollieInvoice, $affiliate ? $this->languageOf($affiliate) : InvoiceLanguage::Dutch);

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
        $template = filled($templateId) && $templateId !== self::CustomEmail ? MessageTemplate::find($templateId) : null;

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

    /**
     * The affiliate's Slack channel name when a message can be posted there, otherwise null.
     */
    protected function slackChannelName(mixed $affiliateId): ?string
    {
        $affiliate = filled($affiliateId) ? Affiliate::find($affiliateId) : null;

        return $affiliate && app(SlackInvoiceMessages::class)->canPost($affiliate) ? $affiliate->slack_channel_name : null;
    }

    /**
     * The Slack message as it will look, before the invoice exists: the invoice number is assigned by Mollie.
     */
    protected function slackPreview(mixed $affiliateId, ?string $period, mixed $amount): ?string
    {
        $affiliate = filled($affiliateId) ? $this->invoicedAffiliatesQuery()->find($affiliateId) : null;

        if (! $affiliate || ! is_numeric($amount) || (float) $amount <= 0) {
            return null;
        }

        $net = (float) $amount;
        $total = $net + round($net * (float) InvoiceVat::rateFor($affiliate) / 100, 2);

        return app(SlackInvoiceMessages::class)->message(
            $affiliate,
            $period ? Carbon::parse($period) : now()->subMonthNoOverflow(),
            '[invoice number]',
            SlackInvoiceMessages::formatAmount($total),
            (string) $affiliate->invoice_email,
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
