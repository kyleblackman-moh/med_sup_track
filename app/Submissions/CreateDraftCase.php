<?php

namespace App\Submissions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A vendor user starts a new request. The organisation always comes from the
 * user, never from input.
 */
final class CreateDraftCase
{
    /**
     * Slice V2-1b: with no type (New request) the draft's type is NOT_SELECTED
     * and the vendor chooses one in the form (ChangeRequestType). A type given
     * here (the dev sample command, tests) must be one of the five, active.
     *
     * @throws AuthorizationException not a vendor user with an organisation and case.submit
     * @throws ValidationException the procurement type is inactive or not one of the five request types
     */
    public function handle(User $user, ?ProcurementType $type = null): ProcurementCase
    {
        if (! $user->isVendor() || $user->org_id === null || ! $user->can(Permission::CaseSubmit->value)) {
            throw new AuthorizationException('Only vendor users can start a request.');
        }

        if ($type === null) {
            $type = ProcurementType::where('code', ProcurementTypeProfile::NOT_SELECTED)->firstOrFail();
        } elseif (! $type->is_active || ! ProcurementTypeProfile::for($type)->isRequestType()) {
            throw ValidationException::withMessages([
                'procurement_type' => 'This procurement type is not available.',
            ]);
        }

        return DB::transaction(function () use ($user, $type) {
            $case = new ProcurementCase;
            $case->forceFill([
                'org_id' => $user->org_id,
                'created_by' => $user->getKey(),
                'procurement_type_id' => $type->getKey(),
                'title' => $type->name, // cases.title is NOT NULL; the applicant has not described the item yet
                'status' => CaseStatus::DRAFT,
                'current_holder_role' => 'vendor_applicant',
                'status_changed_at' => CarbonImmutable::now('UTC'),
                // reference_no stays null until submission (slice 8d).
            ])->save();

            (new CaseRequisition)->forceFill(['case_id' => $case->getKey()])->save();

            // Fail closed: if this throws, the case and requisition roll back.
            Audit::record(
                AuditAction::CaseCreated,
                ['type' => 'case', 'id' => $case->getKey()],
                $case->getKey(),
                ['procurement_type' => $type->code],
                $user,
            );

            return $case;
        });
    }
}
