<?php

namespace App\Enums;

/** case_quotations.technical_status. Set by MOH staff only, never by the vendor. */
enum TechnicalStatus: string
{
    case PENDING = 'PENDING';
    case SAMPLE_ACCEPTED = 'SAMPLE_ACCEPTED';
    case PRIOR_APPROVAL_VERIFIED = 'PRIOR_APPROVAL_VERIFIED';
    case NO_ACCEPTABLE_SAMPLE_OR_HISTORY = 'NO_ACCEPTABLE_SAMPLE_OR_HISTORY';
    case TECHNICALLY_REJECTED = 'TECHNICALLY_REJECTED';
}
