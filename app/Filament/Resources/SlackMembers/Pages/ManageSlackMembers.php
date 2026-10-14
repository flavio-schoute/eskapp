<?php

namespace App\Filament\Resources\SlackMembers\Pages;

use App\Filament\Resources\SlackMembers\SlackMemberResource;
use App\Filament\Resources\SlackMembers\Widgets\AffiliateSlackChannels;
use App\Models\SlackMember;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSlackMembers extends ManageRecords
{
    protected static string $resource = SlackMemberResource::class;

    protected static ?string $title = 'Slack settings';

    protected function getHeaderActions(): array
    {
        return [
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
}
