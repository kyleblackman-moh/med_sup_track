<?php

namespace App\Enums;

/** documents.visibility. Vendor users only ever see EXTERNAL documents. */
enum DocumentVisibility: string
{
    case INTERNAL = 'INTERNAL';
    case EXTERNAL = 'EXTERNAL';
}
