{{-- Ad Hoc and Tender Excess: the Quotation Comparison Sheet, on its own page. --}}
<div class="annex">
    <h2>Quotation Comparison Sheet</h2>
    <table class="grid small">
        <thead>
            <tr>
                <th>Supplier</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th><th>VAT</th><th>Total Price</th><th>Ex Stock</th>
                <th>Lead Time</th><th>Brand</th><th>Manufacturer</th><th>Country of Origin</th><th>Remarks</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($letter['annex'] as $row)
                <tr>
                    @foreach (['supplier', 'quantity', 'unit_price', 'subtotal', 'vat', 'total', 'ex_stock', 'lead_time', 'brand', 'manufacturer', 'country', 'remarks', 'status'] as $key)
                        <td>{{ $row[$key] }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
