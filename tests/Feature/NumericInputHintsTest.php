<?php

// FORM-ERR-1 (E): number-like inputs on the request form carry inputmode
// "decimal" (or "numeric" for whole numbers) as a keyboard hint only. Never
// type="number", and no client-side check stands in for the server, which
// validates and owns the message. Static checks on the source.

const AD_HOC_DIR = 'js/components/requests/ad-hoc/';

it('gives every number-like request field a numeric keyboard hint', function (string $file, string $pattern) {
    expect(file_get_contents(resource_path(AD_HOC_DIR.$file)))->toMatch($pattern);
})->with([
    'Quantity Requested' => ['item-tender-section.tsx', "/field\\('quantity_requested'\\)\\}[^>]*inputMode=\"decimal\"/s"],
    'Average Monthly Consumption' => ['stock-section.tsx', "/field\\('average_monthly_consumption'\\)\\}[^>]*inputMode=\"decimal\"/s"],
    'Quantity on Order' => ['stock-section.tsx', "/field\\('quantity_on_order'\\)\\}[^>]*inputMode=\"decimal\"/s"],
    'Quantity to Purchase' => ['quotation-section.tsx', "/requisition\\('quantity_to_purchase'\\)[^>]*inputMode=\"decimal\"/s"],
    'Tender Price' => ['price-section.tsx', "/requisition\\('tender_price'\\)[^>]*inputMode=\"decimal\"/s"],
    'Previous Price' => ['price-section.tsx', "/requisition\\('previous_price'\\)[^>]*inputMode=\"decimal\"/s"],
    'Quotation Quantity' => ['quotation-section.tsx', "/input\\('quantity_offered', 'decimal'\\)/"],
    'Quotation Unit Price' => ['quotation-section.tsx', "/input\\('unit_price', 'decimal'\\)/"],
    'Quotation Lead Time (Days)' => ['quotation-section.tsx', "/input\\('lead_time_days', 'integer'\\)/"],
    'Quantity Awarded' => ['awarded-supplier-section.tsx', "/input\\('quantity_awarded', 'decimal'\\)/"],
    'Unit Cost' => ['awarded-supplier-section.tsx', "/input\\('unit_cost', 'decimal'\\)/"],
    'Quantity Ordered' => ['existing-arrangement-section.tsx', "/input\\('quantity_ordered', 'decimal'\\)/"],
    'Quantity Delivered' => ['existing-arrangement-section.tsx', "/input\\('quantity_delivered', 'decimal'\\)/"],
    'Batch Quantity' => ['stock-section.tsx', "/kind=\\{column\\.key === 'expiry_date' \\? 'date' : 'decimal'\\}/"],
]);

it('maps the cell kinds to inputmode, as a hint only', function () {
    $cells = file_get_contents(resource_path(AD_HOC_DIR.'entry-fields.tsx'));

    expect($cells)->toContain("inputMode={kind === 'decimal' ? 'decimal' : kind === 'integer' ? 'numeric' : undefined}")
        ->and($cells)->toContain("type={kind === 'date' ? 'date' : 'text'}");
});

it('never uses type="number" or a client-side pattern on the request form', function () {
    foreach (glob(resource_path(AD_HOC_DIR.'*.tsx')) as $path) {
        $source = file_get_contents($path);

        expect($source)->not->toMatch('/type=["\']number["\']/', basename($path))
            ->and($source)->not->toMatch('/\bpattern=/', basename($path));
    }
});
