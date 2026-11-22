<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * IT-ADMIN: every request gets an id of its own (a UUID made here; an id sent
 * by the client is never trusted). Audit rows written during the request carry
 * it (audit_logs.request_id) and the response returns it as X-Request-Id, so a
 * report from a user can be matched to the audit trail and the logs.
 */
class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function of(?Request $request): ?string
    {
        $id = $request?->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}
