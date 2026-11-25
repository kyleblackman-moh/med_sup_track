<?php

use App\Support\NumberWords;

// The request letter's quantities in words (the intl extension is not installed).

it('spells whole numbers as the letters write them', function (int $n, string $words) {
    expect(NumberWords::spell($n))->toBe($words);
})->with([
    [0, 'zero'],
    [1, 'one'],
    [2, 'two'],
    [15, 'fifteen'],
    [40, 'forty'],
    [101, 'one hundred one'],
    [29_988, 'twenty-nine thousand nine hundred eighty-eight'],
    [80_000, 'eighty thousand'],
    [800_000, 'eight hundred thousand'],
    [1_000_001, 'one million one'],
]);

it('gives words with figures, and figures only for a fraction', function () {
    expect(NumberWords::withFigures('29988.00'))->toBe('twenty-nine thousand nine hundred eighty-eight (29,988)')
        ->and(NumberWords::withFigures('1'))->toBe('one (1)')
        ->and(NumberWords::withFigures('200.50'))->toBe('200.5')
        ->and(NumberWords::withFigures(null))->toBeNull();
});

it('refuses numbers out of range', function () {
    NumberWords::spell(-1);
})->throws(InvalidArgumentException::class);
