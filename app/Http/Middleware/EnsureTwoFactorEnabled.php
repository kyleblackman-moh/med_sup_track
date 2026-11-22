<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA (spec 8.2): while config('auth.mfa_enforce') is on, a signed-in local
 * account without a confirmed authenticator can only set one up or sign out.
 * Every other page redirects to the setup page; JSON calls (the bell's
 * unread-count poll and the like) get a 409 JSON answer instead of a redirect,
 * so a background poll never loops through the setup page.
 *
 * Appended to the web group after EnsurePasswordChanged (same reasoning: the
 * signed-in routes are spread over several route files). A forced password
 * change comes first, so this step stands aside until that flag is cleared.
 * Guests and SSO accounts pass straight through.
 */
class EnsureTwoFactorEnabled
{
    /** Route names a user who still has to set up MFA may reach. */
    public const ALLOWED = [
        'two-factor.setup',
        'logout',
        // Password confirmation (the setup page and Fortify's endpoints sit behind it).
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        // Fortify's setup endpoints.
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
    ];

    /** JSON endpoints polled in the background: answered with 409, never redirected. */
    public const JSON_ROUTES = [
        'notifications.unread-count',
        'notifications.latest',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || $user->password_change_required
            || ! $user->mustUseTwoFactor()
            || $user->hasEnabledTwoFactorAuthentication()
            || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->routeIs(...self::JSON_ROUTES) || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return new JsonResponse([
                'message' => 'Set up two-factor authentication to continue.',
                'two_factor_setup' => route('two-factor.setup'),
            ], 409);
        }

        return redirect()->route('two-factor.setup')
            ->with('info', 'Set up two-factor authentication before you continue.');
    }
}
