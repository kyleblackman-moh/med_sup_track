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
];

/** Gaps, padding and borders: 12px between cells, 16px row padding each side, 1px table borders. */
function lineWidth(array $columns, int $extraItems = 0, int $extraPx = 0): int
{
    $sum = 0;
    foreach ($columns as $column) {
        $width = preg_match("/width: '([\\w-]+)'/", $column, $m) ? $m[1] : 'md';
        $sum += ENTRY_WIDTH_PX[$width];
    }
    $items = count($columns) + $extraItems;

    return $sum + $extraPx + 12 * ($items - 1) + 32 + 2;
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

    return lineWidth(columnsOf($source, 'columns'), 2, $rowLabel + 36); // + the row label and the 36px remove button
}

it('switches each table to one line only where every column fits at its minimum', function (string $file, int $expectedRem) {
    $source = file_get_contents(resource_path("js/components/requests/ad-hoc/{$file}"));

    preg_match('/breakpoint="(\d+)rem"/', $source, $m);
    $breakpointPx = (int) $m[1] * 16;

    expect((int) $m[1])->toBe($expectedRem)
        ->and(oneLineWidth($source))->toBeLessThanOrEqual($breakpointPx, "{$file}: needs ".oneLineWidth($source)."px, breaks at {$breakpointPx}px");
})->with([
    'awarded suppliers' => ['awarded-supplier-section.tsx', 66],
    'orders' => ['existing-arrangement-section.tsx', 66],
    'stock batches' => ['stock-section.tsx', 32],
    'variations' => ['variation-section.tsx', 45],
]);

it('lays each quotation out as a supplier block of three table lines in the agreed order', function () {
    $source = file_get_contents(resource_path('js/components/requests/ad-hoc/quotation-section.tsx'));
    preg_match('/const SHEET_LINES[^=]*= \[(.*?)\n\];/s', $source, $block);
    preg_match_all('/\[\s*(\{ key:.*?)\s*\],?/s', $block[1] ?? '', $lines);
    $keys = array_map(fn (string $line) => preg_match_all("/key: '(\\w+)'/", $line, $m) ? $m[1] : [], $lines[1]);

    // Kyle, 2026-11-24: the supplier in the block's header, then three lines.
    expect($keys)->toBe([
        ['quantity_offered', 'unit_price', 'subtotal', 'vat', 'total_price'],
        ['quantity_available_now', 'lead_time_days', 'brand'],
        ['manufacturer', 'country_of_origin', 'remarks'],
    ])
        ->and($source)->toContain("{ key: 'quantity_available_now', label: 'Ex Stock' }")
        // Ex Stock is a number, not a checkbox.
        ->and($source)->toContain("input('quantity_available_now', 'decimal')")
        ->and($source)->not->toContain("'ex_stock'")
        // The block's header: Supplier, then Considered and the one Recommended radio.
        ->and($source)->toMatch('/htmlFor=\{id\(\'supplier_name\'\)\}.*?<EntryInlineCheck\s+id=\{id\(\'considered\'\)\}.*?id=\{id\(\'is_recommended\'\)\}.*?type="radio"\s+name="recommended-quotation"/s')
        // The block is the shared EntrySheet: a line of five shares 15 tracks 3 each, a line of three 5 each, once 40rem wide.
        ->and($source)->toContain('<EntrySheet')
        ->and(file_get_contents(resource_path('js/components/ui/entry-sheet.tsx')))->toContain("'bg-base-300 grid grid-cols-2 gap-px @min-[40rem]:grid-cols-15'")
        // The reason renders only for a quotation that is not considered.
        ->and($source)->toMatch('/!considered && \(\s*<TextAreaField/')
        ->and($source)->toContain('label="Reason Quotation Wasn\'t Considered"')
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
