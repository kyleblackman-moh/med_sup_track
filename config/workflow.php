<?php

// Workflow switches (TERMINAL-1).

return [

    // T16, final reject (WITH_PS | RETURNED_TO_CMO -> REJECTED_FINAL).
    // ASSUMPTION (spec Q3 open): the CMO records a final rejection on the PS's
    // instruction. Set to false if the CMO's office answers Q3 differently: the
    // rule then behaves as if it did not exist (hidden and refused).
    'final_reject_enabled' => (bool) env('WORKFLOW_FINAL_REJECT_ENABLED', true),

];
