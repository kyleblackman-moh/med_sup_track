{{-- The Quotation Comparison sheet (RequestLetter::annex): header rows, then one row per quotation, the recommended one highlighted. --}}
<h2 class="annex-title">Quotation Comparison</h2>
<table class="annex-header">
    @foreach ($letter['annex']['header'] as [$label, $value])
        <tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>
    @endforeach
</table>
<table class="grid small">
    <thead>
        <tr>
            @foreach (['No.', 'Supplier', 'Qty', 'Unit Price', 'Subtotal', 'VAT', 'Total Price', 'Ex Stock', 'Lead Time', 'Brand', 'Manufacturer', 'Country of Origin', 'Remarks'] as $heading)
                <th>{{ $heading }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($letter['annex']['rows'] as $row)
            <tr class="{{ $row['recommended'] ? 'recommended' : 'quoted' }}">
                @foreach ($row['cells'] as $cell)<td>{{ $cell }}</td>@endforeach
            </tr>
        @endforeach
    </tbody>
</table>
