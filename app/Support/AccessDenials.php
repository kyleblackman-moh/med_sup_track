<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * IT-ADMIN: one access.denied audit row when a signed-in user is refused:
 * the route name (or "unnamed"), the method, the status sent (403, or 404 for
 * the admin console and the case area) and a fixed reason code. Never the
 * URL, query string or anything typed. Best-effort (Audit::recordBestEffort)
 * and capped per user a minute, so a scripted scan cannot flood the log.
 * Guests are not recorded here (sign-in failures are auth.login.failed).
 */
final class AccessDenials
{
    public static function record(Request $request, int $status, string $reason): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return;
        }

        $key = 'access-denied-log|'.$user->getAuthIdentifier();
        $max = max(1, (int) config('admin.audit.denied_log_per_minute', 30));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return;
        }
        RateLimiter::hit($key, 60);

        Audit::recordBestEffort(AuditAction::AccessDenied, null, [
            'route' => $request->route()?->getName() ?? 'unnamed',
            'method' => $request->method(),
            'status' => $status,
            'reason' => $reason,
        ], $user);
    }
}
