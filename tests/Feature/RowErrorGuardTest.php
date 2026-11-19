<?php

// FORM-ERR-1 (A, G): a table row's errors are shown ONLY in the shared
// full-width list under the row (RowErrorList, through EntryRow's errors),
// never inside a narrow cell. Static checks on the source.

/** Every TSX file that renders entry-table rows. */
function entryRowFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx') && str_contains(file_get_contents($file->getPathname()), '<EntryRow')) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    return $files;
}

it('finds every table of the request form', function () {
    $names = array_map('basename', entryRowFiles());

    expect($names)->toContain(
        'quotation-section.tsx',
        'awarded-supplier-section.tsx',
        'existing-arrangement-section.tsx',
        'stock-section.tsx',
        'variation-section.tsx',
    );
});

it('passes every row its errors and the id of its error list', function () {
    foreach (entryRowFiles() as $path) {
        $source = file_get_contents($path);

        expect(str_contains($source, 'errors={'))->toBeTrue("{$path}: EntryRow without errors")
            ->and(str_contains($source, 'errorsId={'))->toBeTrue("{$path}: EntryRow without errorsId")
            ->and(str_contains($source, 'collectRowErrors('))->toBeTrue("{$path}: row errors not collected");
    }
});

it('never renders an error message inside a table cell', function () {
    foreach (entryRowFiles() as $path) {
        $source = file_get_contents($path);

        foreach (['CellError', '<FieldError', '<InputError'] as $renderer) {
            expect(str_contains($source, $renderer))->toBeFalse("{$path} renders a message with {$renderer}");
        }

        // Every control fed a cell error points at the row's list instead of drawing its own message.
        if (str_contains($source, 'cellError(')) {
            expect(str_contains($source, 'errorListId'))->toBeTrue("{$path}: a cell error without errorListId");
        }
    }
});

it('keeps the cell components free of message rendering', function () {
    $cells = file_get_contents(resource_path('js/components/requests/ad-hoc/entry-fields.tsx'));
    $table = file_get_contents(resource_path('js/components/ui/entry-table.tsx'));

    expect($cells)->not->toContain('CellError')
        ->and($cells)->not->toContain('FieldError')
        ->and($cells)->not->toMatch('/<p\b/')
        // The one renderer, used by EntryRow under the row's line of cells.
        ->and($table)->toContain('export function RowErrorList')
        ->and($table)->toMatch('/<RowErrorList id=\{errorsId\} errors=\{errors\} \/>/');
});
