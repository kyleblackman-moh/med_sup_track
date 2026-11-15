<?php

namespace App\Workflow;

use App\Enums\Permission;
use App\Enums\ReviewDesk;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may be assigned a case at a desk: active staff users holding the desk's
 * permission (directly or through a role). Checked by permission, never by
 * role name.
 *
 * - Review desks (slice 10a): technical.consider / funding.confirm.
 * - Legal (slice 10c): legal.confirm.
 *
 * Staff-only data: exposed only on the send-for-review and send-to-Legal forms.
 */
final class EligibleReviewers
{
    /** @return list<array{id: int, name: string}> */
    public function for(ReviewDesk $desk): array
    {
        return $this->forPermission($desk->permission());
    }

    /**
     * The eligible users among $ids, keyed by id.
     *
     * @param  list<int>  $ids
     * @return array<int, User>
     */
    public function among(ReviewDesk $desk, array $ids): array
    {
        return $this->amongPermission($desk->permission(), $ids);
    }

    /** @return list<array{id: int, name: string}> */
    public function legal(): array
    {
        return $this->forPermission(Permission::LegalConfirm);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, User>
     */
    public function legalAmong(array $ids): array
    {
        return $this->amongPermission(Permission::LegalConfirm, $ids);
    }

    /** @return list<array{id: int, name: string}> */
    private function forPermission(Permission $permission): array
    {
        return $this->query($permission)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, User>
     */
    private function amongPermission(Permission $permission, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->query($permission)->whereIn('users.id', $ids)->get(['users.id', 'users.name'])
            ->keyBy(fn (User $u) => (int) $u->id)
            ->all();
    }

    private function query(Permission $permission): Builder
    {
        return User::query()
            ->permission($permission->value)
            ->where('users.user_type', UserType::Staff->value)
            ->where('users.status', User::STATUS_ACTIVE);
    }
}
