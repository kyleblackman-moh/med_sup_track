<?php

namespace App\Http\Resources\Requests;

use App\Enums\RfqAudience;
use App\Enums\TenderOutcome;
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
            'options' => [
                'tender_outcome' => [
                    ['value' => TenderOutcome::NOT_AWARDED->value, 'label' => 'Not awarded'],
                    ['value' => TenderOutcome::PARTIALLY_AWARDED->value, 'label' => 'Partially awarded'],
                    ['value' => TenderOutcome::SUPPLIER_UNABLE_TO_SUPPLY->value, 'label' => 'Awarded but supplier unable to supply'],
                ],
                'rfq_audience' => [
                    ['value' => RfqAudience::ALL_REGISTERED->value, 'label' => 'All registered suppliers'],
                    ['value' => RfqAudience::SPECIFIED->value, 'label' => 'Specified suppliers'],
                ],
            ],
            // The outcome that hides the award and order tables.
            'not_awarded' => TenderOutcome::NOT_AWARDED->value,
            'row_caps' => RequisitionRules::ROW_CAPS,
            'max_lengths' => [
                'clinical_note' => 2000,
                'price_note' => 1000,
                'reason_accepted_excluded' => 500,
            ],
        ];
    }
}
