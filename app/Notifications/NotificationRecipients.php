<?php

namespace App\Notifications;

use App\Enums\OrganisationType;
use App\Enums\Permission;
use App\Enums\UserType;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * NOTIF-1: who gets each email, resolved by permission name (never a role
 * name), active accounts only, each person once.
 *
 * - intake: holders of case.intake (staff)
 * - cmo: holders of case.route_to_review, the one permission only the CMO has
 * - psOffice: holders of nonobjection.grant
 * - admins: holders of users.manage (the admin permission, ADMIN-1)
 * - vendorApplicant: the vendor user who created the case, while still in its organisation
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

    /** @return Collection<int, User> */
    public function vendorApplicant(ProcurementCase $case): Collection
    {
        return User::query()
            ->whereKey($case->created_by)
            ->where('status', User::STATUS_ACTIVE)
            ->where('user_type', UserType::Vendor->value)
            ->where('org_id', $case->org_id)
            ->whereHas('organisation', fn ($q) => $q->where('type', OrganisationType::Vendor->value))
            ->get();
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
