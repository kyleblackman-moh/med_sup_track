<?php

// MFA-1: mandatory TOTP multi-factor authentication for every account.

return [

    // Every signed-in user (staff and vendor) must enrol and confirm an
    // authenticator app before using the application. May be false ONLY when
    // APP_ENV=local: App\Support\MfaEnforcement throws at boot in any other
    // environment.
    'enforce' => (bool) env('MFA_ENFORCE', true),

];
