<?php

namespace App\Workflow;

use App\Enums\ReviewDesk;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may be sent a case for review at a desk: active staff users holding the
 * desk's permission (directly or through a role). Checked by permission, never
 * by role name.
 *
 * Staff-only data: exposed only on the send-for-review form.
 */
final class EligibleReviewers
{
    /** @return list<array{id: int, name: string}> */
    public function for(ReviewDesk $desk): array
    {
        return $this->query($desk)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()->all();
    }

    /**
     * The eligible users among $ids, keyed by id.
     *
     * @param  list<int>  $ids
     * @return array<int, User>
     */
    public function among(ReviewDesk $desk, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->query($desk)->whereIn('users.id', $ids)->get(['users.id', 'users.name'])
            ->keyBy(fn (User $u) => (int) $u->id)
            ->all();
    }

    private function query(ReviewDesk $desk): Builder
    {
        return User::query()
            ->permission($desk->permission()->value)
            ->where('users.user_type', UserType::Staff->value)
            ->where('users.status', User::STATUS_ACTIVE);
    }
}
