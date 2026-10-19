<?php

namespace App\Filament\Resources\Finance\Categories\Pages;

use App\Filament\Resources\Finance\Categories\CategoryResource;
use App\Models\Finance\Category;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionSplit;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * The categories as cards per main group, like the Finance app: edit the group, and edit, archive or delete its
 * subcategories.
 */
class ManageCategories extends Page
{
    protected static string $resource = CategoryResource::class;

    protected string $view = 'filament.finance.categories';

    public bool $showArchived = false;

    public function getTitle(): string|Htmlable
    {
        return 'Categorieën';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Hoofdgroepen met subcategorieën. Transacties krijgen altijd een subcategorie; de W&V telt per groep op.';
    }

    /**
     * @return Collection<int, Category>
     */
    public function groups(): Collection
    {
        return Category::query()
            ->whereNull('parent_id')
            ->when(! $this->showArchived, fn ($query) => $query->where('archived', false))
            ->with(['children' => fn ($query) => $query->when(! $this->showArchived, fn ($query) => $query->where('archived', false))])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createGroup')
                ->label('Hoofdgroep')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Nieuwe hoofdgroep')
                ->schema(fn (Schema $schema): Schema => CategoryResource::form($schema))
                ->action(function (array $data): void {
                    Category::create([...$data, 'sort_order' => ((int) Category::query()->whereNull('parent_id')->max('sort_order')) + 10]);

                    Notification::make()->success()->title("Hoofdgroep {$data['name']} toegevoegd")->send();
                }),
        ];
    }

    public function addSubcategoryAction(): Action
    {
        return Action::make('addSubcategory')
            ->modalHeading('Nieuwe subcategorie')
            ->schema(fn (Schema $schema): Schema => CategoryResource::form($schema))
            ->fillForm(function (array $arguments): array {
                $group = Category::findOrFail($arguments['group']);

                return ['parent_id' => $group->getKey(), 'kind' => $group->kind, 'default_vat_rate' => $group->default_vat_rate === null ? null : (string) $group->default_vat_rate, 'color' => $group->color];
            })
            ->action(function (array $data): void {
                Category::create([...$data, 'sort_order' => ((int) Category::query()->where('parent_id', $data['parent_id'])->max('sort_order')) + 10]);

                Notification::make()->success()->title("Subcategorie {$data['name']} toegevoegd")->send();
            });
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->modalHeading('Bewerken')
            ->record(fn (array $arguments): ?Category => Category::find($arguments['category'] ?? null))
            ->schema(fn (Schema $schema): Schema => CategoryResource::form($schema->operation('edit')))
            ->fillForm(fn (Category $record): array => [...$record->attributesToArray(), 'kind' => $record->kind, 'default_vat_rate' => $record->default_vat_rate === null ? null : (string) $record->default_vat_rate])
            ->action(function (Category $record, array $data): void {
                $record->update($data);

                Notification::make()->success()->title('Opgeslagen')->send();
            });
    }

    public function archiveAction(): Action
    {
        return Action::make('archive')
            ->record(fn (array $arguments): ?Category => Category::find($arguments['category'] ?? null))
            ->action(function (Category $record): void {
                $record->update(['archived' => ! $record->archived]);

                Notification::make()->success()->title($record->archived ? "{$record->name} gearchiveerd" : "{$record->name} hersteld")->send();
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->requiresConfirmation()
            ->modalHeading(fn (Category $record): string => "{$record->name} verwijderen?")
            ->record(fn (array $arguments): ?Category => Category::find($arguments['category'] ?? null))
            ->action(function (Category $record): void {
                $count = Transaction::query()->where('category_id', $record->getKey())->count();
                $reason = match (true) {
                    $count > 0 => "Er hangen nog {$count} transacties aan deze categorie — archiveer hem in plaats van verwijderen.",
                    $record->children()->exists() => 'Deze categorie heeft nog subcategorieën.',
                    TransactionSplit::query()->where('category_id', $record->getKey())->exists() => 'Deze categorie wordt nog gebruikt in splitsingen.',
                    default => null,
                };

                if ($reason) {
                    Notification::make()->danger()->title('Kan niet verwijderen')->body($reason)->send();

                    return;
                }

                $record->delete();

                Notification::make()->success()->title("{$record->name} verwijderd")->send();
            });
    }
}
