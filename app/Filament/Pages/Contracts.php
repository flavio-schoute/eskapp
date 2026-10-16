<?php

namespace App\Filament\Pages;

use App\Enums\AffiliateStatus;
use App\Enums\ContractStatus;
use App\Filament\Resources\Affiliates\Actions\ContractActions;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Models\Affiliate;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Affiliate contracts: who has signed, who is still waiting, and "Generate contract".
 */
class Contracts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Contracts';

    protected static ?string $slug = 'contracts';

    public static function getNavigationBadge(): ?string
    {
        $waiting = Affiliate::query()->whereHas('latestContract', fn (Builder $contract) => $contract->open())->count();

        return $waiting ? (string) $waiting : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for a signature';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Affiliate contracts')
            ->description('Contracts are sent with eSignatures. Once signed, the PDF is stored in the affiliate\'s Google Drive folder.')
            ->query(fn (): Builder => Affiliate::query()->with('latestContract'))
            ->defaultSort('name')
            ->recordUrl(fn (Affiliate $record): string => AffiliateResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Partner name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('latestContract.status')
                    ->label('Contract')
                    ->badge()
                    ->description(fn (Affiliate $record): ?string => $record->latestContract?->is_test ? 'Test contract' : null)
                    ->placeholder('Not sent'),
                TextColumn::make('latestContract.signer_name')
                    ->label('Signer')
                    ->description(fn (Affiliate $record): ?string => $record->latestContract?->signer_email)
                    ->placeholder('-'),
                TextColumn::make('latestContract.created_at')
                    ->label('Sent')
                    ->date('j M Y')
                    ->placeholder('-'),
                TextColumn::make('latestContract.signed_at')
                    ->label('Signed')
                    ->date('j M Y')
                    ->placeholder('-'),
                TextColumn::make('latestContract.drive_url')
                    ->label('Signed contract')
                    ->formatStateUsing(fn (): string => 'Open PDF')
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                    ->color('primary')
                    ->placeholder(fn (Affiliate $record): string => $record->latestContract?->status === ContractStatus::Signed ? 'Storing in Drive…' : '-'),
            ])
            ->filters([
                SelectFilter::make('contract')
                    ->label('Contract')
                    ->options([
                        'not_sent' => 'Not sent',
                        'waiting' => 'Waiting for signature',
                        'signed' => 'Signed',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'not_sent' => $query->whereDoesntHave('contracts'),
                        'waiting' => $query->whereHas('latestContract', fn (Builder $contract) => $contract->open()),
                        'signed' => $query->whereHas('latestContract', fn (Builder $contract) => $contract->where('status', ContractStatus::Signed)),
                        default => $query,
                    }),
                SelectFilter::make('status')
                    ->label('Affiliate status')
                    ->options(AffiliateStatus::class),
            ])
            ->headerActions([
                ContractActions::generate(),
            ])
            ->recordActions([
                ContractActions::refreshStatus(),
                ContractActions::generate(),
            ]);
    }
}
