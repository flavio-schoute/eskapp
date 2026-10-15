<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RevenueStats;
use App\Models\User;
use App\Support\RevenueReport;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Invoiced versus received per month, and the affiliate manager's commission over what was received.
 */
class Revenue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Revenue';

    protected static ?string $slug = 'revenue';

    protected function getHeaderWidgets(): array
    {
        return [
            RevenueStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->commissionSettingsAction(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $settings = RevenueReport::commissionSettings();

        return $table
            ->heading('Per month')
            ->description('Invoiced counts by invoice date, received by payment date, both excl. VAT. Cancelled invoices are left out. The commission is '.static::percentage().' of what was received.')
            ->records(fn (): array => RevenueReport::lastMonths(12))
            ->columns([
                TextColumn::make('label')
                    ->label('Month')
                    ->weight('medium'),
                TextColumn::make('invoiced')
                    ->label('Invoiced')
                    ->formatStateUsing(fn (float $state): string => RevenueReport::money($state))
                    ->description(fn (array $record): string => trans_choice('{0} No invoices|{1} 1 invoice|[2,*] :count invoices', $record['invoiced_count']))
                    ->summarize(static::sum('invoiced')),
                TextColumn::make('received')
                    ->label('Received')
                    ->formatStateUsing(fn (float $state): string => RevenueReport::money($state))
                    ->description(fn (array $record): string => RevenueReport::money($record['received_incl_vat']).' incl. VAT')
                    ->color('success')
                    ->summarize(static::sum('received')),
                TextColumn::make('commission')
                    ->label("Commission {$settings['manager']}")
                    ->formatStateUsing(fn (float $state): string => RevenueReport::money($state))
                    ->weight('medium')
                    ->summarize(static::sum('commission')),
            ])
            ->recordAction('details')
            ->recordActions([
                Action::make('details')
                    ->label('Per affiliate')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->color('gray')
                    ->modalHeading(fn (array $record): string => "Per affiliate · {$record['label']}")
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (array $record): array => [
                        TextEntry::make('per_affiliate')
                            ->hiddenLabel()
                            ->state(static::perAffiliateHtml(Carbon::parse("{$record['month']}-01"))),
                    ]),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Export to Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (): StreamedResponse => static::export()),
            ])
            ->paginated(false);
    }

    /**
     * Change who gets the affiliate manager commission and the percentage. Admins only.
     */
    protected function commissionSettingsAction(): Action
    {
        return Action::make('commissionSettings')
            ->label('Commission settings')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->color('gray')
            ->visible(fn (): bool => auth()->user() instanceof User && auth()->user()->isAdmin())
            ->modalHeading('Affiliate manager commission')
            ->modalDescription('A percentage of everything received from affiliates (excl. VAT), per month.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => RevenueReport::commissionSettings())
            ->schema([
                TextInput::make('manager')
                    ->label('Affiliate manager')
                    ->required()
                    ->maxLength(255),
                TextInput::make('percentage')
                    ->label('Commission')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->required(),
            ])
            ->action(function (array $data): void {
                abort_unless(auth()->user() instanceof User && auth()->user()->isAdmin(), 403);

                RevenueReport::saveCommissionSettings($data);

                Notification::make()->success()->title('Commission settings saved')->send();
            });
    }

    protected static function sum(string $column): Summarizer
    {
        return Summarizer::make()
            ->label('Last 12 months')
            ->using(fn (): string => RevenueReport::money(collect(RevenueReport::lastMonths(12))->sum($column)));
    }

    protected static function percentage(): string
    {
        return rtrim(rtrim(number_format(RevenueReport::commissionSettings()['percentage'], 2, ',', ''), '0'), ',').'%';
    }

    protected static function perAffiliateHtml(Carbon $month): Htmlable
    {
        $rows = RevenueReport::perAffiliate($month);

        if ($rows === []) {
            return new HtmlString('<p style="opacity: 0.7;">Nothing invoiced or received this month.</p>');
        }

        $cell = 'padding: 0.375rem 0.5rem; border-bottom: 1px solid rgb(127 127 127 / 0.2);';
        $right = $cell.' text-align: right; white-space: nowrap;';

        $body = collect($rows)->map(fn (array $row): string => '<tr>'
            .'<td style="'.$cell.'">'.e($row['affiliate']).'</td>'
            .'<td style="'.$right.'">'.RevenueReport::money($row['invoiced']).'</td>'
            .'<td style="'.$right.'">'.RevenueReport::money($row['received']).'</td>'
            .'<td style="'.$right.'">'.RevenueReport::money($row['commission']).'</td>'
            .'</tr>')->implode('');

        $total = fn (string $column): string => RevenueReport::money(collect($rows)->sum($column));

        return new HtmlString('<table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">'
            .'<thead><tr style="font-weight: 600;"><td style="'.$cell.'">Affiliate</td><td style="'.$right.'">Invoiced</td><td style="'.$right.'">Received</td><td style="'.$right.'">Commission</td></tr></thead>'
            .'<tbody>'.$body.'</tbody>'
            .'<tfoot><tr style="font-weight: 600;"><td style="padding: 0.375rem 0.5rem;">Total</td><td style="padding: 0.375rem 0.5rem; text-align: right;">'.$total('invoiced').'</td><td style="padding: 0.375rem 0.5rem; text-align: right;">'.$total('received').'</td><td style="padding: 0.375rem 0.5rem; text-align: right;">'.$total('commission').'</td></tr></tfoot>'
            .'</table>');
    }

    /**
     * The last 12 months as a CSV file that opens in Excel, with the commission per month.
     */
    protected static function export(): StreamedResponse
    {
        $manager = RevenueReport::commissionSettings()['manager'];

        return response()->streamDownload(function () use ($manager): void {
            $output = fopen('php://output', 'w');
            $amount = fn (float $value): string => number_format($value, 2, ',', '');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Month', 'Invoices', 'Invoiced excl. VAT', 'Invoiced incl. VAT', 'Payments', 'Received excl. VAT', 'Received incl. VAT', "Commission {$manager}"], ';');

            foreach (RevenueReport::lastMonths(12) as $month) {
                fputcsv($output, [
                    $month['label'],
                    $month['invoiced_count'],
                    $amount($month['invoiced']),
                    $amount($month['invoiced_incl_vat']),
                    $month['received_count'],
                    $amount($month['received']),
                    $amount($month['received_incl_vat']),
                    $amount($month['commission']),
                ], ';');
            }

            fclose($output);
        }, 'revenue-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
