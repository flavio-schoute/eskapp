<?php

namespace App\Filament\Resources\SlackMembers\Pages;

use App\Enums\Integration;
use App\Filament\Pages\AffiliateInvoices;
use App\Filament\Pages\Contracts;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\SlackMembers\SlackMemberResource;
use App\Filament\Resources\SlackMembers\Widgets\AffiliateSlackChannels;
use App\Models\SlackMember;
use App\Services\IntegrationErrorLogger;
use App\Services\Slack;
use App\Support\ContractReminder;
use App\Support\InvoiceReminder;
use App\Support\SlackMessagePreview;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

class ManageSlackMembers extends ManageRecords
{
    protected static string $resource = SlackMemberResource::class;

    protected static ?string $title = 'Slack settings';

    protected function getHeaderActions(): array
    {
        return [
            $this->invoiceReminderAction(),
            $this->contractReminderAction(),
            CreateAction::make()
                ->successNotificationTitle(fn (SlackMember $record): string => "Slack member {$record->name} added"),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            AffiliateSlackChannels::class,
        ];
    }

    /**
     * The monthly Slack reminder to send the affiliate invoices: channel, day, time and message.
     */
    protected function invoiceReminderAction(): Action
    {
        return Action::make('invoiceReminder')
            ->label('Invoice reminder')
            ->icon(Heroicon::OutlinedBellAlert)
            ->color('gray')
            ->modalHeading('Monthly invoice reminder')
            ->modalDescription('Posts a message in Slack every month as a reminder to send the affiliate invoices under Affiliates → Invoices → Monthly invoices.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => [...InvoiceReminder::settings(), 'send_test' => false])
            ->schema([
                Toggle::make('enabled')
                    ->label('Send the reminder every month')
                    ->live(),
                static::channelField(),
                Grid::make(2)->schema([
                    Select::make('day')
                        ->label('Day of the month')
                        ->options(collect(range(1, 28))->mapWithKeys(fn (int $day): array => [$day => "{$day}e"])->all())
                        ->selectablePlaceholder(false)
                        ->required(),
                    TimePicker::make('time')
                        ->label('Time (Amsterdam)')
                        ->seconds(false)
                        ->required(),
                ]),
                Textarea::make('message')
                    ->label('Message')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->live(debounce: 400)
                    ->helperText(MessageTemplateResource::placeholderList(InvoiceReminder::Placeholders, 'Use *text* for bold, like in Slack.')),
                TextEntry::make('preview')
                    ->label('Preview')
                    ->state(fn (Get $get): Htmlable => SlackMessagePreview::html(InvoiceReminder::render((string) $get('message'), now()->subMonthNoOverflow(), InvoiceReminder::affiliateCount(), AffiliateInvoices::getUrl()))),
                Toggle::make('send_test')
                    ->label('Send a test message to the channel after saving'),
            ])
            ->action(function (array $data, Slack $slack, IntegrationErrorLogger $errorLogger): void {
                InvoiceReminder::save($data);

                Notification::make()
                    ->success()
                    ->title('Invoice reminder saved')
                    ->body($data['enabled']
                        ? "Posted on the {$data['day']}e of every month from ".substr($data['time'], 0, 5).'.'
                        : 'The reminder is turned off.')
                    ->send();

                if (! ($data['send_test'] ?? false)) {
                    return;
                }

                try {
                    $slack->postMessage($data['channel'], InvoiceReminder::render($data['message'], now()->subMonthNoOverflow(), InvoiceReminder::affiliateCount(), AffiliateInvoices::getUrl()));

                    Notification::make()->success()->title('Test message posted in Slack')->send();
                } catch (Throwable $exception) {
                    $errorLogger->record(Integration::Slack, 'Post invoice reminder', $exception);

                    Notification::make()
                        ->danger()
                        ->title('The test message could not be posted')
                        ->body(IntegrationErrorLogger::readableMessage($exception).' Is the bot invited to the channel?')
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * The reminder for contracts that are still not signed after a few days.
     */
    protected function contractReminderAction(): Action
    {
        return Action::make('contractReminder')
            ->label('Contract reminder')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->modalHeading('Contract reminder')
            ->modalDescription('When a partner has not signed their contract after a number of days, eSignatures emails them again and a message is posted in the admin channel. Each contract is reminded once, during office hours.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => ContractReminder::settings())
            ->schema([
                Toggle::make('enabled')
                    ->label('Send contract reminders')
                    ->live(),
                Grid::make(2)->schema([
                    TextInput::make('days')
                        ->label('Remind after')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(60)
                        ->suffix('days')
                        ->required(),
                    Toggle::make('remind_partner')
                        ->label('eSignatures emails the partner again')
                        ->inline(false),
                ]),
                static::channelField()
                    ->label('Admin Slack channel ID'),
                Textarea::make('message')
                    ->label('Message')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->live(debounce: 400)
                    ->helperText(MessageTemplateResource::placeholderList(ContractReminder::Placeholders, 'Use *text* for bold, like in Slack.')),
                TextEntry::make('preview')
                    ->label('Preview')
                    ->state(fn (Get $get): Htmlable => SlackMessagePreview::html(strtr((string) $get('message'), [
                        '{partner}' => 'Acme Partners B.V.',
                        '{signer}' => 'Jan Jansen',
                        '{email}' => 'jan@acme.nl',
                        '{days}' => (string) ($get('days') ?: 5),
                        '{link}' => Contracts::getUrl(),
                    ]))),
            ])
            ->action(function (array $data): void {
                ContractReminder::save($data);

                Notification::make()
                    ->success()
                    ->title('Contract reminder saved')
                    ->body($data['enabled'] ? "Contracts that are not signed after {$data['days']} days get a reminder." : 'Contract reminders are turned off.')
                    ->send();
            });
    }

    protected static function channelField(): TextInput
    {
        return TextInput::make('channel')
            ->label('Slack channel ID')
            ->placeholder('C0123456789')
            ->helperText('In Slack: open the channel → channel name → About, the ID is at the bottom. Invite the bot to the channel with /invite.')
            ->regex('/^[CG][A-Z0-9]{6,}$/')
            ->validationMessages(['regex' => 'A channel ID starts with C (or G for older private channels), e.g. C0123456789.'])
            ->required(fn (Get $get): bool => (bool) $get('enabled'));
    }
}
