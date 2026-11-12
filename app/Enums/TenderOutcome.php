<?php

namespace App\Enums;

/** case_requisitions.tender_outcome. */
enum TenderOutcome: string
{
    case NOT_AWARDED = 'NOT_AWARDED';
    case PARTIALLY_AWARDED = 'PARTIALLY_AWARDED';
    case SUPPLIER_UNABLE_TO_SUPPLY = 'SUPPLIER_UNABLE_TO_SUPPLY';

    public function label(): string
    {
        return match ($this) {
            self::NOT_AWARDED => 'Not awarded',
            self::PARTIALLY_AWARDED => 'Partially awarded',
            self::SUPPLIER_UNABLE_TO_SUPPLY => 'Supplier unable to supply',
        };
    }
}
