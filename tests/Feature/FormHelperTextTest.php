<?php

// V2-5 step 2: the request form's helper text under Item and Tender Details
// (static guard, like ThemeGuardTest). Helpers are 13px muted text (fields.tsx).

function itemTenderSource(): string
{
    return file_get_contents(resource_path('js/components/requests/ad-hoc/item-tender-section.tsx'));
}

it('describes the unit of measure under its field', function () {
    expect(itemTenderSource())
        ->toMatch('/label="Unit of Measure"\s+maxLength=\{32\}\s+hint="The unit the quantity is counted in, for example: each, box of 100, vial\."/');
});

it('keeps the other item helpers', function (string $hint) {
    expect(itemTenderSource())->toContain("hint=\"{$hint}\"");
})->with([
    // Kyle, 2026-11-26: decimals allowed (the server takes up to 2 decimal places).
    'quantity requested' => ['Up to 2 decimal places, for example 2800.50.'],
]);

it('gives the DIN no 8-digit hint (Kyle, 2026-11-26)', function () {
    expect(itemTenderSource())->not->toContain('8 digits')->toContain('<TextField {...field(\'din\')} width="sm" label="DIN" maxLength={32} />');
});

it('renders helper text as 13px muted text', function () {
    $fields = file_get_contents(resource_path('js/components/requests/ad-hoc/fields.tsx'));

    // MASTER.md: 13px helper text is the --text-helper token (text-helper).
    expect($fields)->toContain('<p id={hintId} className="text-muted-foreground text-helper">');
});
