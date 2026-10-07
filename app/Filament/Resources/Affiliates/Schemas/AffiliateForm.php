<?php

namespace App\Filament\Resources\Affiliates\Schemas;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Models\Affiliate;
use App\Support\InvoiceAddressFormat;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

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
                            ->required(),
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
                    ->description('Optional. Who we invoice or who invoices us. The address labels follow the chosen country.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('invoice_company_name')
                            ->label('Company name')
                            ->helperText('Full legal name, including the company type (e.g. B.V. or Co., Ltd).')
                            ->maxLength(255)
                            ->live(debounce: 400),
                        Select::make('invoice_country')
                            ->label('Country')
                            ->options(InvoiceAddressFormat::countryOptions())
                            ->searchable()
                            ->live(),
                        TextInput::make('invoice_address_line_1')
                            ->label('Address')
                            ->placeholder(fn (Get $get): string => InvoiceAddressFormat::addressPlaceholder($get('invoice_country')))
                            ->maxLength(255)
                            ->live(debounce: 400)
                            ->columnSpanFull(),
                        TextInput::make('invoice_address_line_2')
                            ->label('Address line 2')
                            ->placeholder('Building, floor, suite (optional)')
                            ->maxLength(255)
                            ->live(debounce: 400)
                            ->columnSpanFull(),
                        Grid::make(3)
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('invoice_postal_code')
                                    ->label(fn (Get $get): string => InvoiceAddressFormat::postalCodeLabel($get('invoice_country')))
                                    ->placeholder(fn (Get $get): ?string => InvoiceAddressFormat::postalCodePlaceholder($get('invoice_country')))
                                    ->maxLength(20)
                                    ->live(debounce: 400),
                                TextInput::make('invoice_city')
                                    ->label('City')
                                    ->maxLength(255)
                                    ->live(debounce: 400),
                                TextInput::make('invoice_region')
                                    ->label(fn (Get $get): string => InvoiceAddressFormat::regionLabel($get('invoice_country')))
                                    ->maxLength(255)
                                    ->live(debounce: 400),
                            ]),
                        TextInput::make('invoice_contact_person')
                            ->label('Contact person')
                            ->maxLength(255),
                        TextInput::make('invoice_phone')
                            ->label('Phone number')
                            ->tel()
                            ->placeholder('+31 6 12345678')
                            ->maxLength(50),
                        TextInput::make('invoice_email')
                            ->label('Email address')
                            ->email()
                            ->maxLength(255),
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
}
