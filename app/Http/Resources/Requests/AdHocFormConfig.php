<?php

namespace App\Http\Resources\Requests;

use App\Enums\DeliveryState;
use App\Enums\RfqAudience;
use App\Submissions\FormWording;
use App\Submissions\RequisitionRules;

/**
 * What the AD_HOC form (slice F2) needs to lay itself out: option labels, row
 * caps and text limits. Display only: the server re-validates every save
 * (RequisitionRules) and decides readiness (RequestSections).
 */
final class AdHocFormConfig
{
    public static function for(): array
    {
        return [
            // V2-3: no tender outcome options; awards and orders never depend on the outcome.
            'options' => [
                'rfq_audience' => [
                    ['value' => RfqAudience::ALL_REGISTERED->value, 'label' => 'All registered suppliers'],
                    ['value' => RfqAudience::SPECIFIED->value, 'label' => 'Specified suppliers'],
                ],
                // 2026-11-24: the order row's Delivery Status dropdown, labelled by the enum.
                'delivery_state' => array_map(
                    fn (DeliveryState $s) => ['value' => $s->value, 'label' => $s->label()],
                    DeliveryState::cases(),
                ),
            ],
            'row_caps' => RequisitionRules::ROW_CAPS,
            'max_lengths' => [
                'clinical_note' => 2000,
                'price_note' => 1000,
                'reason_accepted_excluded' => 500,
            ],
            // FORM-SAVE-1: the form's fixed notices, worded by the server (the browser only shows them).
            'messages' => [
                'leave_unsaved' => FormWording::LEAVE_UNSAVED,
                'type_saved_others_pending' => FormWording::TYPE_SAVED_OTHERS_PENDING,
            ],
        ];
    }
}
