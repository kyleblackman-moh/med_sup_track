<?php

/*
 * SEC-1: session timeouts and security headers. Defaults here; each can be
 * overridden from the environment without a code change.
 */
return [

    'session' => [
        // Signed out after this long without a request (B2).
        'idle_minutes' => [
            'staff' => (int) env('SESSION_IDLE_MINUTES_STAFF', 30),
            'vendor' => (int) env('SESSION_IDLE_MINUTES_VENDOR', 20),
        ],
        // Signed out this long after signing in, active or not (B2).
        'absolute_hours' => (int) env('SESSION_ABSOLUTE_HOURS', 12),
    ],

    /*
     * FIX-1 (A1): every threshold of the five named limiters (App\Support\RateLimits).
     * "max" is attempts per "decay_minutes" window. The login per-IP cap counts
     * FAILED sign-ins only, so a shared office address is never blocked by
     * people signing in successfully.
     */
    'rate_limits' => [
        'login' => [
            'per_email' => (int) env('RATE_LOGIN_PER_EMAIL', 5),
            'failed_per_ip' => (int) env('RATE_LOGIN_FAILED_PER_IP', 100),
            'decay_minutes' => (int) env('RATE_LOGIN_DECAY_MINUTES', 15),
        ],
        // Asking for a reset link and using one (which is also how a new account is activated).
        'password_reset' => [
            'per_email' => (int) env('RATE_RESET_PER_EMAIL', 3),
            'per_ip' => (int) env('RATE_RESET_PER_IP', 30),
            'decay_minutes' => (int) env('RATE_RESET_DECAY_MINUTES', 15),
        ],
        'activation' => [
            'per_account' => (int) env('RATE_ACTIVATION_PER_ACCOUNT', 3),
            'per_admin' => (int) env('RATE_ACTIVATION_PER_ADMIN', 20),
            'decay_minutes' => (int) env('RATE_ACTIVATION_DECAY_MINUTES', 15),
        ],
        'create_request' => [
            'per_user' => (int) env('RATE_NEW_REQUEST_PER_USER', 20),
            'per_ip' => (int) env('RATE_NEW_REQUEST_PER_IP', 60),
            'decay_minutes' => (int) env('RATE_NEW_REQUEST_DECAY_MINUTES', 10),
        ],
        'uploads' => [
            'per_user' => (int) env('RATE_UPLOADS_PER_USER', 60),
            'per_ip' => (int) env('RATE_UPLOADS_PER_IP', 120),
            'decay_minutes' => (int) env('RATE_UPLOADS_DECAY_MINUTES', 1),
        ],
    ],

    'headers' => [
        // B3: Content-Security-Policy is sent as Report-Only until it has been
        // watched in real use; set to false to enforce it.
        'csp_report_only' => (bool) env('CSP_REPORT_ONLY', true),
    ],

];
