<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA: two server-side rules on Fortify's own two-factor endpoints, which this
 * app cannot change directly.
 *
 * 1. Turning MFA off (two-factor.disable) is refused with 403 while MFA is
 *    mandatory for this account. Hiding the button is not the control.
 * 2. Starting setup again (two-factor.enable) is refused with 409 once an
 *    authenticator is confirmed. Fortify would otherwise swap in a new,
 *    unconfirmed secret while keeping two_factor_confirmed_at, and the user's
 *    authenticator app would stop working.
 */
class GuardTwoFactorChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.disable') && $user->mustUseTwoFactor()) {
            abort(403, 'Two-factor authentication is required for this account.');
        }

        if ($request->routeIs('two-factor.enable') && $user->hasEnabledTwoFactorAuthentication()) {
            abort(409, 'Two-factor authentication is already on for this account.');
        }

        return $next($request);
    }
}
