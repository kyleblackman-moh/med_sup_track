<?php

namespace App\Submissions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\TechnicalStatus;
use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Saves the request content of a DRAFT case.
 *
 * - Requisition: only the fields present are written (null clears a field).
 * - Child sections present in the payload are replaced in full; absent sections
 *   are untouched. sort_order follows the array order.
 * - Never touches status, reference_no, tracks or the staff-set technical_*
 *   quotation columns: every quotation row is written as PENDING.
 *
 * KNOWN LIMITATION: no optimistic locking; concurrent saves are last-save-wins.
 */
final class SaveRequisition
{
    /** section => [model, has sort_order column] */
    private const SECTIONS = [
        'quotations' => [CaseQuotation::class, true],
        'tender_awards' => [CaseTenderAward::class, true],
        'purchase_orders' => [CasePurchaseOrder::class, true],
        'stock_expiries' => [CaseStockExpiry::class, false],
    ];

    public function handle(ProcurementCase $case, User $user, RequisitionData $data): ProcurementCase
    {
        $gate = Gate::forUser($user);

        // Visibility first, so users who cannot see the case learn nothing about it.
        $gate->authorize('view', $case);

        if ($case->status !== CaseStatus::DRAFT) {
            throw new DraftNotEditable($case->status instanceof CaseStatus ? $case->status : null);
        }

        $gate->authorize('act', [$case, Permission::CaseSubmit->value]);

        DB::transaction(function () use ($case, $user, $data) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== CaseStatus::DRAFT) {
                throw new DraftNotEditable($locked->status instanceof CaseStatus ? $locked->status : null);
            }

            $caseId = $locked->getKey();
            $saved = [];
            $counts = [];

            if ($data->requisition !== null) {
                $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $caseId)->first()
                    ?? (new CaseRequisition)->forceFill(['case_id' => $caseId]);
                $requisition->fill($data->requisition)->save();
                $saved[] = 'requisition';
            }

            foreach (self::SECTIONS as $section => [$model, $hasSortOrder]) {
                if (! $data->hasSection($section)) {
                    continue;
                }

                $model::withoutGlobalScopes()->where('case_id', $caseId)->delete();

                foreach ($data->sections[$section] as $index => $values) {
                    $row = (new $model)->fill($values)->forceFill(['case_id' => $caseId]);

                    if ($hasSortOrder) {
                        $row->forceFill(['sort_order' => $index]);
                    }

                    if ($row instanceof CaseQuotation) {
                        // Staff-set columns: a vendor save always resets them.
                        $row->forceFill([
                            'technical_status' => TechnicalStatus::PENDING,
                            'technical_status_set_by' => null,
                            'technical_status_set_at' => null,
                        ]);
                    }

                    $row->save();
                }

                $saved[] = $section;
                $counts[$section] = count($data->sections[$section]);
            }

            $locked->touch();

            // Names and counts only; never field values. Fail closed.
            Audit::record(
                AuditAction::CaseDraftSaved,
                ['type' => 'case', 'id' => $caseId],
                $caseId,
                ['sections' => $saved, 'counts' => $counts],
                $user,
            );
        });

        return $case->refresh();
    }
}
