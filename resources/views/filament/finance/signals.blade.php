{{-- Budgets this month, subscriptions and possible mistakes in the money going out. --}}
@php
    $th = 'padding: 0.5rem 0.625rem; text-align: left; font-weight: 500; font-size: 0.8rem; opacity: 0.7; white-space: nowrap;';
    $td = 'padding: 0.5rem 0.625rem; border-top: 1px solid rgb(127 127 127 / 0.12);';
    $num = 'text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums;';
    $badge = fn (string $color) => "display: inline-block; margin-inline-start: 0.375rem; padding: 0 0.375rem; border-radius: 0.375rem; font-size: 0.7rem; font-weight: 600; color: {$color}; background: color-mix(in srgb, {$color} 12%, transparent);";
    $money = fn (int $cents, bool $decimals = true) => \App\Services\Finance\Money::format($cents, decimals: $decimals);
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Bankbetalingen en Plug &amp; Pay</x-slot>
        <x-slot name="description">Geld dat per bank binnenkwam (laatste 60 dagen) waarvan de Plug &amp; Pay-order nog open staat, of dat als klantbetaling is geboekt zonder order. De omzet telt pas mee als de order in Plug &amp; Pay betaald is.</x-slot>

        @if ($bankPayments === [])
            <p style="font-size: 0.875rem; opacity: 0.6;">Alle bankbetalingen horen bij een betaalde order.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead><tr><th style="{{ $th }}">Datum</th><th style="{{ $th }}">Signaal</th><th style="{{ $th }}"></th></tr></thead>
                    <tbody>
                        @foreach ($bankPayments as $payment)
                            <tr wire:key="payment-{{ $payment['key'] }}">
                                <td style="{{ $td }} white-space: nowrap; font-family: monospace; opacity: 0.7;">{{ $payment['transaction']->booked_on->format('Y-m-d') }}</td>
                                <td style="{{ $td }}">
                                    <span style="{{ $badge($payment['type'] === 'payment_open' ? '#D97706' : '#DC2626') }} margin-inline-start: 0; margin-inline-end: 0.375rem;">{{ $payment['type'] === 'payment_open' ? 'Order open' : 'Geen order' }}</span>
                                    <a href="{{ $transactionsUrl }}?tableSearch={{ urlencode($payment['transaction']->counterparty_name) }}" style="text-decoration: underline; text-underline-offset: 2px;">{{ str_replace('*', '', $payment['message']) }}</a>
                                </td>
                                <td style="{{ $td }} text-align: right;">
                                    <x-filament::button size="xs" color="gray" wire:click="dismiss(@js($payment['key']), @js($payment['type']), @js($payment['message']))">Klopt</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Afwijkingen</x-slot>
        <x-slot name="description">Betalingen van de laatste 60 dagen: hetzelfde bedrag twee keer binnen een week aan dezelfde partij, of meer dan 3× wat je normaal betaalt.</x-slot>

        @if ($anomalies === [])
            <p style="font-size: 0.875rem; opacity: 0.6;">Niets bijzonders gevonden.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead><tr><th style="{{ $th }}">Datum</th><th style="{{ $th }}">Signaal</th><th style="{{ $th }}"></th></tr></thead>
                    <tbody>
                        @foreach ($anomalies as $anomaly)
                            <tr wire:key="anomaly-{{ $anomaly['key'] }}">
                                <td style="{{ $td }} white-space: nowrap; font-family: monospace; opacity: 0.7;">{{ $anomaly['transaction']->booked_on->format('Y-m-d') }}</td>
                                <td style="{{ $td }}">
                                    <span style="{{ $badge($anomaly['type'] === 'duplicate' ? '#DC2626' : '#D97706') }} margin-inline-start: 0; margin-inline-end: 0.375rem;">{{ $anomaly['type'] === 'duplicate' ? 'Dubbel?' : 'Hoog' }}</span>
                                    <a href="{{ $transactionsUrl }}?tableSearch={{ urlencode($anomaly['transaction']->counterparty_name) }}" style="text-decoration: underline; text-underline-offset: 2px;">{{ str_replace('*', '', $anomaly['message']) }}</a>
                                </td>
                                <td style="{{ $td }} text-align: right;">
                                    <x-filament::button size="xs" color="gray" wire:click="dismiss(@js($anomaly['key']), @js($anomaly['type']), @js($anomaly['message']))">Klopt</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Budgetten · {{ $month }}</x-slot>
        <x-slot name="description">Zet een maandbudget op een categorie onder <a href="{{ $categoriesUrl }}" style="text-decoration: underline;">Categorieën</a>. Ga je eroverheen, dan komt er een melding in #finance.</x-slot>

        @if ($budgets === [])
            <p style="font-size: 0.875rem; opacity: 0.6;">Nog geen budgetten ingesteld.</p>
        @else
            <div style="display: grid; gap: 0.875rem;">
                @foreach ($budgets as $budget)
                    @php($color = $budget['ratio'] > 1 ? '#DC2626' : ($budget['ratio'] >= 0.8 ? '#D97706' : '#16A34A'))
                    <div>
                        <div style="display: flex; justify-content: space-between; gap: 1rem; font-size: 0.875rem; margin-bottom: 0.25rem;">
                            <span style="font-weight: 500;">{{ $budget['label'] }}</span>
                            <span style="{{ $num }}"><span style="color: {{ $color }}; font-weight: 600;">{{ $money($budget['spent'], false) }}</span> <span style="opacity: 0.6;">van {{ $money($budget['budget'], false) }} · {{ round($budget['ratio'] * 100) }}%</span></span>
                        </div>
                        <div style="height: 0.5rem; border-radius: 9999px; background: rgb(127 127 127 / 0.15); overflow: hidden;">
                            <div style="height: 100%; width: {{ min(100, round($budget['ratio'] * 100)) }}%; background: {{ $color }}; border-radius: 9999px;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Abonnementen</x-slot>
        <x-slot name="description">Partijen die je in minstens 3 van de laatste 6 maanden een vergelijkbaar bedrag betaalde (zonder adspend en interne overboekingen).</x-slot>

        @if ($subscriptions === [])
            <p style="font-size: 0.875rem; opacity: 0.6;">Nog geen terugkerende betalingen gevonden.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Partij</th>
                            <th style="{{ $th }}">Categorie</th>
                            <th style="{{ $th }} text-align: right;">Per maand</th>
                            <th style="{{ $th }} text-align: right;">Laatste</th>
                            <th style="{{ $th }}">Laatst betaald</th>
                            <th style="{{ $th }} text-align: right;">Per jaar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subscriptions as $subscription)
                            <tr>
                                <td style="{{ $td }} font-weight: 500;">
                                    {{ $subscription['name'] }}
                                    @if ($subscription['isNew'])<span style="{{ $badge('#2563EB') }}">Nieuw</span>@endif
                                    @if ($subscription['priceUp'])<span style="{{ $badge('#DC2626') }}" title="Was {{ $money($subscription['previous']) }}">Duurder</span>@endif
                                </td>
                                <td style="{{ $td }} opacity: 0.75;">{{ $subscription['category'] ?? 'Nog niet ingedeeld' }}</td>
                                <td style="{{ $td }} {{ $num }}">{{ $money($subscription['typical']) }}</td>
                                <td style="{{ $td }} {{ $num }} {{ $subscription['priceUp'] ? 'color: #DC2626;' : '' }}">{{ $money($subscription['last']) }}</td>
                                <td style="{{ $td }} white-space: nowrap; font-family: monospace; opacity: 0.7;">{{ $subscription['lastOn'] }}</td>
                                <td style="{{ $td }} {{ $num }} font-weight: 500;">{{ $money($subscription['yearly'], false) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td colspan="2" style="{{ $td }} font-weight: 600;">Totaal ({{ count($subscriptions) }})</td>
                            <td style="{{ $td }} {{ $num }} font-weight: 600;">{{ $money(array_sum(array_column($subscriptions, 'typical'))) }}</td>
                            <td colspan="2" style="{{ $td }}"></td>
                            <td style="{{ $td }} {{ $num }} font-weight: 600;">{{ $money(array_sum(array_column($subscriptions, 'yearly')), false) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
