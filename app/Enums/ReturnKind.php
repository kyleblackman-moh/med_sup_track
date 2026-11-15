<?php

namespace App\Enums;

/**
 * What kind of query a PS return to the CMO is (slice 10d). Stored on the
 * case_transitions row of a WITH_PS -> RETURNED_TO_CMO transition only.
 */
enum ReturnKind: string
{
    case Legal = 'legal';
    case Technical = 'technical';

    /** Form option label. */
    public function label(): string
    {
        return match ($this) {
            self::Legal => 'Legal-related',
            self::Technical => 'Technical',
        };
    }

    /** Banner wording, for example "(legal-related query)". Staff only. */
    public function queryLabel(): string
    {
        return match ($this) {
            self::Legal => 'legal-related query',
            self::Technical => 'technical query',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
