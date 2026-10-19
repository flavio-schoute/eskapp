{{-- "Nog niet compleet" banner; $items is a list of ['text' => string, 'url' => ?string]. --}}
@if ($items !== [])
    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 1rem; padding: 0.625rem 1rem; border-radius: 0.5rem; border: 1px solid rgb(217 119 6 / 0.35); background: rgb(217 119 6 / 0.06); font-size: 0.875rem;">
        <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width: 1.125rem; height: 1.125rem; color: #D97706;" />
        <span style="font-weight: 600;">Nog niet compleet</span>
        @foreach ($items as $item)
            @if ($item['url'])
                <a href="{{ $item['url'] }}" style="opacity: 0.75;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">{{ $item['text'] }}</a>
            @else
                <span style="opacity: 0.75;">{{ $item['text'] }}</span>
            @endif
        @endforeach
    </div>
@endif
