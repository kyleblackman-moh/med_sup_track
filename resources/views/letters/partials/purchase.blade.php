{{-- Ad Hoc and Tender Excess: stock, RFQ, recommendation, price comparison and lead time. --}}
@if ($letter['stock'])
    @if ($letter['stock']['sentences'] !== [])
        <p>{{ implode(' ', $letter['stock']['sentences']) }}</p>
    @endif
    @if ($letter['stock']['expiries'] !== [])
        <table class="grid" style="width: 60%; page-break-inside: avoid;">
            <thead>
                <tr>
                    @if ($letter['stock']['with_quantity'])<th>Quantity</th>@endif
                    <th>Expiry Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($letter['stock']['expiries'] as $row)
                    <tr>
                        @if ($letter['stock']['with_quantity'])<td>{{ $row['quantity'] }}</td>@endif
                        <td>{{ $row['expiry'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endif

@foreach ($letter['rfq'] as $paragraph)
    <p>{{ $paragraph }}</p>
@endforeach
