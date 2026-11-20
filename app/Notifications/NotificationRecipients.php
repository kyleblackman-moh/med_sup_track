<?php

namespace App\Notifications;

use App\Enums\OrganisationType;
use App\Enums\Permission;
use App\Enums\UserType;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * NOTIF-1: who gets each email, resolved by permission name (never a role
 * name), active accounts only, each person once.
 *
 * - intake: holders of case.intake (staff)
 * - cmo: holders of case.route_to_review, the one permission only the CMO has
 * - psOffice: holders of nonobjection.grant
 * - admins: holders of users.manage (the admin permission, ADMIN-1)
 * - vendorUsers: active vendor users of the case's organisation holding case.submit
 */
final class NotificationRecipients
{
    /** @return Collection<int, User> */
    public function intake(): Collection
    {
        return $this->staffWith(Permission::CaseIntake);
    }

    /** @return Collection<int, User> */
    public function cmo(): Collection
    {
        return $this->staffWith(Permission::CaseRouteToReview);
    }

    /** @return Collection<int, User> */
    public function psOffice(): Collection
    {
        return $this->staffWith(Permission::NonObjectionGrant);
    }

    /** @return Collection<int, User> */
    public function admins(): Collection
    {
        return $this->staffWith(Permission::UsersManage);
    }

    /**
     * FIX-2 (1): the vendor side of a case: every active vendor user of the
     * case's organisation who holds case.submit, each once (not only the user
     * who created the case).
     *
     * @return Collection<int, User>
     */
    public function vendorUsers(ProcurementCase $case): Collection
    {
        return User::permission(Permission::CaseSubmit->value)
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('users.user_type', UserType::Vendor->value)
            ->where('users.org_id', $case->org_id)
            ->whereHas('organisation', fn ($q) => $q->where('type', OrganisationType::Vendor->value))
            ->get()
            ->unique(fn (User $u) => $u->getKey())
            ->values();
    }

    /** OVERNIGHT-2 (D4): the CMO's desk for queries: holders of query.forward. @return Collection<int, User> */
    public function queryDesk(): Collection
    {
        return $this->staffWith(Permission::QueryForward);
    }

    /**
     * OVERNIGHT-2 (D4): active staff holding $role (a role name read from the
     * query's route data, never written in code) who can see $case.
     *
     * @return Collection<int, User>
     */
    public function roleOnCase(string $role, ProcurementCase $case): Collection
    {
        return User::role($role)
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('users.user_type', UserType::Staff->value)
            ->get()
            ->filter(fn (User $u) => Gate::forUser($u)->allows('view', $case))
            ->unique(fn (User $u) => $u->getKey())
            ->values();
    }

    /** @return Collection<int, User> */
    public function user(?int $id): Collection
    {
        return $id === null ? collect() : User::query()->whereKey($id)->where('status', User::STATUS_ACTIVE)->get();
    }

    /**
     * Several groups as one list, each person once.
     *
     * @param  Collection<int, User>  ...$groups
     * @return Collection<int, User>
     */
    public static function merge(Collection ...$groups): Collection
    {
        return collect($groups)->flatten(1)->unique(fn (User $u) => $u->getKey())->values();
    }

    /** @return Collection<int, User> */
    private function staffWith(Permission $permission): Collection
    {
        return User::permission($permission->value)
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('users.user_type', UserType::Staff->value)
            ->get()
            ->unique(fn (User $u) => $u->getKey())
            ->values();
    }
}
