<?php

namespace App\Support;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
 * The account lock (AuthenticateUser: 5 wrong passwords lock that account for
 * 15 minutes) is separate and stays as it is.
 */
final class RateLimits
{
    public const LOGIN_ATTEMPTS = 5;

    public const DECAY_MINUTES = 15;

    public static function register(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinutes(self::DECAY_MINUTES, self::LOGIN_ATTEMPTS)
                ->by('login|'.self::email($request).'|'.$request->ip())
                ->response(self::respond('email', lockout: true)),
            Limit::perMinutes(self::DECAY_MINUTES, 30)
                ->by('login-ip|'.$request->ip())
                ->response(self::respond('email', lockout: true)),
        ]);

        // Asking for a reset link, and using one (which is also how a new account is activated).
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinutes(self::DECAY_MINUTES, 3)
                ->by('reset|'.$request->route()?->getName().'|'.self::email($request).'|'.$request->ip())
                ->response(self::respond('email')),
            Limit::perMinutes(self::DECAY_MINUTES, 10)
                ->by('reset-ip|'.$request->ip())
                ->response(self::respond('email')),
        ]);

        // An admin resending a sign-in link (ADMIN-1): per account, and per admin.
        RateLimiter::for('activation', fn (Request $request) => [
            Limit::perMinutes(self::DECAY_MINUTES, 3)
                ->by('activation|'.self::routeKey($request, 'user').'|'.$request->ip())
                ->response(self::respond('account')),
            Limit::perMinutes(self::DECAY_MINUTES, 20)
                ->by('activation-admin|'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
                ->response(self::respond('account')),
        ]);

        // New Request (POST /requests): drafts per user, and per IP.
        RateLimiter::for('create-request', fn (Request $request) => [
            Limit::perMinutes(10, 20)
                ->by('create-request|'.($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip())
                ->response(self::respond('request')),
            Limit::perMinutes(10, 60)
                ->by('create-request-ip|'.$request->ip())
                ->response(self::respond('request')),
        ]);

        // Document uploads (one request per file).
        RateLimiter::for('uploads', fn (Request $request) => [
            Limit::perMinute(60)
                ->by('uploads|'.($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip())
                ->response(self::respond('file')),
            Limit::perMinute(120)
                ->by('uploads-ip|'.$request->ip())
                ->response(self::respond('file')),
        ]);
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
