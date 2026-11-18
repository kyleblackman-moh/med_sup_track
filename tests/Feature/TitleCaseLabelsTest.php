<?php

use App\Models\ProcurementType;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use Database\Seeders\ProcurementTypeSeeder;

// V2-4b (design.md section 4, copy conventions): section labels and the five
// request type labels are Title Case, so they do not drift. Small words stay
// lowercase unless first or last; acronyms stay uppercase. DatabaseTransactions only.

const TITLE_CASE_SMALL_WORDS = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];

function isTitleCase(string $label): bool
{
    $words = array_values(array_filter(explode(' ', $label), fn (string $w) => preg_match('/[A-Za-z]/', $w) === 1));
    $last = count($words) - 1;

    foreach ($words as $i => $word) {
        $core = preg_replace('/^[^A-Za-z]+|[^A-Za-z]+$/', '', $word);
        if (strlen($core) >= 2 && strtoupper($core) === $core) {
            continue; // an acronym (DIN, RFQ, CMO)
        }
        if ($i !== 0 && $i !== $last && in_array(strtolower($core), TITLE_CASE_SMALL_WORDS, true)) {
            if ($core !== strtolower($core)) {
                return false;
            }

            continue;
        }
        foreach (explode('-', $core) as $part) {
            if ($part !== '' && ! ctype_upper($part[0])) {
                return false;
            }
        }
    }

    return true;
}

it('knows Title Case when it sees it', function (string $label, bool $expected) {
    expect(isTitleCase($label))->toBe($expected);
})->with([
    ['Item and Tender Details', true],
    ['Ad Hoc - Tender Unable to Supply', true],
    ['Submitted On', true],
    ['Item and tender details', false],
    ['Ad Hoc - Tender unable to supply', false],
    ['Item And Tender Details', false],
]);

it('gives every form section a Title Case label', function (string $key) {
    expect(isTitleCase(RequestSections::LABELS[$key]))->toBeTrue(RequestSections::LABELS[$key]);
})->with(RequestSections::ORDER);

it('seeds the five request types with Title Case labels', function (string $code) {
    $this->seed(ProcurementTypeSeeder::class);
    $name = ProcurementType::where('code', $code)->value('name');

    expect($name)->not->toBeNull()
        ->and(isTitleCase($name))->toBeTrue($name);
})->with(ProcurementTypeProfile::REQUEST_TYPES);
