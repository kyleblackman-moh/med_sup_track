<?php

use App\Enums\CaseStatus;

// Status colours: the server decides each tone (CaseStatus::tone / vendorTone);
// the colours are the status palette in resources/css/app.css and
// TONE_CLASSES (status-pill.tsx) maps every tone. Enum and file reads only: no DB.

/** The tones that use the daisyUI semantic colours rather than a palette line. */
const SEMANTIC_TONES = ['neutral', 'info', 'success', 'warning', 'danger'];

/** The internal (staff-only) stages: everything a vendor sees as "Under review". */
function internalStages(): array
{
    return array_values(array_filter(CaseStatus::cases(), fn (CaseStatus $s) => $s->vendorGroup() === 'UNDER_REVIEW'));
}

function allTones(): array
{
    return array_values(array_unique([
        ...array_map(fn (CaseStatus $s) => $s->tone(), CaseStatus::cases()),
        ...array_map(fn (CaseStatus $s) => $s->vendorTone(), CaseStatus::cases()),
    ]));
}

it('gives every staff status a tone', function () {
    foreach (CaseStatus::cases() as $status) {
        expect($status->tone())->toBeString()->not->toBe('', $status->value);
    }
});

it('gives each non-terminal internal stage its own tone', function () {
    $stages = array_filter(internalStages(), fn (CaseStatus $s) => ! $s->isTerminal());
    $tones = array_map(fn (CaseStatus $s) => $s->tone(), $stages);

    expect(count($stages))->toBeGreaterThan(5)
        ->and(array_unique($tones))->toHaveCount(count($tones));
});

it('gives the vendor one tone for every internal stage, so no stage leaks through colour', function () {
    $vendorTones = array_unique(array_map(fn (CaseStatus $s) => $s->vendorTone(), internalStages()));

    expect($vendorTones)->toHaveCount(1)
        ->and(CaseStatus::RETURNED_TO_CMO->vendorTone())->toBe(CaseStatus::IN_REVIEW->vendorTone())
        // The "Under review" tone is not reused by a vendor group that would tell the stages apart.
        ->and(CaseStatus::SUBMITTED->vendorTone())->not->toBe(CaseStatus::IN_REVIEW->vendorTone());
});

it('maps every tone in TONE_CLASSES and the StatusTone type', function () {
    $pill = file_get_contents(__DIR__.'/../../resources/js/components/ui/status-pill.tsx');
    $types = file_get_contents(__DIR__.'/../../resources/js/types/cases.ts');

    foreach (allTones() as $tone) {
        expect($pill)->toMatch("/^\\s*{$tone}: '[^']+',$/m")
            ->and($types)->toContain("| '{$tone}'");
    }
});

it('defines a palette line and a Tailwind colour for every non-semantic tone', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

    expect($css)->toContain('Status palette: change a colour here and every badge, dot and legend using it follows.');

    foreach (array_diff(allTones(), SEMANTIC_TONES) as $tone) {
        expect($css)->toMatch('/^\s*--status-'.$tone.':\s*hsl\([^;]+\);/m')
            ->and($css)->toContain("--color-status-{$tone}: var(--status-{$tone});");
    }
});
