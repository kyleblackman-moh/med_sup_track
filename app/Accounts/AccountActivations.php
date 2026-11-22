<?php

namespace App\Accounts;

use App\Enums\AuditAction;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\AccountActivation;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * IT-ADMIN (spec 8.2): activation links for accounts an administrator (or the
 * CMO, for vendor users) created. The account exists with status "invited"
 * and a random password nobody knows; the person opens a single-use, signed
 * link that expires after admin.activation.expire_hours (48), chooses a
 * password, and then enrols MFA at their first sign-in (EnsureMfaEnrolled).
 *
 * - Only sha256(token) is stored (user_invitations.token_hash).
 * - Issuing a link revokes every earlier open link of that account.
 * - Accepting marks the link accepted under a row lock, so it works once.
 */
final class AccountActivations
{
    public const TOKEN_LENGTH = 64;

    /**
     * A new link for a pending account; earlier open links stop working.
     * Call inside the caller's transaction.
     *
     * @return string the signed URL to email
     */
    public function issue(User $user, ?User $actor): string
    {
        $now = CarbonImmutable::now('UTC');

        UserInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'revoked_by' => $actor?->id, 'updated_at' => $now]);

        $token = Str::random(self::TOKEN_LENGTH);
        $expires = $now->addHours(self::expireHours());

        $invitation = new UserInvitation;
        $invitation->forceFill([
            'user_id' => $user->id,
            'email' => $user->email,
            'org_id' => $user->org_id,
            'role' => (string) $user->getRoleNames()->first(),
            'token_hash' => UserInvitation::hashToken($token),
            'invited_by' => $actor?->id ?? $user->invited_by ?? $user->id,
            'expires_at' => $expires,
        ])->save();

        return URL::temporarySignedRoute('activation.show', $expires, [
            'invitation' => $invitation->id,
            'token' => $token,
        ]);
    }

    /**
     * Issue a link and email it. False when the mail could not be sent; the
     * error is logged (and audited as mail.send_failed), never shown.
     */
    public function send(User $user, ?User $actor): bool
    {
        $url = $this->issue($user, $actor);

        try {
            $user->notify(new AccountActivation($url, self::expireHours()));

            return true;
        } catch (Throwable $e) {
            Log::error('Account activation email failed', ['user_id' => $user->id, 'exception' => $e::class]);
            Audit::recordBestEffort(AuditAction::MailSendFailed, ['type' => 'user', 'id' => $user->id], [
                'notification' => 'account_activation',
                'exception' => $e::class,
            ], Audit::ACTOR_SYSTEM);

            return false;
        }
    }

    /** The open link for this id and token, or null (unknown, wrong token, used, revoked or expired). */
    public function find(string $invitationId, string $token): ?UserInvitation
    {
        $invitation = UserInvitation::query()->whereKey($invitationId)->first();

        if ($invitation === null || ! hash_equals($invitation->token_hash, UserInvitation::hashToken($token))) {
            return null;
        }

        return $invitation->isOpen() && $invitation->user?->status === User::STATUS_INVITED ? $invitation : null;
    }

    /**
     * Set the password and activate the account; the link is used up.
     *
     * @return User|null null when the link is no longer open (used in the meantime)
     */
    public function accept(string $invitationId, string $token, string $password): ?User
    {
        return DB::transaction(function () use ($invitationId, $token, $password) {
            $invitation = UserInvitation::query()->whereKey($invitationId)->lockForUpdate()->first();

            if ($invitation === null
                || ! hash_equals($invitation->token_hash, UserInvitation::hashToken($token))
                || ! $invitation->isOpen()) {
                return null;
            }

            $user = User::query()->whereKey($invitation->user_id)->lockForUpdate()->first();
            if ($user === null || $user->status !== User::STATUS_INVITED) {
                return null;
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'status' => User::STATUS_ACTIVE,
                'remember_token' => Str::random(60),
                'password_change_required' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $invitation->forceFill([
                'accepted_at' => CarbonImmutable::now('UTC'),
                'accepted_user_id' => $user->id,
            ])->save();

            Audit::record(AuditAction::UserActivated, ['type' => 'user', 'id' => $user->id], null, [
                'user_id' => $user->id,
                'invitation_id' => $invitation->id,
            ], $user);

            return $user;
        });
    }

    /** Revoke every open link of the account (it was disabled). Call inside the caller's transaction. */
    public function revokeAll(User $user, ?User $actor): int
    {
        $now = CarbonImmutable::now('UTC');

        return UserInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'revoked_by' => $actor?->id, 'updated_at' => $now]);
    }

    /** True when the account was activated through a link at some point. */
    public static function wasActivated(User $user): bool
    {
        return UserInvitation::query()->where('user_id', $user->id)->whereNotNull('accepted_at')->exists();
    }

    public static function expireHours(): int
    {
        return max(1, (int) config('admin.activation.expire_hours', 48));
    }
}
