<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Security\MfaController;
use App\Support\MfaEnforcement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA-1: a signed-in user (staff or vendor) without a confirmed authenticator
 * can reach only the MFA setup pages, the Fortify two-factor routes, password
 * confirmation and sign out. Everything else redirects to /security/mfa/setup.
 *
 * A user who has just confirmed (or regenerated) recovery codes is held on the
 * recovery codes page until they tick "I have saved these codes".
 *
 * Appended to the web group after EnsurePasswordChanged: a user who must
 * change their password does that first (this middleware lets them through).
 * Guests pass straight through. Off only when mfa.enforce is false (local only).
 */
class EnsureMfaEnrolled
{
    /** Route names an unenrolled user may still reach. */
    public const ALLOWED = ['security.mfa.*', 'two-factor.*', 'logout', 'password.confirm', 'password.confirmation'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! MfaEnforcement::enforced() || $user->password_change_required) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED) || $request->is('confirm-password', 'user/confirm-password', 'user/confirmed-password-status')) {
            return $next($request);
        }

        if (! MfaEnforcement::enrolled($user)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Set up multi-factor authentication to continue.'], 403)
                : redirect()->route('security.mfa.setup');
        }

        if ($request->session()->get(MfaController::SESSION_SHOW_CODES) === true) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Confirm that you have saved your recovery codes.'], 403)
                : redirect()->route('security.mfa.recovery-codes');
        }

        return $next($request);
    }
}
