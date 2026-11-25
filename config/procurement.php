<?php

return [

    /*
    | VAT rate on the request form's quotation rows (Kyle, 2026-11-23), as a
    | decimal fraction: 0.125 = 12.5%. A string, so money is never computed
    | with floats (App\Support\QuotationPrice). Sent to the form, which uses
    | the same rounding for its live figures.
    */
    'vat_rate' => '0.125',

    /*
    | The request letter PDF (App\Letters\RequestLetter). The recipient is the
    | Ministry's addressee, so it lives here, not in the template. The letterhead,
    | sign-off and footer come from the vendor's organisation record.
    */
    'letter' => [
        'recipient' => [
            'name' => env('LETTER_RECIPIENT_NAME', 'Dr. Jeanine St. Bernard'),
            'title' => env('LETTER_RECIPIENT_TITLE', 'Chief Medical Officer (Ag)'),
            'ministry' => 'Ministry of Health',
            'address_lines' => ['4-6 Queen\'s Park East'],
            'city' => 'Port of Spain',
        ],
        'salutation' => env('LETTER_SALUTATION', 'Dear Dr. St. Bernard,'),

        // letter | a4
        'paper' => env('LETTER_PAPER', 'letter'),

        // The letterhead's left band. Until organisations carry their own colour.
        'band_colour' => '#ee8b5e',
    ],

];
