<?php

namespace App\Notifications;

use App\Enums\CaseStatus;
use App\Enums\OrganisationType;
use App\Enums\Permission;
use App\Enums\UserType;
use App\Models\CaseAssignment;
use App\Models\CaseQuery;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\User;
use App\Queries\QueryAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * NOTIF-1, NOTIF-2: who gets each notification, resolved by permission or
 * assignment (never a role name written in code), active accounts only, each
 * person once.
 *
 * - intake: holders of case.intake (staff)
 * - cmo: holders of case.route_to_review, the one permission only the CMO has
 * - forStage: the active assignees holding a permission, else every holder
 * - forQueryMessage: from the query's route (query_routes data)
 * - admins: holders of users.manage (the admin permission, ADMIN-1)
 * - submitter: the vendor user who submitted the case
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
    public function admins(): Collection
    {
        return $this->staffWith(Permission::UsersManage);
    }

    /**
     * NOTIF-2: the vendor side of a case is the user who submitted it (the
     * actor of the latest DRAFT -> SUBMITTED transition; created_by before
     * submission), and only while that account is active, a vendor of the
     * case's own organisation and holds case.submit. Replaces FIX-2's "every
     * case.submit user of the organisation" (Kyle, 2026-11-21).
     *
     * @return Collection<int, User>
     */
    public function submitter(ProcurementCase $case): Collection
    {
        $id = CaseTransition::query()
            ->where('case_id', $case->getKey())
            ->where('to_status', CaseStatus::SUBMITTED->value)
            ->orderByDesc('id')
            ->value('actor_id') ?? $case->created_by;

        if ($id === null) {
            return collect();
        }

        return User::permission(Permission::CaseSubmit->value)
            ->whereKey($id)
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('users.user_type', UserType::Vendor->value)
            ->where('users.org_id', $case->org_id)
            ->whereHas('organisation', fn ($q) => $q->where('type', OrganisationType::Vendor->value))
            ->get()
            ->values();
    }

    /**
     * NOTIF-2: the people for a case stage. The active assignees on the case
     * who hold $permission, if any; otherwise every active staff user holding
     * it. Resolved by permission, never by role name.
     *
     * @return Collection<int, User>
     */
    public function forStage(ProcurementCase $case, Permission $permission): Collection
    {
        $assignees = User::permission($permission->value)
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('users.user_type', UserType::Staff->value)
            ->whereIn('users.id', CaseAssignment::query()->select('user_id')
                ->where('case_id', $case->getKey())
                ->whereNotNull('user_id')
                ->whereNull('completed_at')
                ->whereNull('cancelled_at'))
            ->get()
            ->unique(fn (User $u) => $u->getKey())
            ->values();

        return $assignees->isNotEmpty() ? $assignees : $this->staffWith($permission);
    }

    /**
     * NOTIF-2: who hears about $message on $query. The route came from the
     * query_routes table when the query was raised (raised_by_role,
     * raised_to_role, via_cmo on the query).
     *
     * - Held (via the CMO, not yet forwarded): the CMO's desk (query.forward).
     * - Delivered to a vendor role: the submitter.
     * - Delivered to the raiser's side: the staff member who raised it.
     * - Delivered to the recipient desk: its holders who can see the case,
     *   plus the officer the CMO named on the forward.
     *
     * The author is never told about their own message.
     *
     * @return Collection<int, User>
     */
    public function forQueryMessage(ProcurementCase $case, CaseQuery $query, QueryMessage $message): Collection
    {
        $author = (int) $message->author_id;

        $users = match (true) {
            $message->isHeld() => $this->queryDesk(),
            QueryAccess::isVendorRole($message->recipient_role) => $this->submitter($case),
            $message->recipient_role === $query->raised_by_role => $this->user((int) $query->raised_by_user_id),
            default => self::merge(
                $this->roleOnCase($message->recipient_role, $case),
                $this->user($message->forwarded_to_user_id !== null ? (int) $message->forwarded_to_user_id : null),
            ),
        };

        return $users->reject(fn (User $u) => (int) $u->getKey() === $author)->values();
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
