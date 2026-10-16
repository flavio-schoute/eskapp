{{--
    Override of Filament's sidebar group (filament/filament resources/views/components/sidebar/group.blade.php), so that:
    - a group can have an icon and its items their own icons as well;
    - in the collapsed sidebar the group icon links straight to the group's first item, instead of opening a dropdown.
    Check this file again after upgrading Filament.
--}}
@props([
    'active' => false,
    'collapsible' => true,
    'icon' => null,
    'items' => [],
    'label' => null,
    'sidebarCollapsible' => true,
    'subNavigation' => false,
])

@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
    use Filament\View\PanelsIconAlias;
    use Illuminate\Contracts\Support\Htmlable;
    use Illuminate\Support\Str;

    $sidebarCollapsible = $sidebarCollapsible && filament()->isSidebarCollapsibleOnDesktop();
    $hasDropdown = filled($label) && filled($icon) && $sidebarCollapsible;
    // A slug alone is not unique: non-Latin labels slug to an empty string and distinct labels can
    // share a slug, producing duplicate ids that break each disclosure button's `aria-controls`.
    // A short hash of the raw label keeps the id unique per label and stable across renders.
    $groupLabel = $subNavigation ? "sub_navigation_{$label}" : (string) $label;
    $groupItemsId = 'fi-sidebar-group-items-' . Str::slug($groupLabel) . '-' . substr(md5($groupLabel), 0, 8);
@endphp

<li
    x-data="{ label: @js($subNavigation ? "sub_navigation_{$label}" : $label) }"
    data-group-label="{{ $subNavigation ? "sub_navigation_{$label}" : $label }}"
    x-bind:class="{ 'fi-collapsed': $store.sidebar.groupIsCollapsed(label) }"
    {{
        $attributes->class([
            'fi-sidebar-group',
            'fi-active' => $active,
            'fi-collapsible' => $collapsible,
        ])
    }}
>
    @if ($label)
        <div
            @if ($collapsible)
                x-on:click="$store.sidebar.toggleCollapsedGroup(label)"
            @endif
            @if ($sidebarCollapsible)
                x-show="$store.sidebar.isOpen"
                x-transition:enter="fi-transition-enter"
                x-transition:enter-start="fi-transition-enter-start"
                x-transition:enter-end="fi-transition-enter-end"
            @endif
            class="fi-sidebar-group-btn"
        >
            @if ($icon)
                {{ \Filament\Support\generate_icon_html($icon, size: IconSize::Large) }}
            @endif

            <span class="fi-sidebar-group-label">
                {{ $label }}
            </span>

            @if ($collapsible)
                <x-filament::icon-button
                    color="gray"
                    :icon="Heroicon::ChevronUp"
                    :icon-alias="PanelsIconAlias::SIDEBAR_GROUP_COLLAPSE_BUTTON"
                    :label="$label"
                    :aria-controls="$groupItemsId"
                    x-bind:aria-expanded="! $store.sidebar.groupIsCollapsed(label)"
                    x-on:click.stop="$store.sidebar.toggleCollapsedGroup(label)"
                    class="fi-sidebar-group-collapse-btn"
                />
            @endif
        </div>
    @endif

    @if ($hasDropdown)
        @php
            $firstItem = collect($items)->first(fn ($item): bool => filled($item->getUrl()));
        @endphp

        <a
            @if ($firstItem)
                {{ \Filament\Support\generate_href_html($firstItem->getUrl(), $firstItem->shouldOpenUrlInNewTab()) }}
            @endif
            aria-label="{{ $label }}"
            x-show="! $store.sidebar.isOpen"
            x-data="{ tooltip: false }"
            x-effect="
                tooltip = $store.sidebar.isOpen
                    ? false
                    : {
                          content: @js($label),
                          placement: document.dir === 'rtl' ? 'left' : 'right',
                          theme: $store.theme,
                      }
            "
            x-tooltip.html="tooltip"
            @class([
                'fi-sidebar-group-dropdown-trigger-btn',
                'fi-active' => $active,
            ])
        >
            {{ \Filament\Support\generate_icon_html($icon, size: IconSize::Large) }}
        </a>
    @endif

    <ul
        @if (filled($label))
            id="{{ $groupItemsId }}"

            @if ($sidebarCollapsible)
                x-show="$store.sidebar.isOpen ? ! $store.sidebar.groupIsCollapsed(label) : ! @js($hasDropdown)"
            @else
                x-show="! $store.sidebar.groupIsCollapsed(label)"
            @endif
            x-collapse.duration.200ms
        @endif
        @if ($sidebarCollapsible)
            x-transition:enter="fi-transition-enter"
            x-transition:enter-start="fi-transition-enter-start"
            x-transition:enter-end="fi-transition-enter-end"
        @endif
        class="fi-sidebar-group-items"
    >
        @foreach ($items as $item)
            @php
                $isItemChildItemsActive = $item->isChildItemsActive();
                $isItemActive = (! $isItemChildItemsActive) && $item->isActive();
                $itemActiveIcon = $item->getActiveIcon();
                $itemBadge = $item->getBadge();
                $itemBadgeColor = $item->getBadgeColor($itemBadge);
                $itemBadgeTooltip = $item->getBadgeTooltip($itemBadge);
                $itemChildItems = $item->getChildItems();
                $itemIcon = $item->getIcon();
                $shouldItemOpenUrlInNewTab = $item->shouldOpenUrlInNewTab();
                $itemUrl = $item->getUrl();
                $itemExtraAttributes = $item->getExtraAttributeBag();

            @endphp

            <x-filament-panels::sidebar.item
                :active="$isItemActive"
                :active-child-items="$isItemChildItemsActive"
                :active-icon="$itemActiveIcon"
                :badge="$itemBadge"
                :badge-color="$itemBadgeColor"
                :badge-tooltip="$itemBadgeTooltip"
                :child-items="$itemChildItems"
                :first="$loop->first"
                :grouped="filled($label)"
                :icon="$itemIcon"
                :last="$loop->last"
                :should-open-url-in-new-tab="$shouldItemOpenUrlInNewTab"
                :sidebar-collapsible="$sidebarCollapsible"
                :sub-navigation="$subNavigation"
                :url="$itemUrl"
                :attributes="\Filament\Support\prepare_inherited_attributes($itemExtraAttributes)"
            >
                {{ $item->getLabel() }}

                @if ($itemIcon instanceof Htmlable)
                    <x-slot name="icon">
                        {{ $itemIcon }}
                    </x-slot>
                @endif

                @if ($itemActiveIcon instanceof Htmlable)
                    <x-slot name="activeIcon">
                        {{ $itemActiveIcon }}
                    </x-slot>
                @endif
            </x-filament-panels::sidebar.item>
        @endforeach
    </ul>
</li>
