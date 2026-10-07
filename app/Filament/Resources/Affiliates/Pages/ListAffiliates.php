<?php

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\Affiliates\Widgets\AffiliateStatusOverview;
use App\Filament\Resources\Affiliates\Widgets\PipelineAffiliates;
use App\Filament\Resources\SlackMembers\SlackMemberResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;

class ListAffiliates extends ListRecords
{
    protected static string $resource = AffiliateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manageSlackMembers')
                ->label('Manage Slack members')
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('gray')
                ->url(SlackMemberResource::getUrl()),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AffiliateStatusOverview::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            PipelineAffiliates::class,
        ];
    }

    #[On('affiliate-status-updated')]
    public function refreshTable(): void {}
}
