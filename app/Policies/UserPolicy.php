<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * ADMIN-1: who may use Admin > Users. Permissions only, never role names.
 * users.manage opens the area; creating also needs user.create and resending
 * the sign-in link user.reset_credentials. A denial is 403 (a missing
 * permission); every user is in scope for a holder, so there is no 404 case.
 *
 * Self-guard (Step 4): nobody changes their own role or disables themselves.
 * The last-holder guard (no active account left with users.manage) is a
 * business rule in UserAdministration, since it depends on other accounts.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersManage->value);
    }

    public function view(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor) && $actor->can(Permission::UserCreate->value);
    }

    /** Step 4: never your own role. */
    public function changeRole(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && ! $actor->is($user);
    }

    /** Step 4: never yourself. */
    public function disable(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && ! $actor->is($user);
    }

    public function enable(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function resendActivation(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && $actor->can(Permission::UserResetCredentials->value);
    }
}
