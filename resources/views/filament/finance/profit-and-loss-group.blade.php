{{-- One cost group in the P&L matrix with its lines, collapsed until clicked (state in the table's x-data). --}}
    <tr style="border-bottom: 1px solid rgb(127 127 127 / 0.12); cursor: pointer;" x-on:click="open[{{ $group['id'] }}] = ! open[{{ $group['id'] }}]">
        <td style="{{ $nameStyle }} font-weight: 600;">
            <span x-text="open[{{ $group['id'] }}] ? '▾' : '▸'" style="display: inline-block; width: 1rem; opacity: 0.6;"></span>{{ $group['name'] }}
        </td>
        @foreach ($months as $month)
            <td style="{{ $cellStyle }}">{{ $cell($group['byMonth'], [$month]) }}</td>
        @endforeach
        <td style="{{ $cellStyle }} font-weight: 500;">{{ $cell($group['byMonth'], $months) }}</td>
        @if ($previous)
            <td style="{{ $cellStyle }} opacity: 0.7;">{{ $cell($group['byMonth'], $previous) }}</td>
        @endif
    </tr>
    @foreach ($group['lines'] as $line)
        <tr x-show="open[{{ $group['id'] }}]" x-cloak wire:click="toggleLine({{ $line['id'] }})" title="Klik voor de transacties" style="border-bottom: 1px solid rgb(127 127 127 / 0.08); font-size: 0.85rem; cursor: pointer; {{ in_array($line['id'], $this->expandedLines, true) ? 'background: rgb(127 127 127 / 0.06);' : '' }}">
            <td style="{{ $nameStyle }} padding-inline-start: 2.25rem; opacity: 0.9;">
                {{ $line['name'] }}@if ($line['code'])<span style="opacity: 0.5; font-family: monospace; margin-inline-start: 0.375rem;">{{ $line['code'] }}</span>@endif
            </td>
            @foreach ($months as $month)
                <td style="{{ $cellStyle }}">
                    @if (($line['byMonth'][$month] ?? 0) !== 0)
                        <button type="button" wire:click.stop="mountAction('drilldown', { category: {{ $line['id'] }}, from: '{{ $month }}', to: '{{ $month }}' })" style="text-decoration: underline dotted; text-underline-offset: 3px;">{{ $cell($line['byMonth'], [$month]) }}</button>
                    @else
                        –
                    @endif
                </td>
            @endforeach
            <td style="{{ $cellStyle }}">
                <button type="button" wire:click.stop="mountAction('drilldown', { category: {{ $line['id'] }}, from: '{{ $first }}', to: '{{ $last }}' })" style="text-decoration: underline dotted; text-underline-offset: 3px;">{{ $cell($line['byMonth'], $months) }}</button>
            </td>
            @if ($previous)
                <td style="{{ $cellStyle }} opacity: 0.7;">{{ $cell($line['byMonth'], $previous) }}</td>
            @endif
        </tr>
        @include('filament.finance.profit-and-loss-transactions', ['categoryId' => $line['id'], 'sign' => 1, 'groupId' => $group['id']])
    @endforeach
