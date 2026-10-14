<?php

namespace App\Filament\Resources\SlackMembers\Widgets;

use App\Enums\InvoiceLanguage;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\SlackMembers\SlackMemberResource;
use App\Models\Affiliate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * All affiliate Slack channels, with the partner's own Slack contact who is invited to the channel.
 */
class AffiliateSlackChannels extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Affiliate channels')
            ->description('Every affiliate\'s private Slack channel. Add the partner\'s contact person and Slack member ID to invite them to their channel.')
            ->query(fn (): Builder => Affiliate::query()->whereNotNull('slack_channel_id'))
            ->defaultSort('slack_channel_name')
            ->columns([
                TextColumn::make('slack_channel_name')
                    ->label('Channel')
                    ->formatStateUsing(fn (string $state): string => "#{$state}")
                    ->url(fn (Affiliate $record): ?string => $record->slackChannelUrl(), shouldOpenInNewTab: true)
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Affiliate')
                    ->url(fn (Affiliate $record): string => AffiliateResource::getUrl('view', ['record' => $record]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slack_contact_name')
                    ->label('Contact person')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('slack_contact_member_id')
                    ->label('Slack member ID')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('Not added'),
                TextColumn::make('slack_contact_language')
                    ->label('Language')
                    ->state(fn (Affiliate $record): string => $record->slackContactLanguage()->getLabel())
                    ->badge()
                    ->color('gray')
                    ->tooltip(fn (Affiliate $record): ?string => $record->slack_contact_language ? null : 'Same as the invoice language'),
            ])
            ->recordActions([
                Action::make('editContact')
                    ->label(fn (Affiliate $record): string => filled($record->slack_contact_member_id) ? 'Edit contact' : 'Add contact')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->link()
                    ->modalHeading(fn (Affiliate $record): string => "Slack contact for #{$record->slack_channel_name}")
                    ->modalDescription('The contact is invited to this channel in the background when you save a new member ID. Problems show up under System → Integration errors.')
                    ->fillForm(fn (Affiliate $record): array => [
                        'slack_contact_name' => $record->slack_contact_name,
                        'slack_contact_member_id' => $record->slack_contact_member_id,
                        'slack_contact_language' => $record->slack_contact_language,
                    ])
                    ->schema([
                        TextInput::make('slack_contact_name')
                            ->label('Contact person')
                            ->maxLength(255),
                        SlackMemberResource::memberIdField('slack_contact_member_id'),
                        Select::make('slack_contact_language')
                            ->label('Language')
                            ->options(InvoiceLanguage::class)
                            ->placeholder('Same as invoice language')
                            ->helperText('Language of the Slack messages to this contact.'),
                    ])
                    ->action(function (Affiliate $record, array $data): void {
                        $record->update($data);

                        Notification::make()
                            ->success()
                            ->title(filled($record->slack_contact_member_id) && $record->wasChanged('slack_contact_member_id')
                                ? "Contact saved; they are invited to #{$record->slack_channel_name} in the background"
                                : "Contact for #{$record->slack_channel_name} saved")
                            ->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedChatBubbleLeftRight)
            ->emptyStateHeading('No affiliate channels yet')
            ->emptyStateDescription('A private channel is created for every new affiliate.');
    }
}
