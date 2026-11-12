<?php

namespace App\Enums;

/** case_requisitions.rfq_audience. */
enum RfqAudience: string
{
    case ALL_REGISTERED = 'ALL_REGISTERED';
    case SPECIFIED = 'SPECIFIED';

    public function label(): string
    {
        return match ($this) {
            self::ALL_REGISTERED => 'All registered suppliers',
            self::SPECIFIED => 'Specified suppliers',
        };
    }
}
