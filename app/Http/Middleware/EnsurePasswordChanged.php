<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in user flagged password_change_required (set by app:user-create)
 * can only change their password or sign out. Every other web route
 * redirects to settings/password. Settings\PasswordController@update clears
 * the flag after a successful change.
 *
 * Appended to the web group rather than to one auth group, because the
 * signed-in routes are spread over web.php, settings.php, auth.php and
 * Fortify's own routes. Guests pass straight through.
 */
class EnsurePasswordChanged
{
    /** Route names a flagged user may still reach. */
    public const ALLOWED = ['password.edit', 'password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->password_change_required || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        return redirect()->route('password.edit')
            ->with('info', 'Please choose a new password before you continue.');
    }
}
