<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * ADMIN-1: who may use Admin > Users. Permissions only, never role names.
 * users.manage opens the area; creating also needs user.create and resending
 * the sign-in link user.reset_credentials. A denial is 403 (a missing
 * permission); every user is in scope for a holder, so there is no 404 case.
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

    public function changeRole(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
    }

    public function disable(User $actor, User $user): bool
    {
        return $this->viewAny($actor);
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
