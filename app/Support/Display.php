<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Slice V2-2: display strings for the request form, formatted on the server
 * so the page does no maths and no number or date formatting. Values are the
 * decimal strings the models compute (Decimal); formatting is text-only, so
 * nothing passes through a float. A missing value shows as EMPTY.
 */
final class Display
{
    public const EMPTY = '—';

    /** A quantity: thousands separators, no trailing zero decimals ("2,800", "80.5"). */
    public static function quantity(?string $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            return self::EMPTY;
        }

        [$whole, $fraction] = self::split($value);
        $fraction = rtrim($fraction, '0');

        return self::group($whole).($fraction === '' ? '' : '.'.$fraction);
    }

    /** A money amount: thousands separators and 2 decimals ("1,234.50"). */
    public static function money(?string $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            return self::EMPTY;
        }

        [$whole, $fraction] = self::split(Decimal::round2($value));

        return self::group($whole).'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * V2-3: a unit price (stored to 4 decimals): thousands separators, at least
     * 2 decimals, trailing zeros beyond those dropped ("1.5975", "1.25", "1,200.00").
     */
    public static function price(?string $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            return self::EMPTY;
        }

        [$whole, $fraction] = self::split($value);

        return self::group($whole).'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }

    /** V2-3: yes/no for a stored flag; a dash when it was never answered. */
    public static function yesNo(?bool $value): string
    {
        return $value === null ? self::EMPTY : ($value ? 'Yes' : 'No');
    }

    /** V2-3: text as entered, or a dash when empty. */
    public static function text(mixed $value): string
    {
        return $value === null || trim((string) $value) === '' ? self::EMPTY : (string) $value;
    }

    /** A percentage the server computed to 2 decimals ("62.50%"). */
    public static function percent(?string $value): string
    {
        return $value === null || ! is_numeric($value) ? self::EMPTY : Decimal::round2($value).'%';
    }

    /** A non-negative day count to the nearest whole day ("13"). */
    public static function wholeDays(string $value): string
    {
        return bcadd($value, '0.5', 0);
    }

    /** A calendar date as people read it ("31 Mar 2027"). */
    public static function date(?CarbonInterface $value): string
    {
        return $value === null ? self::EMPTY : $value->format('j M Y');
    }

    /** @return array{0: string, 1: string} sign and whole digits, decimal digits */
    private static function split(string $value): array
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-+'), 2), 2, '');
        $whole = ltrim($whole, '0') === '' ? '0' : ltrim($whole, '0');

        return [($negative ? '-' : '').$whole, $fraction];
    }

    private static function group(string $whole): string
    {
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $digits = ltrim($whole, '-');

        return $sign.strrev(implode(',', str_split(strrev($digits), 3)));
    }
}
