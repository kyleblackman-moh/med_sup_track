<?php

namespace App\Http\Resources\Requests;

use App\Enums\NeedClassification;
use App\Enums\PreviousPriceMaintained;
use App\Enums\ProcurementCategory;
use App\Enums\RfqAudience;
use App\Enums\TenderOutcome;
use App\Models\ProcurementType;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequisitionRules;

/**
 * What the request form needs to lay itself out for one procurement type.
 * Display only: the server re-validates every save (RequisitionRules).
 */
final class FormConfig
{
    public static function for(ProcurementType $type): array
    {
        $profile = ProcurementTypeProfile::for($type);

        return [
            'procurement_type' => [
                'code' => $type->code,
                'name' => $type->name,
                'category' => $type->category->value,
            ],
            'is_pharm' => $type->category === ProcurementCategory::PHARM,
            'allowed_sections' => $profile->allowedSections,
            'required_sections' => $profile->requiredSections,
            'row_caps' => array_intersect_key(RequisitionRules::ROW_CAPS, array_flip($profile->allowedSections)),
            'options' => [
                'need_classification' => self::options(NeedClassification::cases()),
                'tender_outcome' => self::options(TenderOutcome::cases()),
                'rfq_audience' => self::options(RfqAudience::cases()),
                'previous_price_maintained' => self::options(PreviousPriceMaintained::cases()),
            ],
        ];
    }

    /** @param  list<NeedClassification|TenderOutcome|RfqAudience|PreviousPriceMaintained>  $cases */
    private static function options(array $cases): array
    {
        return array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], $cases);
    }
}
