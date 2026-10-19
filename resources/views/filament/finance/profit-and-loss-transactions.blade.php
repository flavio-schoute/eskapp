{{-- The transactions behind one P&L line for the whole period, shown under the line when it is clicked. --}}
@php($rows = $lineTransactions[$categoryId] ?? null)
@if ($rows !== null)
    <tr wire:key="pl-transactions-{{ $categoryId }}" @isset($groupId) x-show="open[{{ $groupId }}]" @endisset style="border-bottom: 1px solid rgb(127 127 127 / 0.12); background: rgb(127 127 127 / 0.04);">
        <td colspan="{{ count($months) + 2 + ($previous ? 1 : 0) }}" style="padding: 0.5rem 0.5rem 0.625rem 2.25rem;">
            @if ($rows->isEmpty())
                <span style="font-size: 0.8rem; opacity: 0.6;">Geen transacties in deze periode.</span>
            @else
                <table style="width: 100%; font-size: 0.8rem;">
                    @foreach ($rows as $row)
                        <tr>
                            <td style="padding: 0.125rem 1rem 0.125rem 0; width: 6.5rem; white-space: nowrap; font-family: monospace; opacity: 0.6;">{{ $row['date'] }}</td>
                            <td style="padding: 0.125rem 1rem 0.125rem 0; white-space: nowrap;">
                                {{ $row['name'] }}
                                @if ($row['split'])<span style="opacity: 0.6;">(deel)</span>@endif
                                @if ($row['auto'])<span style="color: #2563EB;">· voorgesteld</span>@endif
                            </td>
                            <td style="padding: 0.125rem 1rem 0.125rem 0; opacity: 0.6; max-width: 28rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['description'] }}@if ($row['note']) · 📝 {{ $row['note'] }}@endif</td>
                            <td style="padding: 0.125rem 0; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums;">{{ \App\Services\Finance\Money::format($row['cents'] * $sign) }}</td>
                        </tr>
                    @endforeach
                    @if ($rows->count() > 1)
                        <tr style="border-top: 1px solid rgb(127 127 127 / 0.15);">
                            <td></td>
                            <td colspan="2" style="padding-top: 0.25rem; font-weight: 600;">Totaal ({{ $rows->count() }})</td>
                            <td style="padding-top: 0.25rem; text-align: right; font-weight: 600; font-variant-numeric: tabular-nums;">{{ \App\Services\Finance\Money::format($rows->sum('cents') * $sign) }}</td>
                        </tr>
                    @endif
                </table>
            @endif
        </td>
    </tr>
@endif
