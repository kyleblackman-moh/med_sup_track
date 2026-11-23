<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Where a user lands after login when there is no intended URL. Decided by the
 * same checks as the shared `can.requests` and `can.cases` flags
 * (HandleInertiaRequests): permissions and policy, never role names. Console
 * users without case work land on the IT health page (/admin).
 */
final class LandingPage
{
    public static function for(?User $user): string
    {
        if ($user !== null && $user->can(Permission::CaseSubmit->value)) {
            return route('requests.index', absolute: false);
        }

        // Staff with a work queue start on the dashboard, where the queue is.
        if (WorkQueue::appliesTo($user)) {
            return route('dashboard', absolute: false);
        }

        if ($user !== null && Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class)) {
            return route('cases.index', absolute: false);
        }

        // IT-ADMIN: an account with the IT Admin console and no case work (the System Admin) starts on IT health.
        if ($user !== null && $user->can(Permission::AdminAccess->value) && $user->can(Permission::SystemHealthView->value)) {
            return route('admin.dashboard', absolute: false);
        }

        return route('dashboard', absolute: false);
    }
}
