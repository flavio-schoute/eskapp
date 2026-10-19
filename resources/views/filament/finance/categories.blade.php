{{-- Categories as cards per main group, like the Finance app. Actions show on hover. --}}
<x-filament-panels::page>
    <style>
        .finance-category-row .finance-category-actions { opacity: 0; }
        .finance-category-row:hover { background: rgb(127 127 127 / 0.08); }
        .finance-category-row:hover .finance-category-actions { opacity: 1; }
        .finance-icon-button { padding: 0.125rem; opacity: 0.6; }
        .finance-icon-button:hover { opacity: 1; }
    </style>

    <label style="display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.875rem;">
        <input type="checkbox" wire:model.live="showArchived" class="fi-checkbox-input">
        Gearchiveerde tonen
    </label>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr)); gap: 0.875rem; align-items: start;">
        @foreach ($this->groups() as $group)
            <x-filament::section :compact="true" @class(['opacity-60' => $group->archived])>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="width: 0.65rem; height: 0.65rem; border-radius: 9999px; background: {{ $group->color ?? '#A39E93' }};"></span>
                    <span style="font-size: 1.05rem; font-weight: 600; flex: 1;">{{ $group->name }} <x-finance.info :text="$group->description" />@if ($group->archived) <span style="font-size: 0.75rem; opacity: 0.6;">(gearchiveerd)</span>@endif</span>
                    <x-filament::badge color="gray" size="sm">{{ $group->kind->getLabel() }}</x-filament::badge>
                    <button type="button" class="finance-icon-button" wire:click="mountAction('edit', { category: {{ $group->id }} })" title="Bewerken">
                        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPencil" style="width: 1rem; height: 1rem;" />
                    </button>
                </div>
                @if ($group->below_operating)
                    <div style="margin-top: 0.25rem; font-size: 0.8rem; color: #B45309;">Onder de operationele winst</div>
                @endif

                <div style="margin-top: 0.625rem; display: flex; flex-direction: column;">
                    @foreach ($group->children as $category)
                        <div class="finance-category-row" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.3rem 0.5rem; border-radius: 0.375rem; font-size: 0.9rem; {{ $category->archived ? 'opacity: 0.5;' : '' }}">
                            <span style="flex: 1;">
                                {{ $category->name }} <x-finance.info :text="$category->description" />
                                {{-- E.g. a transfer under the Refunds group: say so, it is easy to miss. --}}
                                @if (! $category->kind->countsInProfitAndLoss() && $group->kind->countsInProfitAndLoss())
                                    <span style="margin-inline-start: 0.25rem; padding: 0 0.375rem; border-radius: 0.375rem; font-size: 0.7rem; background: rgb(127 127 127 / 0.15); white-space: nowrap;">telt niet mee</span>
                                @endif
                            </span>
                            <span style="font-family: ui-monospace, monospace; font-size: 0.75rem; opacity: 0.55;">{{ $category->code }}</span>
                            <span style="font-size: 0.75rem; opacity: 0.55; min-width: 2rem;">{{ $category->default_vat_rate === null ? '' : round($category->default_vat_rate * 100).'%' }}</span>
                            <span class="finance-category-actions" style="display: inline-flex; gap: 0.25rem;">
                                <button type="button" class="finance-icon-button" wire:click="mountAction('edit', { category: {{ $category->id }} })" title="Bewerken"><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPencil" style="width: 0.9rem; height: 0.9rem;" /></button>
                                <button type="button" class="finance-icon-button" wire:click="mountAction('archive', { category: {{ $category->id }} })" title="{{ $category->archived ? 'Herstellen' : 'Archiveren' }}"><x-filament::icon :icon="$category->archived ? \Filament\Support\Icons\Heroicon::OutlinedArrowUturnLeft : \Filament\Support\Icons\Heroicon::OutlinedArchiveBox" style="width: 0.9rem; height: 0.9rem;" /></button>
                                <button type="button" class="finance-icon-button" wire:click="mountAction('delete', { category: {{ $category->id }} })" title="Verwijderen"><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedTrash" style="width: 0.9rem; height: 0.9rem;" /></button>
                            </span>
                        </div>
                    @endforeach
                    <button type="button" wire:click="mountAction('addSubcategory', { group: {{ $group->id }} })" style="align-self: flex-start; margin-top: 0.375rem; padding: 0 0.5rem; font-size: 0.8rem; color: #C2410C;">+ Subcategorie</button>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
