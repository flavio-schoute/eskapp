<?php

namespace App\Filament\Resources\Affiliates\Schemas;

use App\Models\Affiliate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AffiliateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Basics')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->label('Partner name'),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('slack_channel_name')
                            ->label('Slack channel')
                            ->formatStateUsing(fn (string $state): string => "#{$state}")
                            ->url(fn (Affiliate $record): ?string => $record->slackChannelUrl(), shouldOpenInNewTab: true)
                            ->color('primary')
                            ->placeholder('No channel yet'),
                    ]),
                Section::make('Access')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('login_url')
                            ->label('Login URL')
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->placeholder('-'),
                        TextEntry::make('username')
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('password')
                            ->formatStateUsing(fn (): string => '••••••••')
                            ->copyable()
                            ->copyableState(fn (?string $state): ?string => $state)
                            ->copyMessage('Password copied')
                            ->placeholder('-'),
                    ]),
                Section::make('Agreement')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('agreement')
                            ->placeholder('-')
                            ->prose(),
                        TextEntry::make('payment_method')
                            ->label('Payment')
                            ->placeholder('-'),
                        TextEntry::make('agreement_drive_url')
                            ->label('Agreement document')
                            ->formatStateUsing(fn (Affiliate $record): ?string => $record->agreement_file_name)
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->color('primary')
                            ->placeholder('No agreement uploaded yet'),
                        TextEntry::make('google_drive_folder_id')
                            ->label('Google Drive folder')
                            ->formatStateUsing(fn (): string => 'Open folder')
                            ->url(fn (Affiliate $record): ?string => $record->googleDriveFolderUrl(), shouldOpenInNewTab: true)
                            ->color('primary')
                            ->placeholder('No folder yet'),
                    ]),
                Section::make('Invoice details')
                    ->visible(fn (Affiliate $record): bool => (bool) $record->payment_method?->requiresInvoiceDetails())
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('invoice_address')
                            ->label('Invoice address')
                            ->state(fn (Affiliate $record): array => $record->invoiceAddressLines())
                            ->listWithLineBreaks()
                            ->copyable()
                            ->copyableState(fn (Affiliate $record): string => implode("\n", $record->invoiceAddressLines()))
                            ->placeholder('-'),
                        TextEntry::make('invoice_contact_person')
                            ->label('Contact person')
                            ->placeholder('-'),
                        TextEntry::make('invoice_kvk_number')
                            ->label(fn (Affiliate $record): string => $record->invoice_country === 'NL' ? 'KvK number' : 'Company registration number')
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('invoice_vat_number')
                            ->label('VAT number')
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('invoice_language')
                            ->label('Preferred language')
                            ->placeholder('-'),
                        TextEntry::make('mollie_customer_id')
                            ->label('Mollie customer')
                            ->fontFamily('mono')
                            ->copyable()
                            ->placeholder('Not created yet'),
                        TextEntry::make('invoice_phone')
                            ->label('Phone number')
                            ->url(fn (?string $state): ?string => $state ? 'tel:'.preg_replace('/[^\d+]/', '', $state) : null)
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('invoice_email')
                            ->label('Email address')
                            ->url(fn (?string $state): ?string => $state ? "mailto:{$state}" : null)
                            ->copyable()
                            ->placeholder('-'),
                    ]),
                Section::make('Links')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('affiliate_link')
                            ->label('Affiliate / tracking link')
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->copyable()
                            ->placeholder('-'),
                        TextEntry::make('notes')
                            ->label('Extra notes')
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
