<?php

namespace App\Enums;

/**
 * Stored on procurement_types.category, the single source of truth for a
 * case's category (cases.category was dropped in slice 6b).
 */
enum ProcurementCategory: string
{
    case PHARM = 'PHARM';
    case NON_PHARM = 'NON_PHARM';
}
