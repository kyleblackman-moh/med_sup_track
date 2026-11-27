<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Whole numbers in lowercase English words, as the request letters write them
 * ("twenty-nine thousand nine hundred and eighty-eight"): hyphenated tens and
 * the British "and" (Kyle, 2026-11-27): after "hundred", and before a last
 * group below one hundred ("two thousand and fifty", "one million and one").
 * The intl extension is not installed on the server, so this does the spelling
 * itself. Supports 0 to 999,999,999,999.
 */
final class NumberWords
{
    private const ONES = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen',
    ];

    private const TENS = [2 => 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    private const SCALES = [1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'];

    public static function spell(int $number): string
    {
        if ($number < 0 || $number > 999_999_999_999) {
            throw new InvalidArgumentException('NumberWords supports 0 to 999,999,999,999.');
        }

        if ($number === 0) {
            return self::ONES[0];
        }

        $words = [];
        foreach (self::SCALES as $size => $name) {
            if ($number >= $size) {
                $words[] = self::belowThousand(intdiv($number, $size)).' '.$name;
                $number %= $size;
            }
        }

        if ($number > 0) {
            $words[] = ($words !== [] && $number < 100 ? 'and ' : '').self::belowThousand($number);
        }

        return implode(' ', $words);
    }

    /**
     * A decimal string as "words (figures)": "twenty-nine thousand nine hundred
     * and eighty-eight (29,988)". A quantity with a fraction has no words, only
     * the figures ("200.5"). Null for a missing or non-numeric value.
     */
    public static function withFigures(?string $value): ?string
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $figures = Display::quantity($value);
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+'), 2), 2, '');

        if (rtrim($fraction, '0') !== '' || str_starts_with($whole, '-') || strlen(ltrim($whole, '0')) > 12) {
            return $figures;
        }

        return self::spell((int) $whole).' ('.$figures.')';
    }

    private static function belowThousand(int $number): string
    {
        $words = [];

        if ($number >= 100) {
            $words[] = self::ONES[intdiv($number, 100)].' hundred'.($number % 100 === 0 ? '' : ' and');
            $number %= 100;
        }

        if ($number >= 20) {
            $tens = self::TENS[intdiv($number, 10)];
            $words[] = $number % 10 === 0 ? $tens : $tens.'-'.self::ONES[$number % 10];
        } elseif ($number > 0) {
            $words[] = self::ONES[$number];
        }

        return implode(' ', $words);
    }
}
