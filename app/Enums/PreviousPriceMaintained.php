<?php

namespace App\Enums;

/** case_quotations.previous_price_maintained. */
enum PreviousPriceMaintained: string
{
    case YES = 'YES';
    case NO = 'NO';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::YES => 'Yes',
            self::NO => 'No',
            self::UNKNOWN => 'Unknown',
        };
    }
}
