<?php

namespace App\Support;

use App\Models\User;
use RuntimeException;

/**
 * MFA-1: whether TOTP enrolment is enforced, and who has finished it.
 *
 * mfa.enforce may be false only in the local environment. assertAllowed runs
 * at boot (AppServiceProvider), so a production or staging deploy with the
 * flag off fails to start instead of running without MFA.
 */
final class MfaEnforcement
{
    public static function enforced(): bool
    {
        return (bool) config('mfa.enforce');
    }

    /** @throws RuntimeException when enforcement is off outside local */
    public static function assertAllowed(string $environment, bool $enforce): void
    {
        if (! $enforce && $environment !== 'local') {
            throw new RuntimeException("mfa.enforce may be false only when APP_ENV=local (APP_ENV is {$environment}).");
        }
    }

    /** A confirmed authenticator: a secret and a confirmation time. */
    public static function enrolled(User $user): bool
    {
        return filled($user->two_factor_secret) && $user->two_factor_confirmed_at !== null;
    }
}
