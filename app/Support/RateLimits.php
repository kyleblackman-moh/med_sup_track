<?php

namespace App\Support;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-1 (B1): the named rate limiters, in one place. Each is keyed by the
 * person (email or user) AND the IP where that matters, with a looser cap per
 * IP so many emails from one address are limited too.
 *
 * Over the limit: 429 with Retry-After and a plain message with the wait
 * ("Too many attempts. Try again in 15 minutes."). An Inertia request gets the
 * same message back on its page instead (a bare 429 would break the page),
 * still with Retry-After. The message never depends on whether an email exists.
 *
 * Thresholds come from config('security.rate_limits') (FIX-1), each with an
 * env override; the login per-IP cap counts failed sign-ins only.
 *
 * The account lock (AuthenticateUser: 5 wrong passwords lock that account for
 * 15 minutes) is separate and stays as it is.
 */
final class RateLimits
{
    public static function register(): void
    {
        // FIX-1: both caps count FAILED sign-ins only. The after() callback hits
        // a counter only when the request ended without a signed-in user, so
        // good sign-ins (one person, or a whole office behind one address) never
        // throttle. The check before each request still refuses once over.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinutes(self::cfg('login.decay_minutes'), self::cfg('login.per_email'))
                ->by('login|'.self::email($request).'|'.$request->ip())
                ->after(fn () => ! Auth::check())
                ->response(self::respond('email', lockout: true)),
            Limit::perMinutes(self::cfg('login.decay_minutes'), self::cfg('login.failed_per_ip'))
                ->by('login-ip|'.$request->ip())
                ->after(fn () => ! Auth::check())
                ->response(self::respond('email', lockout: true)),
        ]);

        // Asking for a reset link, and using one (which is also how a new account is activated).
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinutes(self::cfg('password_reset.decay_minutes'), self::cfg('password_reset.per_email'))
                ->by('reset|'.$request->route()?->getName().'|'.self::email($request).'|'.$request->ip())
                ->response(self::respond('email')),
            Limit::perMinutes(self::cfg('password_reset.decay_minutes'), self::cfg('password_reset.per_ip'))
                ->by('reset-ip|'.$request->ip())
                ->response(self::respond('email')),
        ]);

        // An admin resending a sign-in link (ADMIN-1): per account, and per admin.
        RateLimiter::for('activation', fn (Request $request) => [
            Limit::perMinutes(self::cfg('activation.decay_minutes'), self::cfg('activation.per_account'))
                ->by('activation|'.self::routeKey($request, 'user').'|'.$request->ip())
                ->response(self::respond('account')),
            Limit::perMinutes(self::cfg('activation.decay_minutes'), self::cfg('activation.per_admin'))
                ->by('activation-admin|'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
                ->response(self::respond('account')),
        ]);

        // New Request (POST /requests): drafts per user, and per IP.
        RateLimiter::for('create-request', fn (Request $request) => [
            Limit::perMinutes(self::cfg('create_request.decay_minutes'), self::cfg('create_request.per_user'))
                ->by('create-request|'.($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip())
                ->response(self::respond('request')),
            Limit::perMinutes(self::cfg('create_request.decay_minutes'), self::cfg('create_request.per_ip'))
                ->by('create-request-ip|'.$request->ip())
                ->response(self::respond('request')),
        ]);

        // Document uploads (one request per file).
        RateLimiter::for('uploads', fn (Request $request) => [
            Limit::perMinutes(self::cfg('uploads.decay_minutes'), self::cfg('uploads.per_user'))
                ->by('uploads|'.($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip())
                ->response(self::respond('file')),
            Limit::perMinutes(self::cfg('uploads.decay_minutes'), self::cfg('uploads.per_ip'))
                ->by('uploads-ip|'.$request->ip())
                ->response(self::respond('file')),
        ]);
    }

    private static function cfg(string $key): int
    {
        return (int) config('security.rate_limits.'.$key);
    }

    /** "Too many attempts. Try again in 15 minutes." from Retry-After (seconds). */
    public static function message(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return $minutes === 1
            ? 'Too many attempts. Try again in 1 minute.'
            : "Too many attempts. Try again in {$minutes} minutes.";
    }

    /** @return callable(Request, array<string, mixed>): Response */
    private static function respond(string $errorKey, bool $lockout = false): callable
    {
        return function (Request $request, array $headers) use ($errorKey, $lockout): Response {
            if ($lockout) {
                // AuthEventAuditor records it as auth.rate_limited.
                event(new Lockout($request));
            }

            $message = self::message((int) ($headers['Retry-After'] ?? 60));

            if ($request->header('X-Inertia')) {
                return back()->withErrors([$errorKey => $message])->with('error', $message)->withHeaders($headers);
            }

            return $request->expectsJson()
                ? response()->json(['message' => $message], 429, $headers)
                : response($message, 429, $headers);
        };
    }

    private static function email(Request $request): string
    {
        return Str::transliterate(Str::lower(trim((string) $request->input('email'))));
    }

    private static function routeKey(Request $request, string $parameter): string
    {
        $value = $request->route($parameter);

        return (string) (is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : $value);
    }
}
