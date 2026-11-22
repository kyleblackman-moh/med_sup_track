<?php

namespace App\Actions\Fortify;

use App\Support\MfaEnforcement;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

/**
 * MFA-1: the password step of the login pipeline, in place of Fortify's
 * RedirectIfTwoFactorAuthenticatable + AttemptToAuthenticate pair, which would
 * run the AuthenticateUser callback twice per login (doubling failed-attempt
 * counts and audit rows).
 *
 * The callback runs once. A user with a confirmed authenticator is NOT signed
 * in: their id goes in the session (login.id, as Fortify's challenge expects)
 * and they are sent to the two-factor challenge. Anyone else is signed in and
 * carries on (EnsureMfaEnrolled then sends them to MFA setup).
 */
final class AuthenticateOrChallenge
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly LoginRateLimiter $limiter,
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        $user = call_user_func(Fortify::$authenticateUsingCallback, $request);

        if (! $user) {
            event(new Failed(config('fortify.guard'), null, [
                Fortify::username() => $request->input(Fortify::username()),
            ]));

            $this->limiter->increment($request);

            throw ValidationException::withMessages([
                Fortify::username() => [trans('auth.failed')],
            ]);
        }

        if (MfaEnforcement::enrolled($user)) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false, // FIX-1: no remember-me cookie
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return $request->wantsJson()
                ? response()->json(['two_factor' => true])
                : redirect()->route('two-factor.login');
        }

        $this->guard->login($user, false);

        return $next($request);
    }
}
