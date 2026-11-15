<?php

// Case settings (slice 8d).

return [

    // Reference numbers: {prefix}-{year}-{6-digit sequence}, e.g. NR-2026-000001.
    // The year is taken in reference_timezone, not UTC.
    'reference_prefix' => env('CASES_REFERENCE_PREFIX', 'NR'),

    'reference_timezone' => env('CASES_REFERENCE_TIMEZONE', 'America/Port_of_Spain'),

    // File numbers (slice 10c). The prepare-file form pre-fills this; the CMO may
    // add to it or edit it, and the prefix is not validated.
    // ASSUMPTION: a fixed series. What varies per case is not yet known; to
    // confirm with the CMO's office (spec Q5).
    'file_no_prefix' => 'HE:8/2/50',

];
