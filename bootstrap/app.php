<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureMfaEnrolled;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionTimeout;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // SEC-1: security headers on every response, redirects and errors included.
        $middleware->append(SecurityHeaders::class);

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
        //
    })->create();
