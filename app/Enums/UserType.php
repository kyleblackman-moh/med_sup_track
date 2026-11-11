<?php

namespace App\Enums;

/**
 * Identity layer (spec 8.3, layer 1): staff belong to the MOH organisation,
 * vendor users to an organisation of type VENDOR. Not a role and grants nothing.
 */
enum UserType: string
{
    case Staff = 'staff';
    case Vendor = 'vendor';
}
