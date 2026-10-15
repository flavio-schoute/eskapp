<?php

namespace App\Filament\Pages;

use App\Enums\DebtorCaseType;
use App\Enums\Integration;
use App\Filament\Concerns\AdminOnly;
use App\Models\DebtorCase;
use App\Services\DebtorSync;
use App\Services\IntegrationErrorLogger;
use App\Services\PlugAndPay;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

/**
 * Debiteurenbeheer: e-Skool's own unpaid and charged back sales invoices from Plug&Pay, separate from the affiliates.
 */
class Debtors extends Page implements HasTable
{
    use AdminOnly;
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Debiteurenbeheer';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Debiteurenbeheer';

    protected static ?string $slug = 'debtors';

    public static function getNavigationBadge(): ?string
    {
        $open = DebtorCase::query()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /**
     * The tab that is open: unpaid or charged back invoices.
     */
    public string $activeTab = 'unpaid';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Debtor type')
                ->livewireProperty('activeTab')
                ->contained(false)
                ->tabs(collect(DebtorCaseType::cases())->mapWithKeys(fn (DebtorCaseType $type): array => [$type->value => Tab::make($type->getLabel())
                    ->icon($type->getIcon())
                    ->badge(fn (): ?int => DebtorCase::query()->open()->where('type', $type)->count() ?: null)
                    ->badgeColor($type->getColor())
                    ->schema([EmbeddedTable::make()])])
                    ->all()),
        ]);
    }

    public function updatedActiveTab(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn (): string => DebtorCaseType::tryFrom($this->activeTab) === DebtorCaseType::ChargedBack ? 'Gestorneerde betalingen' : 'Onbetaalde facturen')
            ->description(fn (): string => $this->summary())
            ->query(fn (): Builder => DebtorCase::query()->where('type', DebtorCaseType::tryFrom($this->activeTab) ?? DebtorCaseType::Unpaid))
            ->defaultSort('invoice_date')
            ->columns([
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->description(fn (DebtorCase $record): ?string => $record->company ?: $record->email)
                    ->searchable(['customer_name', 'company', 'email'])
                    ->sortable(),
                TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->description(fn (DebtorCase $record): ?string => $record->product)
                    ->url(fn (DebtorCase $record): ?string => $record->invoice_url, shouldOpenInNewTab: true)
                    ->searchable(['invoice_number', 'plug_and_pay_order_id', 'product']),
                TextColumn::make('amount')
                    ->label('Amount excl. VAT')
                    ->money('EUR', locale: 'nl')
                    ->weight('medium')
                    ->sortable(),
                TextColumn::make('amount_with_tax')
                    ->label('Incl. VAT')
                    ->money('EUR', locale: 'nl')
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('invoice_date')
                    ->label('Invoice date')
                    ->date('j M Y')
                    ->description(fn (DebtorCase $record): ?string => $record->daysOpen() !== null ? trans_choice('{0} today|{1} 1 day open|[2,*] :count days open', $record->daysOpen()) : null)
                    ->color(fn (DebtorCase $record): ?string => ($record->daysOpen() ?? 0) > 30 ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime('j M Y')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('open')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Open')
                    ->falseLabel('Resolved')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('resolved_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('resolved_at'),
                    ),
            ])
            ->recordUrl(fn (DebtorCase $record): string => $record->plugAndPayUrl(), shouldOpenInNewTab: true)
            ->recordActions([
                Action::make('whatsApp')
                    ->label('WhatsApp')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->color('success')
                    ->url(fn (DebtorCase $record): ?string => $record->whatsAppUrl(static::whatsAppMessage($record)), shouldOpenInNewTab: true)
                    ->visible(fn (DebtorCase $record): bool => $record->whatsAppNumber() !== null && $record->resolved_at === null),
                ActionGroup::make([
                    Action::make('copyPaymentLink')
                        ->label('Copy payment link')
                        ->icon(Heroicon::OutlinedLink)
                        ->visible(fn (DebtorCase $record): bool => filled($record->payment_url))
                        ->alpineClickHandler(fn (DebtorCase $record): string => 'window.navigator.clipboard.writeText('.json_encode($record->payment_url).'); $tooltip(\'Copied\', { timeout: 1500 })'),
                    Action::make('email')
                        ->label('Email customer')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->url(fn (DebtorCase $record): ?string => $record->email ? 'mailto:'.$record->email.'?subject='.rawurlencode('Factuur '.$record->invoice_number).'&body='.rawurlencode(static::whatsAppMessage($record)) : null)
                        ->visible(fn (DebtorCase $record): bool => filled($record->email)),
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
                ]),
            ])
            ->headerActions([
                Action::make('sync')
                    ->label('Sync with Plug&Pay')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->disabled(fn (): bool => ! app(PlugAndPay::class)->isConfigured())
                    ->action(function (DebtorSync $sync, IntegrationErrorLogger $errorLogger): void {
                        try {
                            $result = $sync->sync();
                        } catch (Throwable $exception) {
                            $errorLogger->record(Integration::PlugAndPay, 'Sync debtors', $exception);

                            Notification::make()->danger()->title('Could not sync with Plug&Pay')->body(IntegrationErrorLogger::readableMessage($exception))->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title("{$result['open']} open debtor cases")
                            ->body($result['resolved'] > 0 ? trans_choice('{1} 1 case was paid or resolved.|[2,*] :count cases were paid or resolved.', $result['resolved']) : null)
                            ->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('No open debtors')
            ->emptyStateDescription('Unpaid and charged back invoices from Plug&Pay show up here. They are synced every 30 minutes.');
    }

    /**
     * The WhatsApp or email text for a case, with the payment link.
     */
    public static function whatsAppMessage(DebtorCase $case): string
    {
        $firstName = str($case->customer_name)->before(' ')->toString() ?: 'daar';
        $amount = '€ '.number_format((float) $case->amount_with_tax, 2, ',', '.');

        $reason = $case->type === DebtorCaseType::ChargedBack
            ? "de incasso van factuur {$case->invoice_number} ({$amount}) is helaas gestorneerd"
            : "we hebben de betaling van factuur {$case->invoice_number} ({$amount}) nog niet ontvangen";

        return "Hoi {$firstName}, {$reason}. Je kunt hier eenvoudig betalen: {$case->payment_url}\n\nLaat het gerust weten als er iets is!\n\nGroet, e-Skool";
    }

    protected function summary(): string
    {
        $open = DebtorCase::query()->open();
        $total = '€ '.number_format((float) $open->clone()->sum('amount'), 2, ',', '.');
        $totalWithTax = '€ '.number_format((float) $open->clone()->sum('amount_with_tax'), 2, ',', '.');
        $unpaid = $open->clone()->where('type', DebtorCaseType::Unpaid)->count();
        $chargedBack = $open->clone()->where('type', DebtorCaseType::ChargedBack)->count();
        $lastSync = DebtorCase::query()->max('synced_at');

        return "{$unpaid} onbetaald · {$chargedBack} gestorneerd · {$total} open excl. VAT ({$totalWithTax} incl. VAT)"
            .($lastSync ? ' · synced with Plug&Pay '.Carbon::parse($lastSync)->diffForHumans() : '');
    }
}
