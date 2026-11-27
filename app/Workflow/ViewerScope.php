<?php

namespace App\Workflow;

use App\Enums\Permission;
use App\Enums\ReviewDesk;
use App\Models\User;

/**
 * Kyle, 2026-11-26: what a staff user sees of the rest of the process. The
 * CMO's Office (case.view_all, or intake for the registry) and the PS Office
 * (case.view_all) see everything. A Technocrat, a CAB officer or a Legal
 * officer sees only their own part: their own checklist item, their own
 * desk as the holder, and the time the file spent in their own lane.
 *
 * Display only, by permission (never a role name). Access to the case itself
 * is unchanged (CasePolicy).
 */
final class ViewerScope
{
    /**
     * The lanes this user may see in full (FileTracking::LANES keys), or null
     * for everything.
     *
     * @return list<string>|null
     */
    public static function lanes(?User $user): ?array
    {
        if ($user === null || $user->can(Permission::CaseViewAll->value) || $user->can(Permission::CaseIntake->value)) {
            return null;
        }

        $lanes = [];
        if ($user->can(ReviewDesk::Technical->permission()->value)) {
            $lanes[] = 'technocrats';
        }
        if ($user->can(ReviewDesk::Funding->permission()->value)) {
            $lanes[] = 'cab';
        }
        if ($user->can(Permission::LegalConfirm->value)) {
            $lanes[] = 'legal';
        }

        return $lanes;
    }

    /**
     * The review desks whose checklist item this user sees, or null for all
     * items (hardcopy included).
     *
     * @return list<ReviewDesk>|null
     */
    public static function desks(?User $user): ?array
    {
        $lanes = self::lanes($user);
        if ($lanes === null) {
            return null;
        }

        return array_values(array_filter([
            in_array('technocrats', $lanes, true) ? ReviewDesk::Technical : null,
            in_array('cab', $lanes, true) ? ReviewDesk::Funding : null,
        ]));
    }
}
