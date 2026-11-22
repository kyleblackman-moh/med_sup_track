<?php

// IT-ADMIN: the IT Admin console (users, audit log, IT health). Every value has
// a safe default; each can be overridden from the environment.

return [

    'activation' => [
        // How long an emailed activation link works (single use; a resend revokes the old one).
        'expire_hours' => (int) env('ADMIN_ACTIVATION_EXPIRE_HOURS', 48),
    ],

    'passwords' => [
        // Refuse passwords found in public breaches (Have I Been Pwned, k-anonymity
        // range query) when a new account is activated. The server may have no
        // internet access: the check then lets the password through after the
        // timeout (Laravel's NotPwnedVerifier fails open) and logs the error.
        'check_breached' => (bool) env('ADMIN_PASSWORDS_CHECK_BREACHED', true),
        'breached_timeout_seconds' => (int) env('ADMIN_PASSWORDS_BREACHED_TIMEOUT', 5),
    ],

    // Every admin action needs a password confirmed within this many seconds.
    'confirm_password_seconds' => (int) env('ADMIN_CONFIRM_PASSWORD_SECONDS', 900),

    'audit' => [
        'per_page' => 50,
        // log.viewed: at most this many rows per user per window (later views are not logged).
        'viewed_log_max' => (int) env('ADMIN_AUDIT_VIEWED_LOG_MAX', 30),
        'viewed_log_decay_minutes' => (int) env('ADMIN_AUDIT_VIEWED_LOG_DECAY_MINUTES', 10),
        // access.denied: at most this many rows per user (or IP) a minute.
        'denied_log_per_minute' => (int) env('ADMIN_AUDIT_DENIED_LOG_PER_MINUTE', 30),
    ],

    'health' => [
        'cache_seconds' => 60,
        // Warn when the documents volume has less free space than this.
        'disk_warn_free_gb' => (float) env('ADMIN_HEALTH_DISK_WARN_FREE_GB', 10),
        'clamd_timeout_seconds' => (int) env('ADMIN_HEALTH_CLAMD_TIMEOUT', 2),
        'failed_jobs_shown' => 10,
    ],

];
