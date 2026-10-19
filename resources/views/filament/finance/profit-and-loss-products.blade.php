{{-- The products behind one revenue stream for the whole period, shown under the stream when it is clicked. --}}
<tr style="border-bottom: 1px solid rgb(127 127 127 / 0.12); background: rgb(127 127 127 / 0.04);">
    <td colspan="{{ count($months) + 2 + ($previous ? 1 : 0) }}" style="padding: 0.5rem 0.5rem 0.625rem 2.25rem;">
        @if ($products->isEmpty())
            <span style="font-size: 0.8rem; opacity: 0.6;">Geen betaalde orders in deze periode.</span>
        @else
            <table style="width: 100%; font-size: 0.8rem;">
                @foreach ($products as $product)
                    <tr>
                        <td style="padding: 0.125rem 1rem 0.125rem 0; white-space: nowrap;">{{ $product['name'] }}</td>
                        <td style="padding: 0.125rem 1rem 0.125rem 0; width: 6rem; font-family: monospace; opacity: 0.6;">{{ $product['sku'] }}</td>
                        <td style="padding: 0.125rem 1rem 0.125rem 0; width: 7rem; opacity: 0.6; white-space: nowrap;">{{ $product['count'] }} {{ $product['count'] === 1 ? 'orderregel' : 'orderregels' }}</td>
                        <td style="padding: 0.125rem 0; width: 7rem; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums;">{{ \App\Services\Finance\Money::format($product['cents']) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </td>
</tr>
