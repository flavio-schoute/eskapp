<?php

namespace App\Filament\Resources\DebtorCases;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Pages\Debtors;
use App\Filament\Resources\DebtorCases\Pages\ViewDebtorCase;
use App\Models\DebtorCase;
use App\Models\DebtorCaseEvent;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\RichEditor;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * A debtor case ("dossier"): the customer, the invoice and the timeline from opened to resolved, with notes.
 */
class DebtorCaseResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = DebtorCase::class;

    protected static ?string $slug = 'debtors/cases';

    protected static ?string $modelLabel = 'dossier';

    protected static bool $shouldRegisterNavigation = false;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Dossier')
                            ->columnSpan(2)
                            ->columns(3)
                            ->schema([
                                TextEntry::make('type')
                                    ->badge(),
                                TextEntry::make('resolved_at')
                                    ->label('Status')
                                    ->state(fn (DebtorCase $record): string => $record->resolved_at ? 'Resolved' : 'Open')
                                    ->badge()
                                    ->color(fn (DebtorCase $record): string => $record->resolved_at ? 'success' : 'danger')
                                    ->helperText(fn (DebtorCase $record): ?string => $record->resolved_at?->translatedFormat('j M Y, H:i')),
                                TextEntry::make('amount')
                                    ->label('Amount excl. VAT')
                                    ->money('EUR', locale: 'nl')
                                    ->weight('medium')
                                    ->helperText(fn (DebtorCase $record): string => '€ '.number_format((float) $record->amount_with_tax, 2, ',', '.').' incl. VAT'),
                                TextEntry::make('invoice_number')
                                    ->label('Invoice')
                                    ->url(fn (DebtorCase $record): ?string => $record->invoice_url, shouldOpenInNewTab: true)
                                    ->color('primary')
                                    ->placeholder('-'),
                                TextEntry::make('invoice_date')
                                    ->label('Invoice date')
                                    ->date('j M Y')
                                    ->helperText(fn (DebtorCase $record): ?string => $record->daysOpen() !== null && ! $record->resolved_at ? trans_choice('{0} today|{1} 1 day open|[2,*] :count days open', $record->daysOpen()) : null)
                                    ->placeholder('-'),
                                TextEntry::make('plug_and_pay_order_id')
                                    ->label('Order')
                                    ->formatStateUsing(fn (int $state): string => "#{$state}")
                                    ->url(fn (DebtorCase $record): string => $record->plugAndPayUrl(), shouldOpenInNewTab: true)
                                    ->color('primary'),
                                TextEntry::make('product')
                                    ->columnSpanFull()
                                    ->placeholder('-'),
                            ]),
                        Section::make('Customer')
                            ->columnSpan(1)
                            ->schema([
                                TextEntry::make('customer_name')
                                    ->label('Name')
                                    ->helperText(fn (DebtorCase $record): ?string => $record->company)
                                    ->placeholder('-'),
                                TextEntry::make('email')
                                    ->url(fn (?string $state): ?string => $state ? "mailto:{$state}" : null)
                                    ->copyable()
                                    ->placeholder('-'),
                                TextEntry::make('phone')
                                    ->url(fn (?string $state): ?string => $state ? 'tel:'.preg_replace('/[^\d+]/', '', $state) : null)
                                    ->copyable()
                                    ->placeholder('-'),
                            ]),
                    ]),
                Section::make('Timeline')
                    ->key('timeline_section')
                    ->description('Newest first: what happened with this case, and notes from the team.')
                    ->columnSpanFull()
                    ->headerActions([
                        static::addNoteAction(),
                    ])
                    ->schema([
                        ViewEntry::make('timeline')
                            ->hiddenLabel()
                            ->view('filament.debtor-case-timeline'),
                    ]),
            ]);
    }

    public static function addNoteAction(): Action
    {
        return Action::make('addNote')
            ->label('Add note')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Add a note')
            ->modalSubmitActionLabel('Save note')
            ->schema([
                RichEditor::make('body')
                    ->hiddenLabel()
                    ->placeholder('E.g. called the customer, they will pay on Friday.')
                    ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'link'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']])
                    ->required(),
            ])
            ->action(function (DebtorCase $record, array $data): void {
                $record->recordEvent(DebtorCaseEvent::Note, 'Note', $data['body']);

                Notification::make()->success()->title('Note saved')->send();
            });
    }

    /**
     * Contacting the customer and the links to Plug&Pay, Mollie and the invoice.
     *
     * @return array<Action|ActionGroup>
     */
    public static function caseActions(): array
    {
        return [
            Action::make('whatsApp')
                ->label('WhatsApp')
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                ->url(fn (DebtorCase $record): ?string => $record->whatsAppUrl(Debtors::whatsAppMessage($record)), shouldOpenInNewTab: true)
                ->visible(fn (DebtorCase $record): bool => $record->whatsAppNumber() !== null && $record->resolved_at === null),
            Action::make('email')
                ->label('Email customer')
                ->icon(Heroicon::OutlinedEnvelope)
                ->url(fn (DebtorCase $record): ?string => $record->email ? 'mailto:'.$record->email.'?subject='.rawurlencode('Factuur '.$record->invoice_number).'&body='.rawurlencode(Debtors::whatsAppMessage($record)) : null)
                ->visible(fn (DebtorCase $record): bool => filled($record->email)),
            Action::make('copyPaymentLink')
                ->label('Copy payment link')
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn (DebtorCase $record): bool => filled($record->payment_url))
                ->alpineClickHandler(fn (DebtorCase $record): string => 'window.navigator.clipboard.writeText('.json_encode($record->payment_url).'); $tooltip(\'Copied\', { timeout: 1500 })'),
            Action::make('invoice')
                ->label('Invoice PDF')
                ->icon(Heroicon::OutlinedDocumentText)
                ->url(fn (DebtorCase $record): ?string => $record->invoice_url, shouldOpenInNewTab: true)
                ->visible(fn (DebtorCase $record): bool => filled($record->invoice_url)),
            Action::make('plugAndPay')
                ->label('Open in Plug&Pay')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (DebtorCase $record): string => $record->plugAndPayUrl(), shouldOpenInNewTab: true),
            Action::make('mollie')
                ->label('Open in Mollie')
                ->icon(Heroicon::OutlinedCreditCard)
                ->url(fn (DebtorCase $record): ?string => $record->mollie_transaction_id ? 'https://my.mollie.com/dashboard/'.config('services.mollie.dashboard_organization')."/payments/{$record->mollie_transaction_id}" : null, shouldOpenInNewTab: true)
                ->visible(fn (DebtorCase $record): bool => filled($record->mollie_transaction_id)),
        ];
    }

    public static function getRecordTitle(?Model $record): string
    {
        /** @var DebtorCase|null $record */
        return $record ? trim(($record->customer_name ?? 'Unknown customer').' · '.($record->invoice_number ?? "#{$record->plug_and_pay_order_id}")) : 'Dossier';
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewDebtorCase::route('/{record}'),
        ];
    }
}
