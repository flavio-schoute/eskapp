<?php

namespace App\Filament\Resources\SlackMembers\Widgets;

use App\Enums\Integration;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\SlackMembers\SlackMemberResource;
use App\Models\Affiliate;
use App\Models\IntegrationError;
use Filament\Actions\Action;
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
            ])
            ->recordActions([
                Action::make('editContact')
                    ->label(fn (Affiliate $record): string => filled($record->slack_contact_member_id) ? 'Edit contact' : 'Add contact')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->link()
                    ->modalHeading(fn (Affiliate $record): string => "Slack contact for #{$record->slack_channel_name}")
                    ->modalDescription('The contact is invited to this channel when you save a new member ID.')
                    ->fillForm(fn (Affiliate $record): array => [
                        'slack_contact_name' => $record->slack_contact_name,
                        'slack_contact_member_id' => $record->slack_contact_member_id,
                    ])
                    ->schema([
                        TextInput::make('slack_contact_name')
                            ->label('Contact person')
                            ->maxLength(255),
                        SlackMemberResource::memberIdField('slack_contact_member_id'),
                    ])
                    ->action(function (Affiliate $record, array $data): void {
                        $lastErrorId = IntegrationError::max('id') ?? 0;

                        $record->update($data);

                        $inviteFailed = IntegrationError::query()
                            ->where('id', '>', $lastErrorId)
                            ->where('affiliate_id', $record->getKey())
                            ->where('integration', Integration::Slack)
                            ->where('action', 'Invite contact')
                            ->first();

                        if ($inviteFailed) {
                            Notification::make()
                                ->warning()
                                ->title("Saved, but the contact could not be invited to #{$record->slack_channel_name}")
                                ->body($inviteFailed->summary().' The error is logged under System → Integration errors.')
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(filled($record->slack_contact_member_id) && $record->wasChanged('slack_contact_member_id')
                                ? "Contact saved and invited to #{$record->slack_channel_name}"
                                : "Contact for #{$record->slack_channel_name} saved")
                            ->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedChatBubbleLeftRight)
            ->emptyStateHeading('No affiliate channels yet')
            ->emptyStateDescription('A private channel is created for every new affiliate.');
    }
}
