<?php

// FORM-COMPACT-1 (5): static guards on the vendor form's layout.
// 1. Every field takes its width from the one scale (components/ui/field-width.ts):
//    a `width` prop on every stacked field, and no ad hoc width classes in the sections.
// 2. The Documents cells start an upload on choosing a file: no Upload button, no drop zone.

const FORM_DIR = 'js/components/requests/ad-hoc/';

/** The section files of the request form, plus the panel around them. @return array<string, string> name => source */
function formSectionSources(): array
{
    $files = [...glob(resource_path(FORM_DIR.'*-section.tsx')), resource_path(FORM_DIR.'form-panel.tsx')];

    return collect($files)->mapWithKeys(fn (string $path) => [basename($path) => file_get_contents($path)])->all();
}

it('finds every section of the request form', function () {
    expect(array_keys(formSectionSources()))->toContain(
        'request-type-section.tsx',
        'item-tender-section.tsx',
        'variation-section.tsx',
        'awarded-supplier-section.tsx',
        'existing-arrangement-section.tsx',
        'stock-section.tsx',
        'quotation-section.tsx',
        'price-section.tsx',
        'form-panel.tsx',
    );
});

it('gives every stacked field a width from the scale', function () {
    $scale = ['xs', 'sm', 'md', 'lg', 'full'];
    $found = 0;

    foreach (formSectionSources() as $name => $source) {
        preg_match_all('/<(TextField|TextAreaField|RadioGroupField|CheckboxField|Figure|PreviousRequestQuestion)\b(.*?)\/>/s', $source, $tags, PREG_SET_ORDER);

        foreach ($tags as [$tag, $component, $props]) {
            $found++;
            expect(preg_match('/\bwidth="(\w+)"/', $props, $m))->toBe(1, "{$name}: a <{$component}> without a width")
                ->and($scale)->toContain($m[1]);
        }
    }

    expect($found)->toBeGreaterThan(25);
});

it('uses no width classes of its own in the sections', function () {
    // Not field widths: fill and shrink helpers, a one-column stack, and the Yes/No pills' 80px minimum (a control).
    $allowed = ['w-full', 'min-w-0', 'grid-cols-1', 'min-w-20'];

    foreach (formSectionSources() as $name => $source) {
        preg_match_all('/(?<![\w-])(?:[\w@\[\]-]+:)*((?:grid-cols|col-span|w|min-w|max-w|basis)-[\w\[\]\(\),.\/-]+)/', $source, $m);
        $adHoc = array_values(array_unique(array_diff($m[1], $allowed)));

        expect($adHoc)->toBe([], "{$name}: ".implode(', ', $adHoc));
    }
});

it('defines the scale once, and both layouts read it', function () {
    $scale = file_get_contents(resource_path('js/components/ui/field-width.ts'));

    expect($scale)->toContain("export type FieldWidth = 'xs' | 'sm' | 'md' | 'lg' | 'full';")
        ->and($scale)->toContain('export const FIELD_WIDTH')
        ->and($scale)->toContain('export const ENTRY_WIDTH')
        ->and(file_get_contents(resource_path(FORM_DIR.'fields.tsx')))->toContain("from '@/components/ui/field-width'")
        ->and(file_get_contents(resource_path('js/components/ui/entry-table.tsx')))->toContain("from '@/components/ui/field-width'");

    // Nothing else defines a width map of its own.
    foreach (glob(resource_path('js/components/ui/*.ts*')) as $file) {
        if (basename($file) !== 'field-width.ts') {
            expect(file_get_contents($file))->not->toMatch('/(?:const|let)\s+(?:ENTRY|FIELD)_WIDTH\b/', basename($file));
        }
    }
});

it('has no Upload button and no drop zone in the Documents cells', function () {
    $cells = file_get_contents(resource_path('js/components/requests/supporting-documents.tsx'));
    $uploads = file_get_contents(resource_path('js/components/requests/use-document-uploads.ts'));

    expect(file_exists(resource_path('js/components/requests/upload-drop-zone.tsx')))->toBeFalse()
        ->and($cells)->not->toMatch('/>\s*Upload\s*</')        // a button or link labelled Upload
        ->and($cells)->not->toMatch("/'Upload'|\"Upload\"/")
        ->and($cells)->not->toMatch('/Uploading…|Drag and drop|drop (PDF )?files|onDrop|onDragOver/i')
        ->and($cells)->toContain('Choose File')
        ->and($cells)->toContain('aria-live="polite"')
        // Choosing a file starts the upload.
        ->and($uploads)->toMatch('/const choose = .*?update\(\(items\) => \[\.\.\.items, \.\.\.added\]\);\s*void run\(\);/s');
});

it('shows Ready only on the server\'s clean scan, and progress through Inertia', function () {
    $cells = file_get_contents(resource_path('js/components/requests/supporting-documents.tsx'));
    $uploads = file_get_contents(resource_path('js/components/requests/use-document-uploads.ts'));
    $progress = file_get_contents(resource_path('js/components/requests/upload-progress.tsx'));

    expect($cells)->toContain("if (status === 'Ready') {")
        ->and($cells)->toContain("Scanning: 'Checking'")
        ->and($uploads)->toContain('onProgress: (event)')
        ->and($uploads)->toContain("route('requests.documents.store', caseId)")
        ->and($progress)->toContain('role="progressbar"')
        ->and($progress)->toContain('aria-valuenow={percent}');

    foreach ([$cells, $uploads, $progress] as $source) {
        expect($source)->not->toMatch('/\bfetch\s*\(|axios|XMLHttpRequest|localStorage|sessionStorage|indexedDB/');
    }
});
