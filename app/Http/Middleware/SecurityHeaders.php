<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-1 (B3): security headers on every response (pages, redirects, errors,
 * downloads). The Content-Security-Policy is Report-Only for now
 * (config security.headers.csp_report_only). HSTS only over HTTPS.
 *
 * script-src keeps 'unsafe-inline' because the page carries Ziggy's inline
 * route list (@routes); a nonce would let the policy drop it before it is
 * enforced. In local development the Vite dev server (public/hot) is allowed.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set(
            config('security.headers.csp_report_only', true) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
            self::contentSecurityPolicy(),
        );

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    public static function contentSecurityPolicy(): string
    {
        $dev = self::viteDevOrigin();
        $devHttp = $dev === null ? '' : ' '.$dev;
        $devWs = $dev === null ? '' : ' '.preg_replace('/^http/', 'ws', $dev);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'".$devHttp,
            "style-src 'self' 'unsafe-inline'".$devHttp,
            "img-src 'self' data:".$devHttp,
            "font-src 'self' data:".$devHttp,
            "connect-src 'self'".$devHttp.$devWs,
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    /** The Vite dev server's origin in local development (public/hot), else null. */
    private static function viteDevOrigin(): ?string
    {
        if (! app()->isLocal() || ! is_file(public_path('hot'))) {
            return null;
        }

        $url = trim((string) file_get_contents(public_path('hot')));

        return preg_match('#^https?://[^/\s]+$#', $url) === 1 ? $url : null;
    }
}
