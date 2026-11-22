<?php

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

/**
 * MFA: signs in the user ResolveLoginUser accepted (no second credential check),
 * for accounts without a confirmed authenticator. Never remembered (FIX-1).
 *
 * The user has just typed their password, so the session also counts as
 * password-confirmed: the mandatory setup page (behind password.confirm) does
 * not ask for it again straight after sign-in. The normal timeout still applies.
 */
class LoginResolvedUser
{
    public function __construct(private readonly StatefulGuard $guard) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->guard->login(ResolveLoginUser::user($request), false);

        $request->session()->put('auth.password_confirmed_at', time());

        return $next($request);
    }
}
