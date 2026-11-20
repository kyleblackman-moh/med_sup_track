<?php

// FORM-COMPACT-1 (4), WCAG 2.2 2.4.11 Focus Not Obscured: on the request form
// a focused field, an anchor target (progress strip, error summary links) and
// scrollIntoView never land under the sticky progress strip or the sticky
// action bar. Static checks on the source; the arithmetic is in app.css.

function clearanceCss(): string
{
    return file_get_contents(resource_path('css/app.css'));
}

it('pads the page scroll for the sticky strip and the sticky bar', function () {
    $css = clearanceCss();

    // Strip at most ~134px (three label lines) + 16px; bar 90px from the bottom edge + 22px; 208px below 40rem.
    expect($css)->toMatch('/html:has\(\[data-sticky-strip\]\)\s*\{\s*scroll-padding-top:\s*9\.5rem;/')
        ->and($css)->toMatch('/html:has\(\[data-sticky-bar\]\)\s*\{\s*scroll-padding-bottom:\s*7rem;/')
        ->and($css)->toMatch('/@media \(width < 40rem\)\s*\{\s*html:has\(\[data-sticky-bar\]\)\s*\{\s*scroll-padding-bottom:\s*13rem;/');
});

it('marks the sticky strip and the sticky bar the padding is for', function () {
    $page = file_get_contents(resource_path('js/pages/requests/ad-hoc/edit.tsx'));
    $bar = file_get_contents(resource_path('js/components/ui/sticky-action-bar.tsx'));

    expect($page)->toMatch('/<div data-sticky-strip className="[^"]*\bsticky top-0\b/')
        ->and($bar)->toMatch('/data-sticky-bar\s+className=\{cn\(\s*\'[^\']*\bsticky bottom-3\b/');
});

it('keeps the bar in the page flow, so at the end it covers nothing', function () {
    $bar = file_get_contents(resource_path('js/components/ui/sticky-action-bar.tsx'));
    $page = file_get_contents(resource_path('js/pages/requests/ad-hoc/edit.tsx'));

    // sticky, not fixed: its own box keeps its height (78px) after the last section.
    expect($bar)->not->toMatch('/\bfixed\b/')
        ->and(strrpos($page, '<StickyActionBar'))->toBeGreaterThan(strrpos($page, '<FormPanel'));
});

it('does not add a second offset to the sections (it would double the strip clearance)', function () {
    expect(file_get_contents(resource_path('js/components/ui/section-panel.tsx')))->not->toMatch('/\bscroll-mt-/');
});

it('keeps one primary action in the bar and no "Continue to Next Step"', function () {
    $page = file_get_contents(resource_path('js/pages/requests/ad-hoc/edit.tsx'));

    expect($page)->toContain('variant="default"')
        ->and(substr_count($page, 'variant="outline"'))->toBe(2) // Delete Draft and Save Draft
        ->and($page)->not->toContain('Continue to Next Step');
});
