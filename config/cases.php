<?php

// Case settings (slice 8d).

return [

    // Reference numbers: {prefix}-{year}-{6-digit sequence}, e.g. NR-2026-000001.
    // The year is taken in reference_timezone, not UTC.
    'reference_prefix' => env('CASES_REFERENCE_PREFIX', 'NR'),

    'reference_timezone' => env('CASES_REFERENCE_TIMEZONE', 'America/Port_of_Spain'),

];
