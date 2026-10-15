<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Enums\InvoiceLanguage;
use App\Enums\MessageTemplateType;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\MessageTemplates\Pages\ManageMessageTemplates;
use App\Filament\Resources\MessageTemplates\Widgets\SlackMessageTemplates;
use App\Models\MessageTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class MessageTemplateResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = MessageTemplate::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?string $modelLabel = 'template';

    protected static ?string $slug = 'templates';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components(static::formComponents('Email'));
    }

    /**
     * The template form for one channel ("Email" or "Slack"): only that channel's types can be picked.
     *
     * @return array<int, mixed>
     */
    public static function formComponents(string $channel): array
    {
        $types = MessageTemplateType::forChannel($channel);

        return [
            Select::make('type')
                ->options(collect($types)->mapWithKeys(fn (MessageTemplateType $type): array => [$type->value => $type->getLabel()])->all())
                ->default($types[0]->value)
                ->selectablePlaceholder(false)
                ->required()
                ->live(),
            Select::make('language')
                ->options(InvoiceLanguage::class)
                ->default(InvoiceLanguage::Dutch)
                ->selectablePlaceholder(false)
                ->required(),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Toggle::make('is_default')
                ->label('Default template for this language')
                ->helperText('Selected automatically for partners with this language.')
                ->inline(false),
            TextInput::make('subject')
                ->required()
                ->visible(fn (Get $get): bool => (bool) static::typeFrom($get('type'))?->hasSubject())
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('body')
                ->label(fn (Get $get): string => static::typeFrom($get('type'))?->hasSubject() ? 'Body' : 'Message')
                ->required()
                ->rows(10)
                ->maxLength(5000)
                ->helperText(fn (Get $get): Htmlable => static::placeholderHelp($get('type')))
                ->columnSpanFull(),
        ];
    }

    /**
     * The placeholders that can be used in the chosen template type, as a list of chips you can click to copy.
     */
    public static function placeholderHelp(MessageTemplateType|string|null $type): Htmlable
    {
        return static::placeholderList(static::typeFrom($type)?->placeholders() ?? []);
    }

    /**
     * A list of placeholders with what they are replaced with; click one to copy it.
     *
     * @param  array<string, string>  $placeholders
     */
    public static function placeholderList(array $placeholders, ?string $footnote = null): Htmlable
    {
        $rows = collect($placeholders)
            ->map(fn (string $description, string $placeholder): string => '<div style="display: flex; align-items: baseline; gap: 0.75rem; padding: 0.25rem 0;">'
                .'<button type="button" title="Click to copy" x-data="{ copied: false }"'
                .' x-on:click="window.navigator.clipboard.writeText('.e(json_encode($placeholder)).'); copied = true; setTimeout(() => copied = false, 1500)"'
                .' style="flex-shrink: 0; min-width: 9.5rem; text-align: left; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem;'
                .' padding: 0.125rem 0.5rem; border: 0; border-radius: 0.375rem; background: rgb(127 127 127 / 0.12); color: inherit; box-shadow: inset 0 0 0 1px rgb(127 127 127 / 0.25); cursor: pointer;">'
                .'<span x-show="! copied">'.e($placeholder).'</span><span x-show="copied" x-cloak>Copied ✓</span>'
                .'</button>'
                .'<span>'.e($description).'</span>'
                .'</div>')
            ->implode('');

        return new HtmlString(
            '<div style="margin-top: 0.25rem;">'
                .'<div style="font-weight: 600; margin-bottom: 0.25rem;">Placeholders <span style="font-weight: 400; opacity: 0.75;">· click one to copy it</span></div>'
                .$rows
                .($footnote ? '<div style="margin-top: 0.375rem; opacity: 0.75;">'.e($footnote).'</div>' : '')
            .'</div>'
        );
    }

    public static function typeFrom(MessageTemplateType|string|null $type): ?MessageTemplateType
    {
        return $type instanceof MessageTemplateType ? $type : MessageTemplateType::tryFrom((string) $type);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->heading('Email templates')
            ->description('The emails Mollie sends with invoices, per language. Placeholders like {contact_person} and {month} are filled in per invoice.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('type', MessageTemplateType::forChannel('Email')))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('language')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('subject')
                    ->limit(70)
                    ->searchable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->successNotificationTitle(fn (MessageTemplate $record): string => "Template {$record->name} saved"),
                DeleteAction::make()
                    ->successNotificationTitle(fn (MessageTemplate $record): string => "Template {$record->name} deleted"),
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            SlackMessageTemplates::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMessageTemplates::route('/'),
        ];
    }
}
