<?php

namespace App\Filament\Pages\Finance;

use App\Filament\Concerns\FinanceOnly;
use App\Models\Finance\Order;
use App\Models\Finance\RevenueStream;
use App\Models\Product;
use App\Services\Finance\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Link each Plug&Pay product (SKU) to a revenue stream. Paid orders count as revenue on their payment date.
 */
class RevenueStreams extends Page implements HasTable
{
    use FinanceOnly;
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Producten';

    protected static ?string $title = 'Producten';

    protected static ?string $slug = 'finance/products';

    public function getSubheading(): string|Htmlable|null
    {
        return 'Link each product (SKU from Plug&Pay) to a revenue stream. Paid orders count as revenue on their payment date.';
    }

    public static function getNavigationBadge(): ?string
    {
        $unmapped = static::unmappedProducts()->count();

        return $unmapped > 0 ? (string) $unmapped : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Producten met omzet zonder omzetstroom';
    }

    /**
     * Products with paid orders that are not linked to a revenue stream yet.
     *
     * @return Builder<Product>
     */
    public static function unmappedProducts(): Builder
    {
        return Product::query()
            ->whereNull('revenue_stream_id')
            ->whereHas('financeOrders', fn (Builder $query) => $query->where('status', 'paid'));
    }

    /**
     * SKUs used by more than one active Plug&Pay product.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Product>>
     */
    public static function duplicateSkus(): \Illuminate\Support\Collection
    {
        return Product::query()
            ->whereNull('deleted_in_plug_and_pay_at')
            ->whereNotNull('sku')
            ->whereIn('sku', Product::query()->whereNull('deleted_in_plug_and_pay_at')->whereNotNull('sku')->select('sku')->groupBy('sku')->havingRaw('count(*) > 1'))
            ->with('revenueStream')
            ->orderBy('sku')
            ->get()
            ->groupBy('sku');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('warnings')
                ->hiddenLabel()
                ->state(fn (): ?Htmlable => $this->warningsHtml())
                ->visible(fn (): bool => static::unmappedProducts()->exists() || static::duplicateSkus()->isNotEmpty()),
            Section::make('Omzetstromen')
                ->description('Paid revenue excl. VAT, all time.')
                ->headerActions([$this->manageStreamsAction()])
                ->schema([
                    TextEntry::make('streams')->hiddenLabel()->state(fn (): Htmlable => $this->streamsHtml()),
                ]),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Product::query()
                ->where(fn (Builder $query) => $query->whereNull('deleted_in_plug_and_pay_at')->orWhereHas('financeOrders'))
                ->withCount('financeOrders')
                ->withMax('financeOrders', 'invoiced_on')
                ->withSum(['financeOrders as paid_revenue' => fn (Builder $query) => $query->where('status', 'paid')], 'subtotal_ex_vat_cents'))
            ->defaultSort('paid_revenue', 'desc')
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable()
                    ->placeholder('-')
                    ->badge(fn (Product $record): bool => static::duplicateSkus()->has((string) $record->sku))
                    ->color(fn (Product $record): ?string => static::duplicateSkus()->has((string) $record->sku) ? 'warning' : null)
                    ->tooltip(fn (Product $record): ?string => static::duplicateSkus()->has((string) $record->sku) ? 'Deze SKU wordt door meerdere producten gebruikt' : null),
                TextColumn::make('title')
                    ->label('Product')
                    ->description(fn (Product $record): string => $record->finance_orders_count.' orders'.($record->finance_orders_max_invoiced_on ? ' · laatst '.substr((string) $record->finance_orders_max_invoiced_on, 0, 10) : '').($record->deleted_in_plug_and_pay_at ? ' · verwijderd in Plug&Pay' : ''))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('paid_revenue')
                    ->label('Betaalde omzet')
                    ->formatStateUsing(fn (mixed $state): string => Money::format((int) $state, decimals: false))
                    ->alignEnd()
                    ->sortable(),
                SelectColumn::make('revenue_stream_id')
                    ->label('Omzetstroom')
                    ->options(fn (): array => RevenueStream::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('Nog niet ingedeeld')
                    // Products sharing a SKU always get the same revenue stream, like in the Finance app (per SKU).
                    ->updateStateUsing(function (?string $state, Product $record): ?string {
                        Product::query()
                            ->when(filled($record->sku), fn (Builder $query) => $query->where('sku', $record->sku), fn (Builder $query) => $query->whereKey($record->getKey()))
                            ->update(['revenue_stream_id' => $state ?: null]);

                        return $state;
                    }),
            ])
            ->filters([
                TernaryFilter::make('mapped')
                    ->label('Producten')
                    ->placeholder('Alle producten')
                    ->trueLabel('Ingedeeld')
                    ->falseLabel('Nog niet ingedeeld')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('revenue_stream_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('revenue_stream_id'),
                    ),
            ])
            ->toolbarActions([
                BulkAction::make('setStream')
                    ->label('Omzetstroom kiezen')
                    ->icon(Heroicon::OutlinedTag)
                    ->schema([Select::make('revenue_stream_id')->label('Omzetstroom')->options(fn (): array => RevenueStream::query()->orderBy('sort_order')->pluck('name', 'id')->all())->placeholder('Nog niet ingedeeld')])
                    ->action(function (Collection $records, array $data): void {
                        Product::query()->whereKey($records->modelKeys())->update(['revenue_stream_id' => $data['revenue_stream_id'] ?? null]);

                        Notification::make()->success()->title($records->count().' producten ingedeeld')->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    private function warningsHtml(): ?Htmlable
    {
        $warnings = [];
        $unmapped = static::unmappedProducts()->get(['sku', 'title']);

        if ($unmapped->isNotEmpty()) {
            $warnings[] = '<b>'.$unmapped->count().' '.($unmapped->count() === 1 ? 'nieuw product' : 'nieuwe producten').' zonder omzetstroom</b> (de omzet telt nu onder "Nog niet ingedeeld"): '
                .$unmapped->map(fn (Product $product): string => e(trim($product->sku.' '.$product->title)))->implode(', ').'. Kies hieronder de omzetstroom.';
        }

        foreach (static::duplicateSkus() as $sku => $products) {
            $streams = $products->map(fn (Product $product): string => $product->revenueStream?->name ?? 'Nog niet ingedeeld')->unique();
            $warnings[] = '<b>Dubbele SKU '.e($sku).'</b>: gebruikt door '.$products->count().' producten in Plug&Pay ('.$products->map(fn (Product $product): string => e($product->title).' #'.$product->plug_and_pay_product_id)->implode(', ').').'
                .($streams->count() > 1 ? ' Ze staan op verschillende omzetstromen: '.e($streams->implode(', ')).'.' : ' Geef elk product in Plug&Pay een eigen SKU.');
        }

        return $warnings === [] ? null : new HtmlString(collect($warnings)->map(fn (string $warning): string => '<div style="margin-bottom: 0.375rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; background: rgb(217 119 6 / 0.08); box-shadow: inset 0 0 0 1px rgb(217 119 6 / 0.25); font-size: 0.875rem;">⚠️ '.$warning.'</div>')->implode(''));
    }

    private function manageStreamsAction(): Action
    {
        return Action::make('manageStreams')
            ->label('Omzetstromen bewerken')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading('Omzetstromen')
            ->modalDescription('Add, rename or remove revenue streams. Products of a removed stream become "Nog niet ingedeeld". Talk to Gairo before adding a new stream.')
            ->fillForm(fn (): array => ['streams' => RevenueStream::query()->orderBy('sort_order')->orderBy('name')->get()->map(fn (RevenueStream $stream): array => ['id' => $stream->getKey(), 'name' => $stream->name, 'color' => $stream->color])->all()])
            ->schema([
                Repeater::make('streams')
                    ->hiddenLabel()
                    ->addActionLabel('Omzetstroom toevoegen')
                    ->reorderable()
                    ->table([Repeater\TableColumn::make('Naam'), Repeater\TableColumn::make('Kleur')->width('8rem')])
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('name')->hiddenLabel()->required()->maxLength(60)->distinct(),
                        ColorPicker::make('color')->hiddenLabel(),
                    ]),
            ])
            ->action(function (array $data): void {
                $keep = [];

                foreach (array_values($data['streams'] ?? []) as $order => $row) {
                    $stream = RevenueStream::updateOrCreate(['id' => $row['id'] ?? null], ['name' => $row['name'], 'color' => $row['color'] ?? null, 'sort_order' => ($order + 1) * 10]);
                    $keep[] = $stream->getKey();
                }

                RevenueStream::query()->whereKeyNot($keep)->delete();

                Notification::make()->success()->title('Omzetstromen opgeslagen')->send();
            });
    }

    private function streamsHtml(): Htmlable
    {
        $paid = Order::query()
            ->where('finance_orders.status', 'paid')
            ->leftJoin('products', 'products.plug_and_pay_product_id', '=', 'finance_orders.plug_and_pay_product_id')
            ->selectRaw('products.revenue_stream_id as stream_id, sum(finance_orders.subtotal_ex_vat_cents) as total')
            ->groupBy('products.revenue_stream_id')
            ->pluck('total', 'stream_id');

        $rows = RevenueStream::query()->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (RevenueStream $stream): string => $this->streamRow($stream->name, $stream->color, (int) ($paid[$stream->getKey()] ?? 0)))
            ->push($this->streamRow('Nog niet ingedeeld', '#D97706', (int) ($paid[''] ?? 0), warning: true))
            ->implode('');

        return new HtmlString('<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); gap: 0.25rem 2rem;">'.$rows.'</div>');
    }

    private function streamRow(string $name, ?string $color, int $cents, bool $warning = false): string
    {
        return '<div style="display: flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0;'.($warning ? ' color: var(--warning-600);' : '').'">'
            .'<span style="width: 0.6rem; height: 0.6rem; border-radius: 9999px; background: '.e($color ?? '#A39E93').';"></span>'
            .'<span style="flex: 1;">'.e($name).'</span>'
            .'<span style="font-variant-numeric: tabular-nums;">'.Money::format($cents, decimals: false).'</span></div>';
    }
}
