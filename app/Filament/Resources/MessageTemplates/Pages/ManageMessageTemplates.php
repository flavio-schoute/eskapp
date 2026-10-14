<?php

namespace App\Filament\Resources\MessageTemplates\Pages;

use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\MessageTemplates\Widgets\SlackMessageTemplates;
use App\Models\MessageTemplate;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageMessageTemplates extends ManageRecords
{
    protected static string $resource = MessageTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New email template')
                ->modalHeading('New email template')
                ->modalWidth(Width::ThreeExtraLarge)
                ->successNotificationTitle(fn (MessageTemplate $record): string => "Template {$record->name} added"),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            SlackMessageTemplates::class,
        ];
    }
}
