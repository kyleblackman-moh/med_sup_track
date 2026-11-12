<?php

namespace App\Support;

use RuntimeException;

/**
 * Exact decimal arithmetic for money and quantities, on numeric strings.
 * Never uses PHP floats.
 *
 * Uses bcmath (loaded on this server). Intermediate results keep SCALE places;
 * round2() rounds half-up (half away from zero) to 2 decimals for display.
 */
final class Decimal
{
    private const SCALE = 10;

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::check($a), self::check($b), self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::check($a), self::check($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::check($a), self::check($b), self::SCALE);
    }

    /** Callers must make sure $divisor is not zero. */
    public static function div(string $dividend, string $divisor): string
    {
        return bcdiv(self::check($dividend), self::check($divisor), self::SCALE);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::check($a), self::check($b), self::SCALE);
    }

    /** Half-up (away from zero) to 2 decimal places. */
    public static function round2(string $value): string
    {
        $value = self::check($value);
        $half = str_starts_with($value, '-') ? '-0.005' : '0.005';
        $rounded = bcadd($value, $half, 2);

        return $rounded === '-0.00' ? '0.00' : $rounded;
    }

    private static function check(string $value): string
    {
        if (! extension_loaded('bcmath')) {
            throw new RuntimeException('The bcmath extension is required for decimal arithmetic.');
        }

        return trim($value);
    }
}
