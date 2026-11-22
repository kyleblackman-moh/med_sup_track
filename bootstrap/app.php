<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ConfirmRecentPassword;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAdminConsole;
use App\Http\Middleware\EnsureCaseAreaAccess;
use App\Http\Middleware\EnsureMfaEnrolled;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionTimeout;
use App\Support\AccessDenials;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // IT-ADMIN: an id per request, carried by audit rows and the X-Request-Id header.
        $middleware->prepend(AssignRequestId::class);

        // SEC-1: security headers on every response, redirects and errors included.
        $middleware->append(SecurityHeaders::class);

        // IT-ADMIN: the admin console (404 without admin.access), recent password
        // confirmation for admin actions, and the case area (404 without case work).
        $middleware->alias([
            'admin.console' => EnsureAdminConsole::class,
            'admin.confirm' => ConfirmRecentPassword::class,
            'case.area' => EnsureCaseAreaAccess::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SessionTimeout::class, // SEC-1: idle and absolute session timeouts
            EnsureAccountActive::class, // ADMIN-1: a disabled account is signed out
            EnsurePasswordChanged::class,
            EnsureMfaEnrolled::class, // MFA-1: after the password change, before anything else
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // IT-ADMIN: every 403 sent to a signed-in user (a policy or permission
        // refusal, an abort(403)) is audited as access.denied (route name only,
        // capped; AccessDenials). Returning nothing keeps the normal response.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() === 403) {
                AccessDenials::record($request, 403, 'forbidden');
            }
        });
    })->create();
