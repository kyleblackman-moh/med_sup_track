<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Fortify::authenticateUsing() callback (spec 8.2, 13.2).
 *
 * Returns the user on success, or null on ANY failure. Fortify then fires the
 * Failed event and shows the single generic "auth.failed" message, so the
 * response never reveals whether the email exists, is disabled or is locked.
 */
class AuthenticateUser
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public function __invoke(Request $request): ?User
    {
        $user = User::where('email', $request->input(Fortify::username()))->first();

        // Always run one hash check, even for an unknown email, so response
        // timing does not reveal which emails have accounts.
        $passwordValid = Hash::check(
            (string) $request->input('password'),
            $user?->password ?: $this->dummyHash(),
        );

        if (! $user) {
            return null;
        }

        // Locked: reject even a correct password, and do not extend the lock.
        if ($user->isLocked()) {
            return null;
        }

        if (! $passwordValid) {
            $this->recordFailure($user, $request);

            return null;
        }

        if (! $user->isActive()) {
            return null;
        }

        if ($user->isVendor() && ! $user->organisation?->active) {
            return null;
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

    private function recordFailure(User $user, Request $request): void
    {
        $attempts = $user->failed_login_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Reset the counter so the user gets a fresh set of attempts once
            // the lock expires, instead of being re-locked on the next miss.
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
            ])->save();

            event(new Lockout($request));

            return;
        }

        $user->forceFill(['failed_login_attempts' => $attempts])->save();
    }

    private function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make('timing-equaliser');
    }
}
