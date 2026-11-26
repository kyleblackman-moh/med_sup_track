<?php

namespace App\Enums;

/**
 * case_purchase_orders.delivery_state (Kyle, 2026-11-23): the order row's
 * Delivery Status dropdown. Kyle, 2026-11-25: Partial Delivery, Delivered,
 * Not Delivered and Other, in that order; Other is described in the older
 * free-text delivery_status column (up to 120 characters), which the form
 * writes again for that status only. The stored values are unchanged.
 */
enum DeliveryState: string
{
    case PARTIALLY_DELIVERED = 'PARTIALLY_DELIVERED';
    case FULLY_DELIVERED = 'FULLY_DELIVERED';
    case NOT_DELIVERED = 'NOT_DELIVERED';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::PARTIALLY_DELIVERED => 'Partial Delivery',
            self::FULLY_DELIVERED => 'Delivered',
            self::NOT_DELIVERED => 'Not Delivered',
            self::OTHER => 'Other',
        };
    }
}
