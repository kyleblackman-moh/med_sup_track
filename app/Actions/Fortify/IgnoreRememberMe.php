<?php

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Http\Request;

/**
 * FIX-1 (A2): there is no "Remember me". A posted "remember" field is dropped
 * before Fortify signs the user in, so no long-lived remember-me cookie is ever
 * issued and every session is bound by the idle and absolute timeouts.
 */
class IgnoreRememberMe
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->offsetUnset('remember');
        $request->query->remove('remember');

        return $next($request);
    }
}
