<?php

namespace App\Filament\Resources\EmailTemplates;

use App\Enums\EmailTemplateType;
use App\Enums\InvoiceLanguage;
use App\Filament\Resources\EmailTemplates\Pages\ManageEmailTemplates;
use App\Models\EmailTemplate;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use UnitEnum;

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Email templates';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('type')
                    ->options(EmailTemplateType::class)
                    ->default(EmailTemplateType::MollieInvoice)
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
                    ->helperText('Selected automatically when generating an invoice for a partner with this language.')
                    ->inline(false),
                TextInput::make('subject')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('body')
                    ->required()
                    ->rows(10)
                    ->maxLength(5000)
                    ->helperText(fn (Get $get): string => static::placeholderHelp($get('type')))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The placeholders that can be used in the chosen template type.
     */
    public static function placeholderHelp(EmailTemplateType|string|null $type): string
    {
        $type = $type instanceof EmailTemplateType ? $type : EmailTemplateType::tryFrom((string) $type);

        return 'Placeholders: '.collect($type?->placeholders() ?? [])
            ->map(fn (string $description, string $placeholder): string => "{$placeholder} = {$description}")
            ->implode(' · ');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('The emails sent with invoices. Use placeholders like {contact_person} and {month}; they are filled in per invoice.')
            ->defaultGroup(Group::make('type')->label('Type')->getTitleFromRecordUsing(fn (EmailTemplate $record): string => $record->type->getLabel()))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('language')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('subject')
                    ->limit(60)
                    ->searchable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(EmailTemplateType::class),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->successNotificationTitle(fn (EmailTemplate $record): string => "Email template {$record->name} saved"),
                DeleteAction::make()
                    ->successNotificationTitle(fn (EmailTemplate $record): string => "Email template {$record->name} deleted"),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEmailTemplates::route('/'),
        ];
    }
}
