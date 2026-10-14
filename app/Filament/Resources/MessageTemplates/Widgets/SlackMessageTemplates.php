<?php

namespace App\Filament\Resources\MessageTemplates\Widgets;

use App\Enums\MessageTemplateType;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\MessageTemplate;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Slack message templates, as their own table below the email templates.
 */
class SlackMessageTemplates extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Slack templates')
            ->description('The messages posted in an affiliate\'s Slack channel after an invoice was sent, per language. Use *text* for bold.')
            ->query(fn (): Builder => MessageTemplate::query()->whereIn('type', MessageTemplateType::forChannel('Slack')))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('language')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('body')
                    ->label('Message')
                    ->formatStateUsing(fn (string $state): string => preg_replace('/\s+/', ' ', $state))
                    ->limit(80)
                    ->searchable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('New Slack template')
                    ->modalHeading('New Slack template')
                    ->model(MessageTemplate::class)
                    ->schema(MessageTemplateResource::formComponents('Slack'))
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->successNotificationTitle(fn (MessageTemplate $record): string => "Template {$record->name} added"),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(MessageTemplateResource::formComponents('Slack'))
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->successNotificationTitle(fn (MessageTemplate $record): string => "Template {$record->name} saved"),
                DeleteAction::make()
                    ->successNotificationTitle(fn (MessageTemplate $record): string => "Template {$record->name} deleted"),
            ]);
    }
}
