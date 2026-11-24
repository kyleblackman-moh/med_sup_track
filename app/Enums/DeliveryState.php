<?php

namespace App\Enums;

/**
 * case_purchase_orders.delivery_state (Kyle, 2026-11-23): the order row's
 * Delivery Status dropdown. The list is an assumption to confirm with the
 * CMO's office. The older free-text delivery_status column is kept (dropped
 * fields keep their columns) and no longer written by the request form.
 */
enum DeliveryState: string
{
    case NOT_DELIVERED = 'NOT_DELIVERED';
    case PARTIALLY_DELIVERED = 'PARTIALLY_DELIVERED';
    case FULLY_DELIVERED = 'FULLY_DELIVERED';

    public function label(): string
    {
        return match ($this) {
            self::NOT_DELIVERED => 'Not delivered',
            self::PARTIALLY_DELIVERED => 'Partially delivered',
            self::FULLY_DELIVERED => 'Fully delivered',
        };
    }
}
