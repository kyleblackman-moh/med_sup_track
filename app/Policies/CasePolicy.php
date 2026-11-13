<?php

namespace App\Policies;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\UserType;
use App\Models\CaseAssignment;
use App\Models\ProcurementCase;
use App\Models\Scopes\VisibleToUserScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Case-level authorisation (spec 8.3 layer 4). Checks permissions, never role
 * names; the only role-name use is matching case_assignments.role data.
 *
 * Never uses auth(): the user is always the one passed in.
 *
 * Registered in AppServiceProvider (the model is ProcurementCase, so
 * auto-discovery would look for ProcurementCasePolicy).
 */
class CasePolicy
{
    /**
     * Vendors, case.view_all holders and anyone with case.view_assigned. That
     * excludes system_admin and auditor, who can never see a case.
     */
    public function viewAny(User $user): bool
    {
        return $user->isVendor()
            || $user->can(Permission::CaseViewAll->value)
            || $user->can(Permission::CaseViewAssigned->value);
    }

    /**
     * May use the staff case pages (slice 9a): a staff user (identity layer,
     * user_type) holding case.view_all or case.view_assigned. Which cases they
     * then see is still VisibleToUserScope's decision.
     */
    public function viewStaffCases(User $user): bool
    {
        return $user->user_type === UserType::Staff->value
            && ($user->can(Permission::CaseViewAll->value) || $user->can(Permission::CaseViewAssigned->value));
    }

    public function view(User $user, ProcurementCase $case): bool
    {
        return $this->visible($user, $case);
    }

    /**
     * Building block for slices 7-8: may $user perform $permission on this case now?
     * Completed assignments give read-only access, never act.
     */
    public function act(User $user, ProcurementCase $case, string $permission): bool
    {
        if (! $case->status instanceof CaseStatus || $case->status->isTerminal()) {
            return false;
        }

        if (! $user->can($permission) || ! $this->visible($user, $case)) {
            return false;
        }

        return $user->can(Permission::CaseViewAll->value) && ! $user->isVendor()
            || ($user->isVendor() && $user->org_id !== null && $user->org_id === $case->org_id)
            || $this->hasActiveAssignment($user, $case);
    }

    private function visible(User $user, ProcurementCase $case): bool
    {
        if (! $case->exists) {
            return false;
        }

        $query = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey());
        (new VisibleToUserScope)->applyFor($query, $user);

        return $query->exists();
    }

    private function hasActiveAssignment(User $user, ProcurementCase $case): bool
    {
        $roles = $user->getRoleNames()->all();

        return CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->where(function (Builder $w) use ($user, $roles) {
                $w->where('user_id', $user->id);

                if ($roles !== []) {
                    $w->orWhere(fn (Builder $pool) => $pool->whereNull('user_id')->whereIn('role', $roles));
                }
            })
            ->exists();
    }
}
