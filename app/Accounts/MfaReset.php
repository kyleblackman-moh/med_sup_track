<?php

namespace App\Accounts;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AdminActionReason;
use App\Models\User;
use App\Notifications\MfaSecurityNotice;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * MFA-1: an administrator clears a user's authenticator so they enrol again
 * at their next sign-in (a lost phone). In one transaction: the three
 * two_factor_* columns cleared, every session of the user ended
 * (SessionRevoker), one mfa.reset_by_admin audit row with the actor (and
 * reason_id when a reason was given). Then a security email to the user
 * (after commit).
 *
 * Who: users.mfa.reset. A holder without admin.access (the CMO) may reset
 * vendor users only. Never yourself: users cannot remove their own MFA.
 */
final class MfaReset
{
    public static function allows(User $actor, User $user): bool
    {
        return $actor->can(Permission::UsersMfaReset->value)
            && ! $actor->is($user)
            && ($user->isVendor() || $actor->can(Permission::AdminAccess->value));
    }

    /** @throws AuthorizationException */
    public function handle(User $actor, User $user, ?string $reason = null): void
    {
        if (! self::allows($actor, $user)) {
            throw new AuthorizationException('You cannot reset multi-factor authentication for this user.');
        }

        DB::transaction(function () use ($actor, $user, $reason) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $ended = SessionRevoker::revoke($user);

            Audit::record(AuditAction::MfaResetByAdmin, ['type' => 'user', 'id' => $user->id], null, [
                'user_id' => $user->id,
                'sessions_ended' => $ended,
                ...AdminActionReason::store(AuditAction::MfaResetByAdmin->value, $actor, $user, $reason),
            ], $actor);

            $user->notify(new MfaSecurityNotice($user->id, MfaSecurityNotice::RESET_BY_ADMIN));
        });
    }
}
