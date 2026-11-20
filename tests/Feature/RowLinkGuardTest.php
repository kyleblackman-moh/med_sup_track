<?php

// LIST-1: a whole-row click is the row's real link stretched over the row
// (rowLink, an ::after overlay), never a navigation onClick on a row, cell or div.

const ROW_LINK_TABLES = [
    'js/pages/requests/index.tsx',
    'js/components/cases/case-table.tsx',
    'js/components/dashboard/work-queue.tsx',
    'js/components/admin/user-table.tsx', // ADMIN-1
];

it('has no navigation onClick on table rows, cells or divs in the list tables', function () {
    foreach (ROW_LINK_TABLES as $path) {
        $source = file_get_contents(resource_path($path));

        // An onClick attribute anywhere inside the opening tag (up to the next tag).
        expect($source)->not->toMatch('/<(tr|td|div|TableRow|TableCell)\b[^<]*?\bonClick=/s', "{$path} has an onClick on a row, cell or div")
            ->and(str_contains($source, 'router.visit('))->toBeFalse("{$path} navigates from script")
            ->and(str_contains($source, 'rowLink'))->toBeTrue("{$path} does not stretch its row link");
    }
});

it('positions each list row for the stretched link', function () {
    // OVERNIGHT-3 (B8): a shared DataTableRow with `linked` is relative (checked below).
    foreach (ROW_LINK_TABLES as $path) {
        expect(file_get_contents(resource_path($path)))->toMatch('/<(tr|TableRow)\b[^<]*?className="[^"]*\brelative\b|<DataTableRow\b[^<]*?\blinked\b/s', "{$path} row is not relative");
    }

    expect(file_get_contents(resource_path('js/components/ui/data-table.tsx')))
        ->toContain("linked && 'hover:bg-selected focus-within:bg-selected relative'");
});
