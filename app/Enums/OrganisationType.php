<?php

namespace App\Enums;

/**
 * Vendors are data: each vendor is an organisations row with type VENDOR.
 */
enum OrganisationType: string
{
    case Moh = 'MOH';
    case Vendor = 'VENDOR';
}
