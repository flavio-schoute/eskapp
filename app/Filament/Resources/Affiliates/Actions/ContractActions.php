<?php

namespace App\Filament\Resources\Affiliates\Actions;

use App\Enums\ContractStatus;
use App\Enums\Integration;
use App\Models\Affiliate;
use App\Services\AffiliateContracts;
use App\Services\ESignatures;
use App\Services\IntegrationErrorLogger;
use App\Support\ContractTerms;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ContractActions
{
    /**
     * Fill in the affiliate contract and send it for signing with eSignatures. Without a record (a header action),
     * the affiliate is chosen in the form first.
     */
    public static function generate(): Action
    {
        return Action::make('generateContract')
            ->label(fn (?Affiliate $record): string => $record?->latestContract ? 'Send new contract' : 'Generate contract')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color(fn (?Affiliate $record): string => $record ? 'gray' : 'primary')
            ->disabled(fn (): bool => ! app(ESignatures::class)->isConfigured())
            ->tooltip(fn (): ?string => app(ESignatures::class)->isConfigured() ? null : 'eSignatures is not configured yet')
            ->modalHeading(fn (?Affiliate $record): string => $record ? "Contract for {$record->name}" : 'Generate contract')
            ->modalDescription(fn (?Affiliate $record): string => $record?->latestContract?->status->isOpen()
                ? 'A contract is already waiting for a signature. This sends a new one; the old one stays open in eSignatures.'
                : 'The Samenwerkingsovereenkomst is filled in with these details and emailed to the partner, who adds their name, function and signature.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Send contract')
            ->fillForm(fn (?Affiliate $record): array => $record ? ContractTerms::defaultsFor($record) : [
                'start_date' => now()->toDateString(),
                'notice_period' => '1 week',
                'other_agreements' => 'Geen.',
                'compensation_basis' => ContractTerms::PercentageOfRevenue,
                'compensation' => ContractTerms::compensationText(ContractTerms::PercentageOfRevenue, null),
            ])
            ->schema(fn (?Affiliate $record): array => static::contractFormComponents(withAffiliateSelect: $record === null))
            ->action(function (?Affiliate $record, array $data, Action $action): void {
                $affiliate = $record ?? Affiliate::findOrFail($data['affiliate_id']);

                try {
                    $contract = app(AffiliateContracts::class)->send($affiliate, $data['signer_name'], $data['signer_email'], $data, auth()->user());
                } catch (Throwable $exception) {
                    app(IntegrationErrorLogger::class)->record(Integration::ESignatures, 'Send contract', $exception, $affiliate);

                    Notification::make()
                        ->danger()
                        ->title('The contract could not be sent')
                        ->body(IntegrationErrorLogger::readableMessage($exception))
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title("Contract sent to {$contract->signer_email}")
                    ->body($contract->is_test ? 'Sent in test mode: free and marked as a demo in eSignatures.' : null)
                    ->send();
            });
    }

    /**
     * @return array<int, Component>
     */
    public static function contractFormComponents(bool $withAffiliateSelect = false): array
    {
        $fillCompensation = fn (Get $get, Set $set) => $get('compensation_basis') === ContractTerms::CustomCompensation
            ? null
            : $set('compensation', ContractTerms::compensationText($get('compensation_basis'), $get('compensation_amount')));

        return [
            Select::make('affiliate_id')
                ->label('Affiliate')
                ->options(fn (): array => Affiliate::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $affiliate = Affiliate::find($state);

                    foreach ($affiliate ? ContractTerms::defaultsFor($affiliate) : [] as $field => $value) {
                        $set($field, $value);
                    }
                })
                ->visible($withAffiliateSelect),
            Section::make('Partner')
                ->description('Ondergetekenden, punt 2.')
                ->columns(2)
                ->schema([
                    TextInput::make('partner_name')
                        ->label('Name affiliate partner')
                        ->required()
                        ->live(onBlur: true)
                        ->maxLength(255),
                    Select::make('legal_form')
                        ->label('Legal form')
                        ->options(ContractTerms::legalForms())
                        ->placeholder('None / not applicable')
                        ->live(),
                    TextInput::make('registration_number')
                        ->label('KvK number')
                        ->helperText('Leave empty when not applicable.')
                        ->live(onBlur: true)
                        ->maxLength(50),
                    TextInput::make('city')
                        ->label('Established in (city)')
                        ->required()
                        ->live(onBlur: true)
                        ->maxLength(255),
                    TextEntry::make('partner_preview')
                        ->label('In the contract')
                        ->state(fn (Get $get): string => '2. '.collect([
                            $get('partner_name') ?: '[NAAM]',
                            ContractTerms::legalFormAndRegistration($get('legal_form'), $get('registration_number')) ?: null,
                            'gevestigd te '.($get('city') ?: '[PLAATS]'),
                        ])->filter()->implode(', ').', hierna te noemen "Partner";')
                        ->columnSpanFull(),
                ]),
            Section::make('Compensation')
                ->description('Artikel 4.1. Pick a basis to fill in the text, then adjust it if needed.')
                ->columns(2)
                ->schema([
                    Select::make('compensation_basis')
                        ->label('Basis')
                        ->options(ContractTerms::compensationOptions())
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated($fillCompensation),
                    TextInput::make('compensation_amount')
                        ->label(fn (Get $get): string => $get('compensation_basis') === ContractTerms::PercentageOfRevenue ? 'Percentage' : 'Amount')
                        ->prefix(fn (Get $get): ?string => $get('compensation_basis') === ContractTerms::PercentageOfRevenue ? null : '€')
                        ->suffix(fn (Get $get): ?string => $get('compensation_basis') === ContractTerms::PercentageOfRevenue ? '%' : null)
                        ->numeric()
                        ->minValue(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated($fillCompensation)
                        ->visible(fn (Get $get): bool => $get('compensation_basis') !== ContractTerms::CustomCompensation),
                    Textarea::make('compensation')
                        ->label('Text of artikel 4.1')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('other_agreements')
                        ->label('Other agreements (artikel 4.2)')
                        ->placeholder('Geen.')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
            Section::make('Duration')
                ->description('Artikel 8. The contract is for an indefinite period.')
                ->columns(2)
                ->schema([
                    DatePicker::make('start_date')
                        ->label('Start date')
                        ->native(false)
                        ->displayFormat('j F Y')
                        ->required(),
                    TextInput::make('notice_period')
                        ->label('Notice period')
                        ->datalist(ContractTerms::noticePeriodOptions())
                        ->required()
                        ->maxLength(50),
                ]),
            Section::make('Signer at the partner')
                ->description('Receives the contract by email and fills in their name, function and signature.')
                ->columns(2)
                ->schema([
                    TextInput::make('signer_name')
                        ->label('Name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('signer_email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->maxLength(255),
                ]),
        ];
    }

    /**
     * Ask eSignatures whether the latest contract was signed in the meantime.
     */
    public static function refreshStatus(): Action
    {
        return Action::make('refreshContractStatus')
            ->label('Check signature')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Affiliate $record): bool => (bool) $record->latestContract?->status->isOpen() && app(ESignatures::class)->isConfigured())
            ->action(function (Affiliate $record): void {
                try {
                    $contract = app(AffiliateContracts::class)->refreshStatus($record->latestContract);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->danger()
                        ->title('The contract status could not be checked')
                        ->body(IntegrationErrorLogger::readableMessage($exception))
                        ->send();

                    return;
                }

                $record->unsetRelation('latestContract');

                Notification::make()
                    ->{$contract->status === ContractStatus::Signed ? 'success' : 'info'}()
                    ->title($contract->status === ContractStatus::Signed
                        ? "{$record->name} has signed the contract"
                        : "{$record->name} has not signed yet")
                    ->body($contract->status === ContractStatus::Signed ? 'The signed PDF is stored in the Google Drive folder in the background.' : null)
                    ->send();
            });
    }
}
