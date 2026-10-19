{{-- "Nog niet compleet": shown when lines wait in the inbox or revenue has no revenue stream yet. --}}
<x-filament-widgets::widget>
    @include('filament.finance.notice-banner', ['items' => array_values(array_filter([
        $openInbox > 0 ? ['text' => $openInbox.($openInbox === 1 ? ' bankregel wacht in de inbox en telt' : ' bankregels wachten in de inbox en tellen').' nog niet (volledig) mee', 'url' => $inboxUrl] : null,
        $unmapped ? ['text' => "{$unmapped} omzet van producten zonder omzetstroom", 'url' => $productsUrl] : null,
    ]))])
</x-filament-widgets::widget>
