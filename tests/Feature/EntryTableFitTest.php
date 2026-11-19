<?php

// FORM-ERR-1 (B): an entry table switches to one line of inputs only when its
// card is wide enough for every column at its minimum width, so inputs never
// overlap: below that width each entry wraps into labelled fields. Static
// checks: the arithmetic per table, and the cell that cannot grow past its column.

/** Minimum (or fixed) width of each EntryWidth in px: ENTRY_WIDTH in entry-table.tsx. */
const ENTRY_WIDTH_PX = [
    'check' => 80, 'check-wide' => 96, 'value' => 96, 'number' => 96,
    'text' => 112, 'select' => 128, 'date' => 144, 'long' => 160,
];

/** The one-line width each table needs, in px, read from its source. */
function oneLineWidth(string $source): int
{
    preg_match('/const columns(?:: EntryColumn\[\])? = \[(.*?)\n    \];/s', $source, $block);
    preg_match_all('/\{ key: [^}]*\}/', $block[1], $columns);

    $sum = 0;
    foreach ($columns[0] as $column) {
        $width = preg_match("/width: '([\\w-]+)'/", $column, $m) ? $m[1] : 'text';
        $sum += ENTRY_WIDTH_PX[$width];
    }

    $rowLabel = str_contains($source, 'narrowRowLabel') ? 32 : 128;
    $items = count($columns[0]) + 2; // + the row label and the remove button

    return $rowLabel + $sum + 36 + 12 * ($items - 1) + 32 + 2; // remove 36, 12px gaps, 16px padding each side, 1px borders
}

it('switches each table to one line only where every column fits at its minimum', function (string $file, int $expectedRem) {
    $source = file_get_contents(resource_path("js/components/requests/ad-hoc/{$file}"));

    preg_match('/breakpoint="(\d+)rem"/', $source, $m);
    $breakpointPx = (int) $m[1] * 16;

    expect((int) $m[1])->toBe($expectedRem)
        ->and(oneLineWidth($source))->toBeLessThanOrEqual($breakpointPx, "{$file}: needs ".oneLineWidth($source)."px, breaks at {$breakpointPx}px");
})->with([
    'quotations' => ['quotation-section.tsx', 88],
    'awarded suppliers' => ['awarded-supplier-section.tsx', 66],
    'orders' => ['existing-arrangement-section.tsx', 66],
    'stock batches' => ['stock-section.tsx', 32],
    'variations' => ['variation-section.tsx', 45],
]);

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
