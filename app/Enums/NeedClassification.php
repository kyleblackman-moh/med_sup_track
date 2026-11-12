<?php

namespace App\Enums;

/** case_requisitions.need_classification. */
enum NeedClassification: string
{
    case VITAL = 'VITAL';
    case ESSENTIAL = 'ESSENTIAL';
    case OTHER = 'OTHER';
}
