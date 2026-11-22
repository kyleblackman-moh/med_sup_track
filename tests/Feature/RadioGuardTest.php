<?php

// RADIO-1 and NEXT-DRAWER-1: static guards (same style as ThemeGuardTest).
// 1. Every radio group is the shared RadioGroup / RadioOption (components/ui/radio-group.tsx):
//    options stacked, never side by side. The one other radio is the quotation row's
//    Recommended (one per row, chosen across the rows), which uses the same radio-sm.
// 2. The staff case page keeps its next step in a right-side drawer, opened from the
//    Next Step button in the header.

it('renders radios only through the shared radio component', function () {
    // quotation-section.tsx passes type="radio" to the row's EntryInlineCheck (entry-fields.tsx).
    $allowed = ['radio-group.tsx', 'entry-fields.tsx', 'quotation-section.tsx'];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    $hits = [];
    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true) || in_array($file->getFilename(), $allowed, true)) {
            continue;
        }
        if (str_contains(file_get_contents($file->getPathname()), 'type="radio"')) {
            $hits[] = $file->getFilename();
        }
    }

    expect($hits)->toBe([]);
});

it('stacks the options of a radio group vertically, one size of radio', function () {
    $source = file_get_contents(resource_path('js/components/ui/radio-group.tsx'));

    expect($source)->toContain("'flex min-w-0 flex-col gap-2'")
        ->and($source)->toContain('className="radio radio-sm mt-px shrink-0"')
        ->and($source)->toContain('has-[:checked]:border-primary has-[:checked]:bg-primary/5')
        ->and($source)->toContain('<fieldset')
        ->and($source)->toContain('<legend')
        ->and($source)->not->toMatch('/\b(?:sm|md|lg|xl|2xl):(?:flex-row|grid-cols-|inline-flex)/');

    expect(file_get_contents(resource_path('js/components/requests/ad-hoc/entry-fields.tsx')))->toContain("'radio radio-sm shrink-0'");
});

it('opens the next step in a right-side drawer from the header button', function () {
    $page = file_get_contents(resource_path('js/pages/cases/show.tsx'));

    expect($page)->toContain('className="drawer drawer-end')
        ->and($page)->toContain('className="drawer-toggle"')
        ->and($page)->toContain('className="drawer-side')
        ->and($page)->toContain('className="drawer-overlay"')
        ->and($page)->toContain('aria-label="Close your next step"')
        ->and($page)->toContain('Your Next Step')
        ->and($page)->toContain('Next Step')
        ->and($page)->toContain('aura aura-glow')
        ->and($page)->toContain('<NextStepCard')
        ->and($page)->toContain('<CaseActions')
        // The old right-hand action column is gone: the record uses the full width.
        ->and($page)->not->toContain('<aside')
        ->and($page)->not->toContain('xl:grid-cols-[minmax(0,1fr)_24rem]');
});
