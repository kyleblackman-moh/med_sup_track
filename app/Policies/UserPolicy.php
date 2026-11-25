<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserType;
use App\Models\User;

/**
 * ADMIN-1, IT-ADMIN: who may use Admin > Users. Permissions only, never role
 * names, and no bypass for any role.
 *
 * - The console (list, detail, role, disable, enable, resend) needs
 *   admin.access plus the action's own permission. Without admin.access the
 *   console does not exist for you: EnsureAdminConsole answers 404 first.
 * - Creating an account is outside the console so the CMO can invite vendor
 *   users: a staff role needs users.create, a vendor role users.invite_vendor.
 *   system_admin is never granted here (app:user-grant-admin only).
 * - Self-guard: nobody changes their own role or disables themselves (and
 *   MfaReset refuses your own MFA). The last-holder guard (no active account
 *   left with admin.access) is in UserAdministration, since it depends on
 *   other accounts.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::AdminAccess->value) && $actor->can(Permission::UsersView->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    /** The create form: at least one kind of account you may create. */
    public function create(User $actor): bool
    {
        return $this->createStaff($actor) || $this->inviteVendor($actor);
    }

    public function createStaff(User $actor): bool
    {
        return $actor->can(Permission::UsersCreate->value);
    }

    public function inviteVendor(User $actor): bool
    {
        return $actor->can(Permission::UsersInviteVendor->value);
    }

    /** May $actor create an account with this role? Never system_admin. */
    public static function mayGrant(User $actor, Role $role): bool
    {
        if ($role === Role::SystemAdmin) {
            return false;
        }

        $policy = new self;

        return $role->userType() === UserType::Vendor ? $policy->inviteVendor($actor) : $policy->createStaff($actor);
    }

    /** Backlog, 2026-11-25: job title and division (the letter's sign-off). Same permission as the role; yourself included. */
    public function updateDetails(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && $actor->can(Permission::UsersUpdateRole->value);
    }

    /** Never your own role.
    public function changeRole(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && $actor->can(Permission::UsersUpdateRole->value) && ! $actor->is($user);
    }

    /** Never yourself. */
    public function disable(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && $actor->can(Permission::UsersDisable->value) && ! $actor->is($user);
    }

    public function enable(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && $actor->can(Permission::UsersDisable->value) && ! $actor->is($user);
    }

    /** The same permission that may create that kind of account. */
    public function resendActivation(User $actor, User $user): bool
    {
        return $this->viewAny($actor)
            && ($user->isVendor() ? $this->inviteVendor($actor) : $this->createStaff($actor));
    }
}
