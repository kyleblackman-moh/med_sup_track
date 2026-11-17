<?php

namespace App\Enums;

/**
 * Stored on procurement_types.category, the single source of truth for a
 * case's category (cases.category was dropped in slice 6b). Null for the
 * tender types (TENDER_EXCESS, TENDER_VARIATION) and the old AD_HOC.
 */
enum ProcurementCategory: string
{
    case PHARM = 'PHARM';
    case NON_PHARM = 'NON_PHARM';
    case AD_HOC = 'AD_HOC'; // slice V2-1: the three Ad Hoc request types
}
