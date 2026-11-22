<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
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
            // MFA (Fortify). Secrets, TOTP codes and recovery codes never reach the payload.
            TwoFactorAuthenticationConfirmed::class => 'onMfaEnabled',
            TwoFactorAuthenticationDisabled::class => 'onMfaDisabled',
            RecoveryCodesGenerated::class => 'onMfaRecoveryCodesRegenerated',
            RecoveryCodeReplaced::class => 'onMfaRecoveryCodeUsed',
            TwoFactorAuthenticationFailed::class => 'onMfaChallengeFailed',
        ];
    }

    /** The authenticator was confirmed: MFA is now on for the account. */
    public function onMfaEnabled(TwoFactorAuthenticationConfirmed $event): void
    {
        Audit::recordAuthEvent(AuditAction::MfaEnabled, [], $this->userOrAnonymous($event->user));
    }

    public function onMfaDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        Audit::recordAuthEvent(AuditAction::MfaDisabled, [], $this->userOrAnonymous($event->user));
    }

    /**
     * Fortify fires RecoveryCodesGenerated only from GenerateNewRecoveryCodes
     * (Settings > Security). The first set, made at setup, is not a regeneration.
     */
    public function onMfaRecoveryCodesRegenerated(RecoveryCodesGenerated $event): void
    {
        Audit::recordAuthEvent(AuditAction::MfaRecoveryCodesRegenerated, [], $this->userOrAnonymous($event->user));
    }

    /** A recovery code signed the user in (and was replaced). The code itself is never stored. */
    public function onMfaRecoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        Audit::recordAuthEvent(AuditAction::MfaRecoveryCodeUsed, [], $this->userOrAnonymous($event->user));
    }

    /** A wrong TOTP or recovery code at the sign-in challenge. Which kind was tried, never the value. */
    public function onMfaChallengeFailed(TwoFactorAuthenticationFailed $event): void
    {
        $request = request();

        Audit::recordAuthEvent(
            AuditAction::MfaChallengeFailed,
            ['method' => $request->filled('recovery_code') ? 'recovery' : 'authenticator'],
            $this->userOrAnonymous($event->user),
        );
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
