{{-- Margin per revenue stream: revenue minus the cost categories linked to the stream. --}}
@php
    $th = 'padding: 0.5rem 0.5rem; font-weight: 500; font-size: 0.8rem; opacity: 0.7; white-space: nowrap;';
    $td = 'padding: 0.5rem 0.5rem; border-top: 1px solid rgb(127 127 127 / 0.12); white-space: nowrap; font-variant-numeric: tabular-nums;';
    $money = fn (int $cents) => \App\Services\Finance\Money::format($cents, decimals: false);
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Marge per omzetstroom</x-slot>
        <x-slot name="description">Omzet min de kosten die bij die stroom horen. Koppel kosten aan een stroom onder <a href="{{ $categoriesUrl }}" style="text-decoration: underline;">Categorieën</a>.</x-slot>

        @if ($rows === [])
            <p style="font-size: 0.875rem; opacity: 0.6;">Geen omzet in deze periode.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                    <thead>
                        <tr>
                            <th style="{{ $th }} text-align: left;">Stroom</th>
                            <th style="{{ $th }} text-align: right;">Omzet</th>
                            <th style="{{ $th }} text-align: right;">Directe kosten</th>
                            <th style="{{ $th }} text-align: right;">Marge</th>
                            <th style="{{ $th }} text-align: right;">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="{{ $td }} font-weight: 500;" title="{{ $row['categories'] === [] ? 'Nog geen kosten gekoppeld' : implode(', ', $row['categories']) }}">
                                    {{ $row['name'] }}
                                    @if ($row['categories'] === [])<span style="opacity: 0.45; font-weight: 400;"> · geen kosten gekoppeld</span>@endif
                                </td>
                                <td style="{{ $td }} text-align: right;">{{ $money($row['revenue']) }}</td>
                                <td style="{{ $td }} text-align: right; opacity: 0.75;">{{ $row['costs'] ? $money($row['costs']) : '–' }}</td>
                                <td style="{{ $td }} text-align: right; font-weight: 600; {{ $row['margin'] < 0 ? 'color: var(--danger-600);' : '' }}">{{ $money($row['margin']) }}</td>
                                <td style="{{ $td }} text-align: right; opacity: 0.75;">{{ $row['ratio'] === null ? '–' : str_replace('.', ',', number_format($row['ratio'] * 100, 1)).'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
