<?php

namespace App\Filament\Resources\Affiliates\Schemas;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Enums\InvoiceLanguage;
use App\Filament\Resources\SlackMembers\SlackMemberResource;
use App\Models\Affiliate;
use App\Services\ViesVatValidator;
use App\Support\InvoiceAddressFormat;
use App\Support\ViesAddress;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class AffiliateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basics')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Partner name')
                            ->required()
                            ->maxLength(255)
                            ->live(debounce: 400),
                        Select::make('type')
                            ->options(AffiliateType::class)
                            ->required()
                            ->live(),
                        Select::make('status')
                            ->options(AffiliateStatus::class)
                            ->default(fn (): AffiliateStatus => AffiliateStatus::tryFrom((string) request()->query('status')) ?? AffiliateStatus::Active)
                            ->required(),
                        TextEntry::make('slack_channel_preview')
                            ->label('Slack channel')
                            ->state(fn (Get $get): ?string => filled($get('name')) ? '#'.Affiliate::slackChannelNameFor($get('name'), $get('type')) : null)
                            ->url(fn (?Affiliate $record): ?string => $record?->slackChannelUrl(), shouldOpenInNewTab: true)
                            ->helperText(fn (?Affiliate $record): string => filled($record?->slack_channel_id) ? 'Private channel' : 'Created as a private channel when you save')
                            ->color('primary')
                            ->placeholder('Fill in the partner name'),
                        TextInput::make('slack_contact_name')
                            ->label('Slack contact person')
                            ->placeholder('Name of the partner\'s contact in Slack')
                            ->maxLength(255),
                        SlackMemberResource::memberIdField('slack_contact_member_id')
                            ->helperText('Invited to the partner\'s Slack channel automatically. In Slack: profile → ⋮ (more) → Copy member ID.')
                            ->columnSpan(2),
                        Select::make('slack_contact_language')
                            ->label('Slack language')
                            ->options(InvoiceLanguage::class)
                            ->placeholder('Same as invoice language')
                            ->helperText('Language of the Slack messages to this contact.'),
                    ]),
                Section::make('Access')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('login_url')
                            ->label('Login URL (dashboard / backend)')
                            ->url()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('username')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->maxLength(255),
                    ]),
                Section::make('Agreement')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('agreement')
                            ->label('Agreement (short and clear)')
                            ->placeholder("E.g. 20% recurring per sale.\nMonthly automatic payout.\nTracking via affiliate link.")
                            ->rows(4),
                        Select::make('payment_method')
                            ->label('Payment')
                            ->options(AffiliatePaymentMethod::class)
                            ->required()
                            ->live(),
                        FileUpload::make('agreement_upload')
                            ->label(fn (?Affiliate $record): string => filled($record?->agreement_drive_url) ? 'Replace agreement (PDF)' : 'Agreement (PDF, optional)')
                            ->helperText('Uploaded to the affiliate\'s Google Drive folder when you save.')
                            ->storeFiles(false)
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(20480),
                        TextEntry::make('agreement_drive_url')
                            ->label('Current agreement in Google Drive')
                            ->formatStateUsing(fn (Affiliate $record): ?string => $record->agreement_file_name)
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->color('primary')
                            ->visibleOn('edit')
                            ->placeholder('No agreement uploaded yet'),
                    ]),
                Section::make('Invoice details')
                    ->description('Who we invoice or who invoices us. The address labels follow the chosen country.')
                    ->visible(fn (Get $get): bool => (bool) AffiliatePaymentMethod::fromState($get('payment_method'))?->requiresInvoiceDetails())
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('invoice_company_name')
                            ->label('Company name')
                            ->helperText('Full legal name, including the company type (e.g. B.V. or Co., Ltd).')
                            ->required()
                            ->maxLength(255)
                            ->live(debounce: 400),
                        TextInput::make('invoice_contact_person')
                            ->label('Contact person')
                            ->maxLength(255),
                        TextInput::make('invoice_kvk_number')
                            ->label(fn (Get $get): string => InvoiceAddressFormat::registrationNumberLabel($get('invoice_country')))
                            ->placeholder(fn (Get $get): ?string => InvoiceAddressFormat::registrationNumberPlaceholder($get('invoice_country')))
                            ->regex(fn (Get $get): string => $get('invoice_country') === 'NL' ? '/^\s*(\d\s*){8}$/' : '/^[\p{L}\p{N} .\-\/]{2,20}$/u')
                            ->validationMessages(['regex' => 'A KvK number has 8 digits; other registration numbers are at most 20 letters or digits.'])
                            ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => blank($state) ? null : ($get('invoice_country') === 'NL' ? preg_replace('/\s+/', '', $state) : trim($state)))
                            ->required()
                            ->helperText('Required: Mollie needs it for business invoices.'),
                        TextInput::make('invoice_vat_number')
                            ->label(fn (Get $get): string => $get('invoice_country') === 'NL' ? 'VAT number (BTW)' : 'VAT number')
                            ->placeholder(fn (Get $get): string => InvoiceAddressFormat::vatNumberPlaceholder($get('invoice_country')))
                            ->helperText('Dots, spaces and dashes are fine (e.g. BE 0123.456.789); it is saved as BE0123456789. EU numbers are checked with VIES as soon as you leave the field, and empty address fields are filled in.')
                            ->regex('/^\s*[A-Za-z]{2}[A-Za-z0-9 .+*\-]{2,20}\s*$/')
                            ->validationMessages(['regex' => 'A VAT number starts with the 2-letter country code, e.g. NL123456789B01.'])
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $vies = app(ViesVatValidator::class);

                                if (blank($value) || ! $vies->isEnabled() || ! ViesVatValidator::isEuVatNumber((string) $value)) {
                                    return;
                                }

                                if ($vies->check((string) $value)['status'] === ViesVatValidator::Invalid) {
                                    $fail('VIES says this VAT number is not valid or not active. Check the number with the partner.');
                                }
                            })
                            ->suffixAction(
                                Action::make('checkVatNumber')
                                    ->label('Check with VIES')
                                    ->icon(Heroicon::OutlinedShieldCheck)
                                    ->tooltip('Check with VIES')
                                    ->action(function (?string $state, Get $get, Set $set): void {
                                        if (blank($state)) {
                                            return;
                                        }

                                        $result = app(ViesVatValidator::class)->check($state);
                                        $vatNumber = ViesVatValidator::normalize($state);

                                        if ($result['status'] === ViesVatValidator::Valid) {
                                            $filled = static::fillFromVies($vatNumber, $result, $get, $set);

                                            Notification::make()
                                                ->success()
                                                ->title("{$vatNumber} is valid")
                                                ->body(collect([
                                                    collect([$result['name'], $result['address'] ? str_replace("\n", ', ', $result['address']) : null])->filter()->implode(' · '),
                                                    $filled ? 'Filled in: '.implode(', ', $filled).'.' : null,
                                                ])->filter()->implode("\n") ?: null)
                                                ->send();

                                            return;
                                        }

                                        match ($result['status']) {
                                            ViesVatValidator::Invalid => Notification::make()
                                                ->danger()
                                                ->title("{$vatNumber} is not valid according to VIES")
                                                ->send(),
                                            ViesVatValidator::NotEu => Notification::make()
                                                ->info()
                                                ->title('VIES only checks EU VAT numbers')
                                                ->send(),
                                            default => Notification::make()
                                                ->warning()
                                                ->title('VIES is not available right now')
                                                ->body('The VAT number could not be checked; try again later.')
                                                ->send(),
                                        };
                                    })
                            )
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                $vies = app(ViesVatValidator::class);

                                if (blank($state) || ! $vies->isEnabled() || ! ViesVatValidator::isEuVatNumber($state)) {
                                    return;
                                }

                                $vatNumber = ViesVatValidator::normalize($state);
                                $result = $vies->check($vatNumber);

                                if ($result['status'] === ViesVatValidator::Valid) {
                                    $filled = static::fillFromVies($vatNumber, $result, $get, $set);

                                    Notification::make()
                                        ->success()
                                        ->title("{$vatNumber} is valid")
                                        ->body($filled ? 'Filled in from VIES: '.implode(', ', $filled).'.' : null)
                                        ->send();
                                } elseif ($result['status'] === ViesVatValidator::Invalid) {
                                    Notification::make()
                                        ->danger()
                                        ->title("{$vatNumber} is not valid according to VIES")
                                        ->send();
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? ViesVatValidator::normalize($state) : null),
                        TextInput::make('invoice_email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->columnStart(1),
                        TextInput::make('invoice_phone')
                            ->label('Phone number')
                            ->tel()
                            ->placeholder(fn (Get $get): string => InvoiceAddressFormat::phonePlaceholder($get('invoice_country')))
                            ->maxLength(50),
                        TextInput::make('invoice_address_line_1')
                            ->label('Street and house number')
                            ->placeholder(fn (Get $get): string => InvoiceAddressFormat::addressPlaceholder($get('invoice_country')))
                            ->required()
                            ->maxLength(255)
                            ->live(debounce: 400),
                        TextInput::make('invoice_address_line_2')
                            ->label('Address line 2')
                            ->placeholder('Building, floor, suite')
                            ->maxLength(255)
                            ->live(debounce: 400),
                        TextInput::make('invoice_postal_code')
                            ->label(fn (Get $get): string => InvoiceAddressFormat::postalCodeLabel($get('invoice_country')))
                            ->placeholder(fn (Get $get): ?string => InvoiceAddressFormat::postalCodePlaceholder($get('invoice_country')))
                            ->required()
                            ->maxLength(20)
                            ->live(debounce: 400),
                        TextInput::make('invoice_city')
                            ->label('City')
                            ->required()
                            ->maxLength(255)
                            ->live(debounce: 400),
                        Select::make('invoice_country')
                            ->label('Country')
                            ->options(InvoiceAddressFormat::countryOptions())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set('invoice_language', InvoiceLanguage::suggestedFor($state)->value)),
                        Select::make('invoice_language')
                            ->label('Preferred language')
                            ->helperText('Suggested from the country; change it if the partner prefers another language.')
                            ->options(InvoiceLanguage::class)
                            ->default(InvoiceLanguage::English->value)
                            ->selectablePlaceholder(false),
                        TextInput::make('invoice_region')
                            ->label(fn (Get $get): string => InvoiceAddressFormat::regionLabel($get('invoice_country')))
                            ->maxLength(255)
                            ->live(debounce: 400)
                            ->hidden(fn (Get $get): bool => in_array($get('invoice_country'), [null, 'NL', 'BE', 'DE', 'FR', 'LU', 'AT'], true)),
                        TextEntry::make('mollie_customer_id')
                            ->label('Mollie customer')
                            ->formatStateUsing(fn (string $state): string => "In Mollie · {$state}")
                            ->badge()
                            ->color('success')
                            ->icon(Heroicon::OutlinedCheckCircle)
                            ->copyable()
                            ->copyableState(fn (?Affiliate $record): ?string => $record?->mollie_customer_id)
                            ->copyMessage('Mollie customer ID copied')
                            ->placeholder('Not in Mollie yet: created automatically when you save with complete invoice details.')
                            ->visibleOn('edit')
                            ->columnSpanFull(),
                        TextEntry::make('invoice_address_preview')
                            ->label('Address as it appears on an invoice')
                            ->state(fn (Get $get): array => InvoiceAddressFormat::lines([
                                'company_name' => $get('invoice_company_name'),
                                'address_line_1' => $get('invoice_address_line_1'),
                                'address_line_2' => $get('invoice_address_line_2'),
                                'postal_code' => $get('invoice_postal_code'),
                                'city' => $get('invoice_city'),
                                'region' => $get('invoice_region'),
                                'country' => $get('invoice_country'),
                            ]))
                            ->listWithLineBreaks()
                            ->placeholder('Fill in the address to see a preview')
                            ->columnSpanFull(),
                    ]),
                Section::make('Links')
                    ->description('Optional, only if relevant.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('affiliate_link')
                            ->label('Affiliate / tracking link')
                            ->url()
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->label('Extra notes')
                            ->helperText('Max 1–2 lines, otherwise leave empty.')
                            ->rows(2),
                    ]),
            ]);
    }

    /**
     * Fill the empty invoice fields with the company and address VIES registered for the VAT number.
     *
     * @param  array{status: string, name: ?string, address: ?string}  $result
     * @return list<string> The labels of the fields that were filled in.
     */
    public static function fillFromVies(string $vatNumber, array $result, Get $get, Set $set): array
    {
        $country = ViesAddress::countryFromVatNumber($vatNumber);
        $address = $result['address'] ? ViesAddress::parse($result['address'], (string) ($get('invoice_country') ?: $country)) : [];

        $values = [
            'invoice_company_name' => ['Company name', $result['name'] ? ViesAddress::companyName($result['name']) : null],
            'invoice_address_line_1' => ['Street and house number', $address['street'] ?? null],
            'invoice_address_line_2' => ['Address line 2', $address['street_additional'] ?? null],
            'invoice_postal_code' => ['Postal code', $address['postal_code'] ?? null],
            'invoice_city' => ['City', $address['city'] ?? null],
            'invoice_country' => ['Country', $country],
        ];

        $filled = [];

        foreach ($values as $field => [$label, $value]) {
            if (blank($value) || filled($get($field))) {
                continue;
            }

            $set($field, $value);
            $filled[] = $label;

            if ($field === 'invoice_country') {
                $set('invoice_language', InvoiceLanguage::suggestedFor($value)->value);
            }
        }

        return $filled;
    }
}
