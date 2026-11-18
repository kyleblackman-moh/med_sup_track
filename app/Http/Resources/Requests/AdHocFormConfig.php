<?php

namespace App\Http\Resources\Requests;

use App\Enums\RfqAudience;
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
            ],
            'row_caps' => RequisitionRules::ROW_CAPS,
            'max_lengths' => [
                'clinical_note' => 2000,
                'price_note' => 1000,
                'reason_accepted_excluded' => 500,
            ],
        ];
    }
}
