{{-- The P&L matrix: click a group to see its lines, and an amount for the transactions behind it. --}}
@php
    $cellStyle = 'padding: 0.45rem 0.4rem; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums;';
    $nameStyle = 'padding: 0.45rem 0.5rem; text-align: left; white-space: nowrap;';
    $totalStyle = 'background: rgb(127 127 127 / 0.08); font-weight: 600;';
    $first = $months[0];
    $last = end($months);
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Winst &amp; verlies</x-slot>
        <x-slot name="afterHeader">
            <div style="display: inline-flex; border-radius: 0.5rem; box-shadow: inset 0 0 0 1px rgb(127 127 127 / 0.25); overflow: hidden; font-size: 0.8rem;">
                <button type="button" wire:click="$set('percentOfRevenue', false)" style="padding: 0.25rem 0.625rem; {{ $this->percentOfRevenue ? '' : 'background: rgb(127 127 127 / 0.15); font-weight: 600;' }}">€</button>
                <button type="button" wire:click="$set('percentOfRevenue', true)" style="padding: 0.25rem 0.625rem; {{ $this->percentOfRevenue ? 'background: rgb(127 127 127 / 0.15); font-weight: 600;' : '' }}">% van omzet</button>
            </div>
        </x-slot>

        <div style="overflow-x: auto;">
            <table x-data="{ open: {} }" style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                <thead>
                    <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.25); opacity: 0.7; font-size: 0.8rem;">
                        <th style="{{ $nameStyle }} font-weight: 500;">Post</th>
                        @foreach ($months as $month)
                            <th style="{{ $cellStyle }} font-weight: 500;">{{ $monthLabel($month) }}</th>
                        @endforeach
                        <th style="{{ $cellStyle }} font-weight: 500;">Totaal</th>
                        @if ($previous)
                            <th style="{{ $cellStyle }} font-weight: 500;">Vorig jaar</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    {{-- Revenue --}}
                    <tr style="{{ $totalStyle }}">
                        <td style="{{ $nameStyle }}">Omzet</td>
                        @foreach ($months as $month)
                            <td style="{{ $cellStyle }}">{{ $cell($pl['revenue'], [$month]) }}</td>
                        @endforeach
                        <td style="{{ $cellStyle }}">{{ $cell($pl['revenue'], $months) }}</td>
                        @if ($previous)
                            <td style="{{ $cellStyle }} opacity: 0.7;">{{ $cell($pl['revenue'], $previous) }}</td>
                        @endif
                    </tr>
                    @foreach ($pl['revenueStreams'] as $stream)
                        @php($streamCategory = str_starts_with($stream['id'], 'cat:') ? (int) substr($stream['id'], 4) : null)
                        @php($streamOpen = $streamCategory ? in_array($streamCategory, $this->expandedLines, true) : in_array($stream['id'], $this->expandedStreams, true))
                        <tr wire:click="{{ $streamCategory ? "toggleLine({$streamCategory})" : "toggleStream('{$stream['id']}')" }}" title="{{ $streamCategory ? 'Klik voor de transacties' : 'Klik voor de producten' }}" style="border-bottom: 1px solid rgb(127 127 127 / 0.12); cursor: pointer; {{ $streamOpen ? 'background: rgb(127 127 127 / 0.06);' : '' }}">
                            <td style="{{ $nameStyle }} padding-inline-start: 1.5rem; font-weight: 500; {{ $stream['id'] === \App\Services\Finance\ProfitAndLoss::UnmappedStream ? 'color: #D97706;' : '' }}">{{ $stream['name'] }}</td>
                            @foreach ($months as $month)
                                <td style="{{ $cellStyle }}">
                                    @if (str_starts_with($stream['id'], 'cat:') && ($stream['byMonth'][$month] ?? 0) !== 0)
                                        <button type="button" wire:click.stop="mountAction('drilldown', { category: {{ $streamCategory }}, from: '{{ $month }}', to: '{{ $month }}' })" style="text-decoration: underline dotted; text-underline-offset: 3px;">{{ $cell($stream['byMonth'], [$month]) }}</button>
                                    @else
                                        {{ $cell($stream['byMonth'], [$month]) }}
                                    @endif
                                </td>
                            @endforeach
                            <td style="{{ $cellStyle }} font-weight: 500;">{{ $cell($stream['byMonth'], $months) }}</td>
                            @if ($previous)
                                <td style="{{ $cellStyle }} opacity: 0.7;">{{ $cell($stream['byMonth'], $previous) }}</td>
                            @endif
                        </tr>
                        @if ($streamCategory)
                            @include('filament.finance.profit-and-loss-transactions', ['categoryId' => $streamCategory, 'sign' => -1])
                        @elseif ($streamOpen)
                            @include('filament.finance.profit-and-loss-products', ['products' => $streamProducts[$stream['id']] ?? collect()])
                        @endif
                    @endforeach

                    {{-- Operating costs and costs below the operating profit --}}
                    @foreach ([['label' => 'Operationele kosten', 'total' => $pl['costs'], 'groups' => $pl['costGroups']]] as $section)
                        <tr style="{{ $totalStyle }}">
                            <td style="{{ $nameStyle }}">{{ $section['label'] }}</td>
                            @foreach ($months as $month)
                                <td style="{{ $cellStyle }}">{{ $cell($section['total'], [$month]) }}</td>
                            @endforeach
                            <td style="{{ $cellStyle }}">{{ $cell($section['total'], $months) }}</td>
                            @if ($previous)
                                <td style="{{ $cellStyle }} opacity: 0.7;">{{ $cell($section['total'], $previous) }}</td>
                            @endif
                        </tr>
                        @foreach ($section['groups'] as $group)
                            @include('filament.finance.profit-and-loss-group', ['group' => $group])
                        @endforeach
                    @endforeach

                    <tr style="{{ $totalStyle }}">
                        <td style="{{ $nameStyle }}">Operationele winst</td>
                        @foreach ($months as $month)
                            <td style="{{ $cellStyle }} {{ ($pl['operating'][$month] ?? 0) < 0 ? 'color: var(--danger-600);' : '' }}">{{ $cell($pl['operating'], [$month]) }}</td>
                        @endforeach
                        <td style="{{ $cellStyle }}">{{ $cell($pl['operating'], $months) }}</td>
                        @if ($previous)
                            <td style="{{ $cellStyle }} opacity: 0.7;">{{ $cell($pl['operating'], $previous) }}</td>
                        @endif
                    </tr>

                    @if ($pl['belowGroups'] !== [])
                        @foreach ($pl['belowGroups'] as $group)
                            @include('filament.finance.profit-and-loss-group', ['group' => $group])
                        @endforeach
                        <tr style="{{ $totalStyle }}">
                            <td style="{{ $nameStyle }}">Winst na alle kosten</td>
                            @foreach ($months as $month)
                                <td style="{{ $cellStyle }} {{ ($pl['net'][$month] ?? 0) < 0 ? 'color: var(--danger-600);' : '' }}">{{ $cell($pl['net'], [$month]) }}</td>
                            @endforeach
                            <td style="{{ $cellStyle }}">{{ $cell($pl['net'], $months) }}</td>
                            @if ($previous)
                                <td style="{{ $cellStyle }} opacity: 0.7;">{{ $cell($pl['net'], $previous) }}</td>
                            @endif
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
        <p style="margin-top: 0.75rem; font-size: 0.8rem; opacity: 0.6;">Klik op een groep om de posten te zien, op een post of omzetstroom voor wat erachter zit, of op een bedrag voor die maand.</p>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
