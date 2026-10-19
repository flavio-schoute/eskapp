{{-- An ⓘ that shows an explanation on hover (e.g. what a category is for). Renders nothing without text. --}}
@props(['text' => null])

@if (filled($text))
    <span
        x-data
        x-tooltip="{ content: @js($text), theme: $store.theme, allowHTML: false, maxWidth: 320 }"
        x-on:click.stop
        {{ $attributes->merge(['style' => 'display: inline-flex; vertical-align: middle; cursor: help; opacity: 0.45;']) }}
        aria-label="{{ $text }}"
    >
        <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedInformationCircle" style="width: 0.95rem; height: 0.95rem;" />
    </span>
@endif
