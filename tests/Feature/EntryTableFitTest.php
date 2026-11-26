<?php

// FORM-ERR-1 (B): an entry table switches to one line of inputs only when its
// card is wide enough for every column at its minimum width, so inputs never
// overlap: below that width each entry wraps into labelled fields. Static
// checks: the arithmetic per table, and the cell that cannot grow past its column.
// FORM-COMPACT-1 (2): the quotation table is a card per entry in two lines;
// EACH line must fit at its field minimums.

/** Minimum (or fixed) width of each EntryWidth in px: ENTRY_WIDTH in components/ui/field-width.ts (FORM-COMPACT-1: the one width scale). */
const ENTRY_WIDTH_PX = [
    'xs' => 96, 'sm' => 144, 'md' => 112, 'lg' => 160,
    'checks' => 352, 'value' => 96,
    'num' => 64, 'date' => 120, // Kyle, 2026-11-25: fixed short numbers and dates; 2026-11-26 narrower
];

/** Gaps, padding and borders: 12px between cells (8px on the 65rem layout), 16px row padding each side, 1px table borders. */
function lineWidth(array $columns, int $extraItems = 0, int $extraPx = 0, int $gap = 12): int
{
    $sum = 0;
    foreach ($columns as $column) {
        $width = preg_match("/width: '([\\w-]+)'/", $column, $m) ? $m[1] : 'md';
        $sum += ENTRY_WIDTH_PX[$width];
    }
    $items = count($columns) + $extraItems;

    return $sum + $extraPx + $gap * ($items - 1) + 32 + 2;
}

/** The columns of a `const <name> = [...]` block in the source. @return list<string> */
function columnsOf(string $source, string $name): array
{
    preg_match('/const '.$name.'(?:: EntryColumn\[\])? = \[(.*?)\n\s*\];/s', $source, $block);
    preg_match_all('/\{ key: [^}]*\}/', $block[1] ?? '', $columns);

    return $columns[0];
}

/** The one-line width a table needs, in px, read from its source (a card table: its widest line). */
function oneLineWidth(string $source): int
{
    if (str_contains($source, 'variant="cards"')) {
        // A card has its label and remove button on a line of their own: each line holds fields only.
        return max(lineWidth(columnsOf($source, 'line1')), lineWidth(columnsOf($source, 'line2')));
    }

    $rowLabel = str_contains($source, 'narrowRowLabel') ? 32 : 128;

    $gap = str_contains($source, 'breakpoint="65rem"') ? 8 : 12;
    $edge = preg_match('/<EntryRow[^>]*\baccent\b/s', $source) ? 4 : 0; // the 4px coloured left edge (Kyle, 2026-11-26)

    return lineWidth(columnsOf($source, 'columns'), 2, $rowLabel + 36 + $edge, $gap); // + the row label and the 36px remove button
}

it('switches each table to one line only where every column fits at its minimum', function (string $file, int $expectedRem) {
    $source = file_get_contents(resource_path("js/components/requests/ad-hoc/{$file}"));

    preg_match('/breakpoint="(\d+)rem"/', $source, $m);
    $breakpointPx = (int) $m[1] * 16;

    expect((int) $m[1])->toBe($expectedRem)
        ->and(oneLineWidth($source))->toBeLessThanOrEqual($breakpointPx, "{$file}: needs ".oneLineWidth($source)."px, breaks at {$breakpointPx}px");
})->with([
    // Kyle, 2026-11-26: each on one line from a 65rem card (8px gaps).
    'awarded suppliers' => ['awarded-supplier-section.tsx', 65],
    'orders' => ['existing-arrangement-section.tsx', 65],
    'stock items' => ['stock-section.tsx', 45], // Kyle, 2026-11-26: Drug Name, Expiry Date, Note
    'variations' => ['variation-section.tsx', 45],
]);

it('lays each quotation out as a supplier block of two table lines over its totals in the agreed order', function () {
    $source = file_get_contents(resource_path('js/components/requests/ad-hoc/quotation-section.tsx'));
    preg_match('/const SHEET_LINES[^=]*= \[(.*?)\n\];/s', $source, $block);
    preg_match_all('/\[\s*(\{ key:.*?)\s*\],?/s', $block[1] ?? '', $lines);
    $keys = array_map(fn (string $line) => preg_match_all("/key: '(\\w+)'/", $line, $m) ? $m[1] : [], $lines[1]);

    // Backlog v0.4 (2026-11-25): the supplier in the block's header; Subtotal, VAT and Total Price
    // Incl. VAT are the block's totals under the table, read-only, worked out live by lib/quotation-price.ts.
    // Kyle, 2026-11-25: one line of seven (narrow fixed number columns, Lead Time the narrowest;
    // Brand, Manufacturer, Country of Origin share the rest), then Remarks across the whole line.
    expect($keys)->toBe([
        ['quantity_offered', 'unit_price', 'quantity_available_now', 'lead_time_days', 'brand', 'manufacturer', 'country_of_origin'],
        ['remarks'],
    ])
        ->and($source)->toContain("[{ key: 'remarks', label: 'Remarks', span: 7 }],")
        ->and($source)->toContain("const SHEET_COLUMNS = ['7rem', '8rem', '7rem', '6rem', undefined, undefined, undefined];")
        ->and($source)->toContain('columns={SHEET_COLUMNS}')
        ->and($source)->not->toContain("key: 'total_price'")
        ->and($source)->toMatch('/below=\{\s*<>\s*<QuotationTotals label=\{rowLabel\} price=\{price\} \/>/')
        ->and(file_get_contents(resource_path('js/components/ui/quotation-totals.tsx')))->toContain('<dt className="border-base-300 border-t pt-1 font-semibold">Total Price Incl. VAT</dt>')
        ->and($source)->toContain("import { money, quotationPrice } from '@/lib/quotation-price';")
        // The Quoted Price figure is gone from the price comparison (the totals show it).
        ->and(file_get_contents(resource_path('js/components/requests/ad-hoc/price-section.tsx')))->not->toContain('Quoted Price (Recommended Quotation)')
        // Ex Stock is a number, not a checkbox.
        ->and($source)->toContain("input('quantity_available_now', 'decimal')")
        ->and($source)->toContain("input('remarks', 'text', 255)")
        ->and($source)->not->toContain("'ex_stock'")
        // The block's header: Supplier, then the one Recommended radio (Kyle, 2026-11-26: no Considered tick).
        ->and($source)->toMatch('/htmlFor=\{id\(\'supplier_name\'\)\}.*?<EntryInlineCheck\s+id=\{id\(\'is_recommended\'\)\}.*?type="radio"\s+name="recommended-quotation"/s')
        ->and($source)->not->toContain("id('considered')")
        // The block is the shared EntrySheet: a collapsible block (details) holding a real bordered table
        // of 15 tracks (table-fixed), each field its span of them, scrolling inside the block.
        ->and($source)->toContain('<EntrySheet')
        ->and($sheet = file_get_contents(resource_path('js/components/ui/entry-sheet.tsx')))->toContain('const TRACKS = 15;')
        ->and($sheet)->toContain('<details')
        ->and($sheet)->toContain('collapse-arrow')
        ->and($sheet)->toContain('<summary className="collapse-title')
        ->and($sheet)->toContain('max-w-full overflow-x-auto')
        ->and($sheet)->toMatch('/<table className="[^"]*\\btable-fixed\\b/')
        ->and($sheet)->toContain('scope="col"')
        ->and($sheet)->toContain('colSpan={field.span ?? share}')
        ->and($sheet)->toContain('<col key={column} style={width ? { width } : undefined} />')
        // Kyle, 2026-11-26: the Reason for Justification renders only for the recommended quotation;
        // every quotation is saved as considered.
        ->and($source)->toContain('onChange={() => api.chooseRecommended(row.key)}')
        ->and($source)->toMatch('/recommended && \(\s*<TextAreaField/')
        ->and($source)->toContain('label="Reason for Justification"')
        ->and(file_get_contents(resource_path('js/components/requests/ad-hoc/form-state.ts')))->toMatch("/if \(section === 'quotations'\) \{\s*values\.considered = true;/")
        ->and($source)->toContain('label="Add Quotation"');
});

it('sizes tables by their card (container query), not the viewport', function () {
    $table = file_get_contents(resource_path('js/components/ui/entry-table.tsx'));

    expect($table)->toContain('@container')
        // A cell's one track can shrink to the cell: nothing inside can push it over its neighbour.
        ->and($table)->toContain("'grid grid-cols-[minmax(0,1fr)] gap-2'")
        ->and($table)->not->toMatch('/\b(lg|xl|2xl):flex\b/');

    foreach (['quotation-section.tsx', 'awarded-supplier-section.tsx', 'existing-arrangement-section.tsx', 'stock-section.tsx', 'variation-section.tsx'] as $file) {
        $source = file_get_contents(resource_path("js/components/requests/ad-hoc/{$file}"));
        expect($source)->not->toMatch('/\b(lg|xl|2xl):(w-|flex-)/', "{$file} still sizes a column by the viewport");
    }
});

it('mirrors the width scale of field-width.ts', function () {
    $source = file_get_contents(resource_path('js/components/ui/field-width.ts'));
    preg_match('/export const ENTRY_WIDTH[^{]*\{(.*?)\n\};/s', $source, $block);
    // The minimum, or the fixed basis of a cell that has no minimum.
    preg_match_all("/'([\\w-]+)': '[^']*?\\b(?:min-w|basis)-(\\d+)\\b/", $block[1], $m, PREG_SET_ORDER);

    $px = [];
    foreach ($m as [, $key, $units]) {
        $px[$key] = (int) $units * 4; // Tailwind 4: one spacing unit is 0.25rem (4px)
    }
    ksort($px);
    $expected = ENTRY_WIDTH_PX;
    ksort($expected);

    expect($px)->toBe($expected)
        // A minimum never exceeds the wrapped grid's 10rem (160px) columns, so a wrapped entry never overflows.
        ->and(max(array_map(fn ($v) => (int) $v * 4, preg_match_all('/\bmin-w-(\d+)\b/', $block[1], $mins) ? $mins[1] : [0])))->toBeLessThanOrEqual(160);
});

it('gives the awarded suppliers TOTAL and TOTAL AWARD in capitals, and a prompt when the % Awarded is not 100', function () {
    $source = file_get_contents(resource_path('js/components/requests/ad-hoc/awarded-supplier-section.tsx'));

    // Kyle, 2026-11-25: Line Cost is Total Cost; the foot is bold capitals with colons.
    expect($source)->toContain("{ key: 'line_cost', label: 'Total Cost', width: 'value' }")
        ->and($source)->toContain('<dl className="flex flex-wrap justify-end gap-x-6 gap-y-1 font-bold uppercase">')
        ->and($source)->toContain('<dt>Total:</dt>')
        ->and($source)->toContain('<dt>Total Award:</dt>')
        ->and($source)->toContain('const awarded = sumPercent(rows.map((row) => text(row.values.percentage_awarded)));')
        ->and($source)->toMatch("/\\{awarded !== null && awarded !== '100\\.00' && \\(\\s*<div role=\"status\" className=\"alert alert-warning alert-soft/");
});

it('turns the delivery status into a text box for Other, and clears it for any other status', function () {
    $source = file_get_contents(resource_path('js/components/requests/ad-hoc/existing-arrangement-section.tsx'));

    expect($source)->toContain("{ key: 'delivery_note', label: 'Delivery Note No.', width: 'xs' }")
        ->and($source)->toContain("{ key: 'po_number', label: 'P.O #', width: 'num' }")
        ->and($source)->toContain("{ key: 'quantity_ordered', label: 'Qty Ordered', width: 'num' }")
        ->and($source)->toContain("{ key: 'ordered_on', label: 'Date Ordered', width: 'date' }")
        ->and($source)->toContain("{ key: 'quantity_delivered', label: 'Qty Delivered', width: 'num' }")
        ->and($source)->toContain("const isOther = deliveryState === 'OTHER';")
        // Kyle, 2026-11-26: in place of the list, not a second field under it; its X goes back to the list.
        ->and($source)->toMatch('/\\{isOther \\? \\(\\s*<label[^>]*>\\s*<input\\s+id=\\{otherId\\}.*?\\) : \\(\\s*<select/s')
        ->and($source)->toContain('Back to the status list')
        ->and($source)->toContain("if (e.target.value !== 'OTHER' && text(row.values.delivery_status) !== '')");
});
