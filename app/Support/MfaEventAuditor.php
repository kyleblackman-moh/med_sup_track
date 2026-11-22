<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\User;
use App\Notifications\MfaSecurityNotice;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * MFA-1: audit rows for the two-factor challenge (Fortify's events). Registered
 * once in AppServiceProvider, outside app/Listeners (as AuthEventAuditor), so
 * it is never auto-discovered twice. Never the code itself.
 */
class MfaEventAuditor
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            TwoFactorAuthenticationFailed::class => 'onChallengeFailed',
            RecoveryCodeReplaced::class => 'onRecoveryCodeUsed',
        ];
    }

    public function onChallengeFailed(TwoFactorAuthenticationFailed $event): void
    {
        Audit::recordAuthEvent(AuditAction::MfaChallengeFailed, [], $event->user instanceof User ? $event->user : Audit::ACTOR_ANONYMOUS);
    }

    public function onRecoveryCodeUsed(RecoveryCodeReplaced $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        Audit::recordAuthEvent(AuditAction::MfaRecoveryUsed, [
            'remaining' => count($event->user->recoveryCodes()),
        ], $event->user);

        $event->user->notify(new MfaSecurityNotice($event->user->id, MfaSecurityNotice::RECOVERY_USED));
    }
}
