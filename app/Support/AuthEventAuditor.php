<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Fortify;

/**
 * Records Laravel's auth events in audit_logs (best-effort; see Audit::recordAuthEvent).
 *
 * Registered once in AppServiceProvider. It deliberately lives outside
 * app/Listeners, which Laravel auto-discovers, so it can't be registered twice.
 *
 * Failed is NOT handled here: App\Actions\Fortify\AuthenticateUser records each
 * failure with its reason, so listening to Failed would duplicate those rows.
 */
class AuthEventAuditor
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Lockout::class => 'onRateLimited',
            PasswordReset::class => 'onPasswordReset',
        ];
    }

    public function onLogin(Login $event): void
    {
        Audit::recordAuthEvent(
            AuditAction::LoginSuccess,
            [],
            $this->userOrAnonymous($event->user),
        );
    }

    public function onLogout(Logout $event): void
    {
        Audit::recordAuthEvent(AuditAction::Logout, [], $this->userOrAnonymous($event->user));
    }

    /** Fired by the "login" rate limiter (FortifyServiceProvider). */
    public function onRateLimited(Lockout $event): void
    {
        Audit::recordAuthEvent(
            AuditAction::RateLimited,
            ['attempted_email' => Audit::safeEmail($event->request->input(Fortify::username()))],
            Audit::ACTOR_ANONYMOUS,
        );
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        Audit::recordAuthEvent(AuditAction::PasswordReset, [], $this->userOrAnonymous($event->user));
    }

    private function userOrAnonymous(mixed $user): User|string
    {
        return $user instanceof User ? $user : Audit::ACTOR_ANONYMOUS;
    }
}
