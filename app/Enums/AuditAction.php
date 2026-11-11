<?php

namespace App\Enums;

/**
 * Names of audited events. Later slices add more cases.
 */
enum AuditAction: string
{
    case LoginSuccess = 'auth.login.success';
    case LoginFailed = 'auth.login.failed';
    case AccountLocked = 'auth.account.locked';
    case RateLimited = 'auth.rate_limited';
    case Logout = 'auth.logout';
    case PasswordReset = 'auth.password.reset';
}
