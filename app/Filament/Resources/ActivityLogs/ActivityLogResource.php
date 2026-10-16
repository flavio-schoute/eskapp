<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Models\ActivityLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ActivityLogResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ActivityLog::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $modelLabel = 'activity';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return static::activityTable($table, withAffiliate: true)
            ->heading('Activity log')
            ->description('Who changed what and when, for every affiliate.')
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Who')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('affiliate_id')
                    ->label('Affiliate')
                    ->relationship('affiliate', 'name')
                    ->searchable(),
                SelectFilter::make('event')
                    ->label('What')
                    ->options(static::eventLabels()),
            ]);
    }

    /**
     * The activity table, shared with the activity on an affiliate's page.
     */
    public static function activityTable(Table $table, bool $withAffiliate = false): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns(array_values(array_filter([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('j M Y, H:i')
                    ->description(fn (ActivityLog $record): string => $record->created_at->diffForHumans())
                    ->sortable(),
                $withAffiliate ? TextColumn::make('affiliate_name')
                    ->label('Affiliate')
                    ->url(fn (ActivityLog $record): ?string => $record->affiliate_id ? AffiliateResource::getUrl('view', ['record' => $record->affiliate_id]) : null)
                    ->searchable() : null,
                TextColumn::make('user_name')
                    ->label('Who')
                    ->state(fn (ActivityLog $record): string => $record->causer())
                    ->color(fn (ActivityLog $record): ?string => $record->user_name ? null : 'gray'),
                TextColumn::make('description')
                    ->label('What')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('changes')
                    ->label('Changes')
                    ->state(fn (ActivityLog $record): ?Htmlable => static::changesHtml($record))
                    ->wrap()
                    ->placeholder('-'),
            ], fn (?Column $column): bool => $column !== null)))
            ->headerActions([
                static::exportAction(),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * Download the activity in the table, with its filters and sorting, as a CSV file that opens in Excel.
     */
    public static function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export to Excel')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (HasTable $livewire): StreamedResponse {
                $query = $livewire->getFilteredSortedTableQuery();
                $name = $livewire instanceof RelationManager ? Str::slug($livewire->getOwnerRecord()->name).'-' : '';

                return response()->streamDownload(function () use ($query): void {
                    $output = fopen('php://output', 'w');
                    fwrite($output, "\xEF\xBB\xBF");
                    fputcsv($output, ['When', 'Affiliate', 'Who', 'What', 'Changes'], ';');

                    $query->clone()->cursor()->each(function (ActivityLog $log) use ($output): void {
                        fputcsv($output, [
                            $log->created_at->timezone('Europe/Amsterdam')->format('d-m-Y H:i'),
                            $log->affiliate_name,
                            $log->causer(),
                            $log->description,
                            static::changesText($log),
                        ], ';');
                    });

                    fclose($output);
                }, "activity-log-{$name}".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }

    /**
     * The changes as "Field: old → new" lines, for the export.
     */
    public static function changesText(ActivityLog $log): string
    {
        return collect($log->changes ?? [])
            ->map(fn (array $change, string $field): string => "{$field}: ".($change['old'] !== null ? "{$change['old']} → " : '').($change['new'] ?? '(empty)'))
            ->implode("\n");
    }

    /**
     * The changes as "Field: old → new" lines.
     */
    public static function changesHtml(ActivityLog $log): ?Htmlable
    {
        if (blank($log->changes)) {
            return null;
        }

        return new HtmlString(collect($log->changes)
            ->map(fn (array $change, string $field): string => '<div><span style="font-weight: 500;">'.e($field).':</span> '
                .($change['old'] !== null ? '<span style="text-decoration: line-through; opacity: 0.6;">'.e($change['old']).'</span> → ' : '')
                .e($change['new'] ?? '(empty)').'</div>')
            ->implode(''));
    }

    /**
     * @return array<string, string>
     */
    public static function eventLabels(): array
    {
        return [
            'created' => 'Affiliate created',
            'updated' => 'Affiliate updated',
            'deleted' => 'Affiliate deleted',
            'invoice_sent' => 'Invoice sent',
            'invoice_status' => 'Invoice paid / cancelled',
            'contract_sent' => 'Contract sent',
            'contract_status' => 'Contract opened / signed',
            'contract_reminder' => 'Contract reminder sent',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
