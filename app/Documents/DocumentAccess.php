<?php

namespace App\Documents;

use App\Documents\Exceptions\UploadNotAllowed;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Shared authorisation for adding, replacing and withdrawing documents. */
final class DocumentAccess
{
    /**
     * Vendor users only (this slice), on a case they can see, while it is a DRAFT,
     * with document.upload under CasePolicy::act.
     *
     * @throws AuthorizationException
     * @throws UploadNotAllowed
     */
    public static function authoriseChange(ProcurementCase $case, User $user): void
    {
        $gate = Gate::forUser($user);
        $gate->authorize('view', $case);

        if (! $user->isVendor()) {
            throw new AuthorizationException('Only vendor users can change request documents.');
        }

        // Current status from the database, not the possibly stale model.
        $status = DB::table('cases')->where('id', $case->getKey())->value('status');
        if ($status !== CaseStatus::DRAFT->value) {
            throw new UploadNotAllowed;
        }

        $gate->authorize('act', [$case, Permission::DocumentUpload->value]);
    }

    /** The same check, as a boolean for props (can_replace, can_withdraw). */
    public static function canChange(ProcurementCase $case, User $user): bool
    {
        try {
            self::authoriseChange($case, $user);

            return true;
        } catch (AuthorizationException|UploadNotAllowed) {
            return false;
        }
    }
}
