<?php

namespace App\Actions\Fortify;

use App\Enums\AuditAction;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Fortify::authenticateUsing() callback (spec 8.2, 13.2).
 *
 * Returns the user on success, or null on ANY failure. Fortify then shows the
 * single generic "auth.failed" message, so the response never reveals whether
 * the email exists, is disabled or is locked. The real reason goes only to the
 * audit log.
 *
 * Successful logins are audited by the Login event listener (AuthEventAuditor),
 * not here, so each login is recorded exactly once.
 */
class AuthenticateUser
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public function __invoke(Request $request): ?User
    {
        $email = $request->input(Fortify::username());
        $user = is_string($email) ? User::where('email', $email)->first() : null;

        // Always run one hash check, even for an unknown email, so response
        // timing does not reveal which emails have accounts.
        $passwordValid = Hash::check(
            (string) $request->input('password'),
            $user?->password ?: $this->dummyHash(),
        );

        if (! $user) {
            return $this->fail('unknown_user', $email);
        }

        // Locked: reject even a correct password, and do not extend the lock.
        if ($user->isLocked()) {
            return $this->fail('locked', $email, $user);
        }

        if (! $passwordValid) {
            $this->fail('bad_password', $email, $user);
            $this->recordBadPassword($user);

            return null;
        }

        if (! $user->isActive()) {
            // IT-ADMIN: an account that has not used its activation link yet.
            return $this->fail($user->isPendingActivation() ? 'pending_activation' : 'disabled', $email, $user);
        }

        if ($user->isVendor() && ! $user->organisation?->active) {
            return $this->fail('org_inactive', $email, $user);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        if (Hash::needsRehash($user->password)) {
            // The "hashed" cast hashes the plain value with the current driver.
            $user->password = $request->input('password');
        }

        $user->save();

        return $user;
    }

    /**
     * Audit a failed attempt (best-effort: see Audit::recordAuthEvent) and return null.
     * The password is never recorded; the email only if it is a valid address.
     */
    private function fail(string $reason, mixed $email, ?User $user = null): null
    {
        Audit::recordAuthEvent(
            AuditAction::LoginFailed,
            ['reason' => $reason, 'attempted_email' => Audit::safeEmail($email)],
            $user ?? Audit::ACTOR_ANONYMOUS,
        );

        return null;
    }

    private function recordBadPassword(User $user): void
    {
        $attempts = $user->failed_login_attempts + 1;

        if ($attempts < self::MAX_ATTEMPTS) {
            $user->forceFill(['failed_login_attempts' => $attempts])->save();

            return;
        }

        $lockedUntil = CarbonImmutable::now('UTC')->addMinutes(self::LOCK_MINUTES);

        // Reset the counter so the user gets a fresh set of attempts once the
        // lock expires, instead of being re-locked on the next miss.
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => $lockedUntil,
        ])->save();

        Audit::recordAuthEvent(
            AuditAction::AccountLocked,
            ['locked_until' => $lockedUntil->toIso8601ZuluString()],
            $user,
        );
    }

    private function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make('timing-equaliser');
    }
}
