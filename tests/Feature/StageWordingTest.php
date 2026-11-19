<?php

use App\Enums\CaseStatus;
use App\Workflow\Actions\RouteReturnedCase;
use App\Workflow\StageWording;

// NEXT-STEP-1: the one wording file covers every status and every destination.

it('has a stage, holder and instruction for every status', function (CaseStatus $status) {
    $entry = StageWording::STAGES[$status->value] ?? null;

    expect($entry)->not->toBeNull("No StageWording entry for {$status->value}")
        ->and($entry['stage'])->toBeString()->not->toBe('')
        ->and($entry['instruction'])->toBeString()->not->toBe('')
        ->and(array_key_exists('holder', $entry))->toBeTrue();
})->with(CaseStatus::cases());

it('names a holder for every status that is not finished', function (CaseStatus $status) {
    expect(StageWording::STAGES[$status->value]['holder'])->not->toBeNull();
})->with(array_values(array_filter(CaseStatus::cases(), fn (CaseStatus $s) => ! $s->isTerminal())));

it('names every route destination', function () {
    expect(array_keys(StageWording::ROUTE_DESTINATIONS))->toEqualCanonicalizing(RouteReturnedCase::DESTINATIONS);
});

it('fills in the vendor name from the organisation', function () {
    $words = StageWording::for(CaseStatus::NON_OBJECTION_GRANTED, 'Acme Pharma Ltd');

    expect($words['instruction'])->toContain('Acme Pharma Ltd')->not->toContain(StageWording::VENDOR)
        ->and(StageWording::for(CaseStatus::DRAFT, 'Acme Pharma Ltd')['holder'])->toBe('Acme Pharma Ltd');
});

it('has no keys for statuses that do not exist', function () {
    $values = array_map(fn (CaseStatus $s) => $s->value, CaseStatus::cases());

    expect(array_diff(array_keys(StageWording::STAGES), $values))->toBe([])
        ->and(array_diff(array_keys(StageWording::RETURNED_BY), $values))->toBe([]);
});
