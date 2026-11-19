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

    'headers' => [
        // B3: Content-Security-Policy is sent as Report-Only until it has been
        // watched in real use; set to false to enforce it.
        'csp_report_only' => (bool) env('CSP_REPORT_ONLY', true),
    ],

];
