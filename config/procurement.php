<?php

return [

    /*
    | VAT rate on the request form's quotation rows (Kyle, 2026-11-23), as a
    | decimal fraction: 0.125 = 12.5%. A string, so money is never computed
    | with floats (App\Support\QuotationPrice). Sent to the form, which uses
    | the same rounding for its live figures.
    */
    'vat_rate' => '0.125',

];
