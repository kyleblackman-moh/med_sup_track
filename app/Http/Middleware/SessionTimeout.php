<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-1 (B2): signs a user out after config('security.session') idle minutes
 * (staff 30, vendors 20) or absolute hours (12) since signing in, then sends
 * them to sign in with a message saying why.
 *
 * Times are kept in the session itself (no browser storage). A sign-in through
 * the remember-me cookie would skip both limits, so it is treated as a timed
 * out session: the user signs in again.
 */
class SessionTimeout
{
    public const STARTED = 'auth_session.started_at';

    public const LAST_ACTIVITY = 'auth_session.last_activity';

    public const IDLE_MESSAGE = 'You were signed out because you were inactive.';

    public const ABSOLUTE_MESSAGE = 'You were signed out because you have been signed in for a long time. Sign in again to continue.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $request->routeIs('logout')) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp(); // Carbon, so tests can travel in time
        $started = $session->get(self::STARTED);

        if (Auth::viaRemember()) {
            return $this->signOut($request, self::IDLE_MESSAGE);
        }

        if ($started === null) {
            // The first request of this sign-in (the session is regenerated at login).
            $session->put([self::STARTED => $now, self::LAST_ACTIVITY => $now]);

            return $next($request);
        }

        $idleMinutes = config('security.session.idle_minutes.'.($user->isVendor() ? 'vendor' : 'staff'));
        $absoluteHours = config('security.session.absolute_hours');

        if ($now - (int) $session->get(self::LAST_ACTIVITY, $now) > $idleMinutes * 60) {
            return $this->signOut($request, self::IDLE_MESSAGE);
        }

        if ($now - (int) $started > $absoluteHours * 3600) {
            return $this->signOut($request, self::ABSOLUTE_MESSAGE);
        }

        $session->put(self::LAST_ACTIVITY, $now);

        return $next($request);
    }

    private function signOut(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message);
    }
}
