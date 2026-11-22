<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccessDenials;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IT-ADMIN: the case area (requests, cases, documents, queries, analytics)
 * does not exist for an account that holds no case-work permission at all
 * (none of the prefixes below): the System Admin, and the Auditor while it has
 * no permissions. They get 404 on every route of the area, lists included, as
 * for a case out of scope. Decided by permissions, never by role name.
 *
 * Accounts with some case work keep the existing answers (a missing
 * permission 403, an out-of-scope case 404).
 */
class EnsureCaseAreaAccess
{
    /** Prefixes of the permissions that are case work (App\Enums\Permission). */
    public const CASE_WORK = [
        'case.', 'document.', 'query.', 'hardcopy.', 'technical.', 'funding.',
        'file.', 'legal.', 'nonobjection.', 'letter.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! self::hasCaseWork($user)) {
            AccessDenials::record($request, 404, 'no_case_work');
            abort(404);
        }

        return $next($request);
    }

    public static function hasCaseWork(User $user): bool
    {
        return $user->getAllPermissions()->contains(function ($permission) {
            foreach (self::CASE_WORK as $prefix) {
                if (str_starts_with($permission->name, $prefix)) {
                    return true;
                }
            }

            return false;
        });
    }
}
