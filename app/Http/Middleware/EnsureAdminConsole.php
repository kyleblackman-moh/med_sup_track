<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Support\AccessDenials;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IT-ADMIN: the IT Admin console (/admin) exists only for holders of
 * admin.access. Everyone else, vendors and other staff, gets 404 (Kyle's
 * decision: the console is not advertised), and the refusal is audited as
 * access.denied. Inside the console each action still checks its own
 * permission (UserPolicy, the controllers).
 *
 * With a permission parameter (admin.console:system.health.view) that
 * permission is required as well, also answered with 404.
 */
class EnsureAdminConsole
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();

        $allowed = $user !== null
            && $user->can(Permission::AdminAccess->value)
            && ($permission === null || $user->can($permission));

        if (! $allowed) {
            AccessDenials::record($request, 404, 'admin_console');
            abort(404);
        }

        return $next($request);
    }
}
