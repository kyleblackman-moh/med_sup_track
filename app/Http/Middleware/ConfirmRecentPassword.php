<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IT-ADMIN: every admin action needs the password confirmed within
 * admin.confirm_password_seconds (15 minutes), on the existing Confirm
 * Password page (route password.confirm; it sets
 * auth.password_confirmed_at, as Laravel's password.confirm middleware does).
 *
 * Laravel's own middleware only remembers where to return for a GET. For a
 * POST or PUT this returns the user to the page they acted from after
 * confirming, so they can repeat the action; nothing is changed until then.
 * A JSON request gets 423, as Laravel's does.
 */
class ConfirmRecentPassword
{
    public const SESSION_KEY = 'auth.password_confirmed_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (self::isRecent($request)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Password confirmation required.'], 423);
        }

        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return redirect()->route('password.confirm');
    }

    public static function isRecent(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get(self::SESSION_KEY, 0);

        return $confirmedAt > 0 && now()->getTimestamp() - $confirmedAt <= (int) config('admin.confirm_password_seconds', 900);
    }
}
