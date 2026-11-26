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
            // Kyle, 2026-11-26: the request form's names for them.
            self::ALL_REGISTERED => 'Prequalified Suppliers',
            self::SPECIFIED => 'Other Suppliers',
        };
    }
}
