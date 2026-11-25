<?php

namespace App\Accounts;

use App\Enums\AuditAction;
use App\Enums\OrganisationType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserType;
use App\Models\AdminActionReason;
use App\Models\Organisation;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\AccountSecurityNotice;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * ADMIN-1, IT-ADMIN: what Admin > Users changes. Callers have already
 * authorised (UserPolicy). Audit rows carry ids, fixed codes and, for a typed
 * reason, reason_id and reason_length only (the text is in
 * admin_action_reasons), never a name, email or anything typed.
 *
 * - system_admin is never given here: the command line only (app:user-grant-admin).
 * - Role and organisation pairing: a vendor role belongs to an active VENDOR
 *   organisation (required); every other role to the single MOH organisation.
 * - The last active holder of admin.access is never disabled or demoted.
 */
final class UserAdministration
{
    public const PASSWORD_LENGTH = 32;

    private const GUARD = 'web';

    public function __construct(private readonly AccountActivations $activations) {}

    /**
     * Create a pending account (status "invited", a random password nobody
     * sees) and email the person a single-use activation link.
     *
     * @return array{0: User, 1: bool} the user, and whether the email went out
     *
     * @throws ValidationException
     */
    public function create(User $actor, string $name, string $email, Role $role, ?string $organisationId, ?string $jobTitle = null, ?string $division = null): array
    {
        self::refuseSystemAdmin($role);
        $organisation = $this->organisationFor($role, $organisationId);

        $user = DB::transaction(function () use ($actor, $name, $email, $role, $organisation, $jobTitle, $division) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'job_title' => $jobTitle,
                'division' => $division,
                // Unknown to everyone: the person sets their own through the emailed link.
                'password' => Hash::make(Str::password(self::PASSWORD_LENGTH)),
                'org_id' => $organisation->id,
                'user_type' => $role->userType()->value,
                'auth_source' => 'local',
                'status' => User::STATUS_INVITED,
                'failed_login_attempts' => 0,
                'password_change_required' => false,
                'invited_by' => $actor->id,
            ])->save();

            $user->assignRole($role->value);

            Audit::record(AuditAction::UserCreated, ['type' => 'user', 'id' => $user->id], null, [
                'user_id' => $user->id,
                'role' => $role->value,
                'organisation_id' => $organisation->id,
                'created_via' => 'admin',
            ], $actor);

            return $user;
        });

        return [$user, $this->activations->send($user, $actor)];
    }

    /**
     * Backlog, 2026-11-25: the job title and division printed under the name
     * on the request letter. The audit holds which changed, never the text.
     * Nothing happens when neither changed.
     */
    public function updateDetails(User $actor, User $user, ?string $jobTitle, ?string $division): void
    {
        $changed = [
            'job_title_changed' => $user->job_title !== $jobTitle,
            'division_changed' => $user->division !== $division,
        ];
        if (! in_array(true, $changed, true)) {
            return;
        }

        DB::transaction(function () use ($actor, $user, $jobTitle, $division, $changed) {
            $user->forceFill(['job_title' => $jobTitle, 'division' => $division])->save();

            Audit::record(AuditAction::UserDetailsChanged, ['type' => 'user', 'id' => $user->id], null, [
                'user_id' => $user->id,
                ...$changed,
            ], $actor);
        });
    }

    /**
     * Give the user another role of the same kind (vendor or staff); the
     * organisation is not changed. Their sessions end at once, so the new
     * permissions apply from their next sign-in, and they get a security email.
     * Nothing happens when the role is the one they have.
     *
     * @throws ValidationException
     */
    public function changeRole(User $actor, User $user, Role $role, string $reason): void
    {
        self::refuseSystemAdmin($role);

        if ($role->userType()->value !== $user->user_type) {
            throw ValidationException::withMessages(['role' => $user->isVendor()
                ? 'A vendor account can only have a vendor role.'
                : 'A staff account can only have a staff role.']);
        }

        if ($user->getRoleNames()->all() === [$role->value]) {
            return;
        }

        DB::transaction(function () use ($actor, $user, $role, $reason) {
            if (! in_array(Permission::AdminAccess, $role->permissions(), true)) {
                $this->guardLastAdmin($user, 'role', 'This is the last active account with access to the admin console. Give another account that access first.');
            }

            $old = $user->roles()->get(['roles.id', 'roles.name']);
            $new = SpatieRole::findByName($role->value, self::GUARD);

            $user->syncRoles([$role->value]);
            $ended = SessionRevoker::revoke($user);

            Audit::record(AuditAction::UserRoleChanged, ['type' => 'user', 'id' => $user->id], null, [
                'user_id' => $user->id,
                // A user holds one role in practice; a list in case older data holds more.
                'old_role_ids' => $old->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'old_roles' => $old->pluck('name')->values()->all(),
                'new_role_id' => (int) $new->id,
                'new_role' => $role->value,
                'sessions_ended' => $ended,
                ...AdminActionReason::store(AuditAction::UserRoleChanged->value, $actor, $user, $reason),
            ], $actor);

            $user->notify(new AccountSecurityNotice($user->id, AccountSecurityNotice::ROLE_CHANGED, $role->value));
        });
    }

    /**
     * Block sign-in at once: status disabled, remember-me token replaced,
     * every session row deleted, open activation links revoked.
     * EnsureAccountActive also signs a disabled user out on their next request.
     */
    public function disable(User $actor, User $user, string $reason): void
    {
        if ($user->status === User::STATUS_DISABLED) {
            return;
        }

        DB::transaction(function () use ($actor, $user, $reason) {
            $this->guardLastAdmin($user, 'account', 'This is the last active account with access to the admin console, so it cannot be disabled.');

            $user->forceFill([
                'status' => User::STATUS_DISABLED,
                'disabled_at' => now(),
                'disabled_by' => $actor->id,
            ])->save();

            $ended = SessionRevoker::revoke($user);
            $revoked = $this->activations->revokeAll($user, $actor);

            Audit::record(AuditAction::UserDisabled, ['type' => 'user', 'id' => $user->id], null, [
                'user_id' => $user->id,
                'sessions_ended' => $ended,
                'activation_links_revoked' => $revoked,
                ...AdminActionReason::store(AuditAction::UserDisabled->value, $actor, $user, $reason),
            ], $actor);
        });
    }

    /**
     * Let a disabled user sign in again (their password is unchanged). An
     * account that never activated goes back to pending activation and needs a
     * new link (Resend Activation).
     */
    public function enable(User $actor, User $user, string $reason): void
    {
        if ($user->status !== User::STATUS_DISABLED) {
            return;
        }

        DB::transaction(function () use ($actor, $user, $reason) {
            $status = $this->neverActivated($user) ? User::STATUS_INVITED : User::STATUS_ACTIVE;

            $user->forceFill([
                'status' => $status,
                'disabled_at' => null,
                'disabled_by' => null,
            ])->save();

            Audit::record(AuditAction::UserEnabled, ['type' => 'user', 'id' => $user->id], null, [
                'user_id' => $user->id,
                'status' => $status,
                ...AdminActionReason::store(AuditAction::UserEnabled->value, $actor, $user, $reason),
            ], $actor);
        });
    }

    /**
     * Email a new activation link to a pending account; the earlier link stops working.
     *
     * @return bool whether the email went out
     *
     * @throws ValidationException when the account is not pending activation
     */
    public function resendActivation(User $actor, User $user): bool
    {
        if (! $user->isPendingActivation()) {
            throw ValidationException::withMessages(['account' => 'Only an account waiting for activation can be sent an activation link. An active user can use Forgot Password.']);
        }

        $sent = $this->activations->send($user, $actor);

        // Recorded whether or not the mail went out ("sent"), so a failed attempt is visible too.
        Audit::record(AuditAction::UserActivationResent, ['type' => 'user', 'id' => $user->id], null, [
            'user_id' => $user->id,
            'sent' => $sent,
        ], $actor);

        return $sent;
    }

    /** True when mail goes to the log or nowhere (MAIL_MAILER log or array), so nothing reaches an inbox. */
    public static function mailIsNotDelivered(): bool
    {
        return in_array(config('mail.default'), ['log', 'array'], true);
    }

    /** @throws ValidationException */
    public static function refuseSystemAdmin(Role $role): void
    {
        if ($role === Role::SystemAdmin) {
            throw ValidationException::withMessages(['role' => 'The System Admin role is given from the command line only.']);
        }
    }

    /**
     * An active account holding admin.access is never the last one to lose it
     * (by disabling or by a role without it). Checked by permission, not by
     * role name. The other holders' rows are read under an update lock, so two
     * admins cannot remove each other at the same moment.
     *
     * @throws ValidationException
     */
    private function guardLastAdmin(User $user, string $key, string $message): void
    {
        if (! $user->isActive() || ! $user->can(Permission::AdminAccess->value)) {
            return;
        }

        $others = User::permission(Permission::AdminAccess->value)
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }

    /** Created here (has activation links) and never used one. Console-made accounts count as activated. */
    private function neverActivated(User $user): bool
    {
        return UserInvitation::query()->where('user_id', $user->id)->exists()
            && ! AccountActivations::wasActivated($user);
    }

    /**
     * The organisation a new account with this role joins.
     *
     * @throws ValidationException when the pairing is wrong
     */
    private function organisationFor(Role $role, ?string $organisationId): Organisation
    {
        if ($role->userType() === UserType::Vendor) {
            $organisation = $organisationId === null ? null : Organisation::query()
                ->whereKey($organisationId)
                ->where('type', OrganisationType::Vendor->value)
                ->where('active', true)
                ->first();

            if ($organisation === null) {
                throw ValidationException::withMessages(['organisation' => 'Choose the vendor organisation this user belongs to.']);
            }

            return $organisation;
        }

        if ($organisationId !== null) {
            throw ValidationException::withMessages(['organisation' => 'Staff roles belong to the Ministry of Health. Leave the organisation empty.']);
        }

        $moh = Organisation::where('type', OrganisationType::Moh->value)->get();
        if ($moh->count() !== 1) {
            throw ValidationException::withMessages(['role' => 'The Ministry of Health organisation is not set up. Nothing was changed.']);
        }

        return $moh->first();
    }
}
