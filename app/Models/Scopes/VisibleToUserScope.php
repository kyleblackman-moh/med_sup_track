<?php

namespace App\Models\Scopes;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which cases a user may see. applyFor() is the single source of truth: the
 * global scope and CasePolicy::view both use it.
 *
 * Fails closed: no user, or a vendor without an organisation, sees nothing.
 * Staff (any non-vendor user) never see a DRAFT case.
 */
class VisibleToUserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        $this->applyFor($builder, $user instanceof User ? $user : null);
    }

    public function applyFor(Builder $query, ?User $user): void
    {
        $table = $query->getModel()->getTable();

        // 1. No user: nothing.
        if ($user === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        // 2. Vendor users: own organisation only. Checked BEFORE case.view_all so
        //    a vendor never sees beyond its organisation, whatever it holds.
        if ($user->isVendor()) {
            $user->org_id
                ? $query->where("{$table}.org_id", $user->org_id)
                : $query->whereRaw('1 = 0');

            return;
        }

        // 3. Staff never see drafts, whatever they hold: a draft belongs to the
        //    vendor until it is submitted. Applies to every branch below.
        $query->where("{$table}.status", '<>', CaseStatus::DRAFT->value);

        // 4. Staff with case.view_all: every non-draft case.
        if ($user->can(Permission::CaseViewAll->value)) {
            return;
        }

        // 5. Other staff: non-draft cases they are assigned to.
        $roles = $user->getRoleNames()->all();

        $query->whereIn("{$table}.id", function (QueryBuilder $sub) use ($user, $roles) {
            $sub->select('case_id')
                ->from('case_assignments')
                ->where(function (QueryBuilder $w) use ($user, $roles) {
                    // Any row for this user, active or completed: completed rows
                    // keep read-only visibility (rule A2).
                    $w->where('user_id', $user->id);

                    // Deliberate tightening of spec 8.5: completed pool rows do not
                    // grant role-wide visibility. See Q7/Q13.
                    // (Cancelled pool rows are treated the same way.)
                    if ($roles !== []) {
                        $w->orWhere(fn (QueryBuilder $pool) => $pool
                            ->whereNull('user_id')
                            ->whereNull('completed_at')
                            ->whereNull('cancelled_at')
                            ->whereIn('role', $roles));
                    }
                });
        });
        // system_admin and auditor hold no assignments, so they match nothing.
    }
}
