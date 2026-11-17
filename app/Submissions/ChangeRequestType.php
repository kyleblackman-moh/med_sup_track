<?php

namespace App\Submissions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\User;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Slice V2-1: a vendor changes its own DRAFT to another of the five request
 * types. Nothing entered is deleted: data in a section the new type hides
 * stays in the draft and is discarded only at submit (SubmitCase).
 *
 * Slice V2-1b: this is also how the type of a new draft (NOT_SELECTED) is
 * chosen. The change is always TO one of the five, never to NOT_SELECTED.
 *
 * The caller binds the case through the visibility scope, so a case outside
 * the user's organisation is 404 before it gets here. A case that is not a
 * DRAFT is DraftNotEditable (403). Audited with the two type codes only.
 */
final class ChangeRequestType
{
    /**
     * @throws AuthorizationException not visible, or no case.submit on this case
     * @throws DraftNotEditable the case is not (or is no longer) a DRAFT
     * @throws ValidationException the new type is unknown, inactive or not one of the five
     */
    public function handle(ProcurementCase $case, User $user, string $code): ProcurementCase
    {
        $gate = Gate::forUser($user);

        // Visibility first, so users who cannot see the case learn nothing about it.
        $gate->authorize('view', $case);

        if ($case->status !== CaseStatus::DRAFT) {
            throw new DraftNotEditable($case->status instanceof CaseStatus ? $case->status : null);
        }

        // The permission that allows editing a draft (RequestController::edit/update).
        $gate->authorize('act', [$case, Permission::CaseSubmit->value]);

        $type = in_array($code, ProcurementTypeProfile::REQUEST_TYPES, true)
            ? ProcurementType::where('code', $code)->where('is_active', true)->first()
            : null;

        if ($type === null) {
            throw ValidationException::withMessages(['procurement_type' => 'This request type is not available.']);
        }

        return DB::transaction(function () use ($case, $user, $type) {
            $locked = ProcurementCase::withoutGlobalScopes()->with('procurementType')
                ->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            // Re-checked on the locked row: a submit may have won the race.
            if ($locked->status !== CaseStatus::DRAFT) {
                throw new DraftNotEditable($locked->status instanceof CaseStatus ? $locked->status : null);
            }

            $from = $locked->procurementType;
            if ($from?->getKey() === $type->getKey()) {
                return $locked; // already this type: nothing to change or audit
            }

            $locked->forceFill(['procurement_type_id' => $type->getKey()]);
            // The title is the type name until the applicant describes the item (CreateDraftCase).
            if ($from !== null && $locked->title === $from->name) {
                $locked->forceFill(['title' => $type->name]);
            }
            $locked->save();

            // Type codes only: never any typed text.
            Audit::record(
                AuditAction::DraftTypeChanged,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['from' => $from?->code, 'to' => $type->code],
                $user,
            );

            return $locked->setRelation('procurementType', $type);
        });
    }
}
