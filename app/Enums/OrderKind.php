<?php

namespace App\Enums;

/**
 * case_purchase_orders.order_kind (Kyle, 2026-11-27). The request form keeps
 * them in two tables of Existing Approved Arrangement: "Purchase Orders Under
 * the Original Tender" (payload section purchase_orders) and "Approved Tender
 * Excesses" (payload section previous_orders). The letter prints them as 3.2
 * and 3.3.
 */
enum OrderKind: string
{
    case ORIGINAL_TENDER = 'ORIGINAL_TENDER';
    case PREVIOUS_REQUEST = 'PREVIOUS_REQUEST';

    public function label(): string
    {
        return match ($this) {
            self::ORIGINAL_TENDER => 'Original Tender',
            self::PREVIOUS_REQUEST => 'Previous Request (Tender Excess)',
        };
    }

    /** The request payload's row section for this kind. */
    public function section(): string
    {
        return match ($this) {
            self::ORIGINAL_TENDER => 'purchase_orders',
            self::PREVIOUS_REQUEST => 'previous_orders',
        };
    }

    public static function forSection(string $section): ?self
    {
        return match ($section) {
            'purchase_orders' => self::ORIGINAL_TENDER,
            'previous_orders' => self::PREVIOUS_REQUEST,
            default => null,
        };
    }
}
