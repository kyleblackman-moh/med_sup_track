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
use App\Models\CaseVariationRow;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saves the request content of a DRAFT case.
 *
 * - Requisition: only the fields present are written (null clears a field).
 * - Child sections present in the payload are saved row by row (slice F1): a
 *   row with an id updates that row in place (only the keys it carries), a row
 *   without an id is inserted, and the case's rows missing from the payload are
 *   deleted (DRAFT only). Row ids stay stable across saves. Absent sections are
 *   untouched. sort_order follows the array order. The old form sends rows
 *   without ids, so for it this is the same as replacing the section.
 * - Awards are saved before orders. Removing an award first sets award_id to
 *   null on the orders that pointed at it (no dangling link; readiness then
 *   flags those orders). An order may only point at an award row of this case.
 * - Never touches status, reference_no, tracks or the staff-set technical_*
 *   quotation columns: every saved quotation row is reset to PENDING.
 *
 * KNOWN LIMITATION: no optimistic locking; concurrent saves are last-save-wins.
 */
final class SaveRequisition
{
    /** section => [model, has sort_order column], in save order (awards before orders). */
    private const SECTIONS = [
        'tender_awards' => [CaseTenderAward::class, true],
        'purchase_orders' => [CasePurchaseOrder::class, true],
        'quotations' => [CaseQuotation::class, true],
        'stock_expiries' => [CaseStockExpiry::class, false],
        // V2-5: Details of Variation Requested, saved exactly like the award rows.
        'variation_rows' => [CaseVariationRow::class, true],
    ];

    public function handle(ProcurementCase $case, User $user, RequisitionData $data): SavedDraft
    {
        $gate = Gate::forUser($user);

        // Visibility first, so users who cannot see the case learn nothing about it.
        $gate->authorize('view', $case);

        if ($case->status !== CaseStatus::DRAFT) {
            throw new DraftNotEditable($case->status instanceof CaseStatus ? $case->status : null);
        }

        $gate->authorize('act', [$case, Permission::CaseSubmit->value]);

        $idMap = [];
        $rowIds = [];

        DB::transaction(function () use ($case, $user, $data, &$idMap, &$rowIds) {
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

            // V2-5: the previous-request answer lives on the case (not mass assignable).
            if ($data->hasPreviousRequest) {
                $locked->forceFill(['previous_request_in_cycle' => $data->previousRequest])->save();
                $saved[] = 'previous_request_in_cycle';
            }

            foreach (self::SECTIONS as $section => [$model, $hasSortOrder]) {
                if (! $data->hasSection($section)) {
                    continue;
                }

                $rowIds[$section] = $this->saveSection($caseId, $section, $model, $hasSortOrder, $data->sections[$section], $idMap);

                $counts[$section] = count($rowIds[$section]);
            }

            // Audit sections in their usual order, whatever order they were saved in.
            $order = [...ProcurementTypeProfile::SECTIONS, ...RequisitionRules::FORM_SECTIONS];
            $counts = array_filter(
                array_map(fn (string $s) => $counts[$s] ?? null, array_combine($order, $order)),
                fn ($count) => $count !== null,
            );

            $saved = [...$saved, ...array_keys($counts)];

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

        // Slice V2-1: days on hand and the expiry warning on what was saved.
        $fresh = $case->refresh();
        $requisition = CaseRequisition::withoutGlobalScopes()->where('case_id', $fresh->getKey())->first();
        $risk = ExpiryRisk::compute(
            $requisition?->average_monthly_consumption,
            (bool) $requisition?->no_stock_on_hand,
            CaseStockExpiry::withoutGlobalScopes()->where('case_id', $fresh->getKey())->get(),
            ExpiryRisk::referenceDate($fresh),
        );

        return new SavedDraft($fresh, $idMap, $rowIds, $risk['days_on_hand'], $risk['expiry_warnings']);
    }

    /**
     * Upserts one section's rows by id and deletes the case's rows that are no
     * longer in the payload. Throws ValidationException (the caller's
     * transaction rolls back) for an id that is not a row of this case and
     * section, or an award_id that is not an award row of this case.
     *
     * Slice F2: a blank row (RequisitionRules::isBlankRow) is dropped, so a blank
     * row with an id is deleted. client_key is never stored; a new row's key is
     * added to $idMap. Returns the saved rows' ids in order.
     *
     * @param  class-string<Model>  $model
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $idMap
     * @return list<int>
     */
    /** The row's vendor-entered columns (as for AD_HOC) are all empty. */
    private static function isBlank(Model $row, string $section): bool
    {
        $fields = array_diff(array_keys(RequisitionRules::rowFields($section, ProcurementTypeProfile::AD_HOC)), ['id', 'client_key']);
        $values = [];
        foreach ($fields as $field) {
            $value = $row->getAttribute($field);
            $values[$field] = is_bool($value) || $value === null ? $value : (string) $value;
        }

        return RequisitionRules::isBlankRow($values);
    }

    private function saveSection(string $caseId, string $section, string $model, bool $hasSortOrder, array $rows, array &$idMap): array
    {
        $existing = $model::withoutGlobalScopes()->where('case_id', $caseId)->get()->keyBy(fn (Model $m) => (int) $m->getKey());
        $awardIds = $section === 'purchase_orders'
            ? CaseTenderAward::withoutGlobalScopes()->where('case_id', $caseId)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
        $kept = [];

        foreach ($rows as $index => $values) {
            $id = $values['id'] ?? null;
            $clientKey = $values['client_key'] ?? null;
            unset($values['id'], $values['client_key']);

            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["{$section}.{$index}.id" => 'This row no longer exists. Reload the page and try again.']);
            }

            if (($values['award_id'] ?? null) !== null && ! in_array($values['award_id'], $awardIds, true)) {
                throw ValidationException::withMessages(["{$section}.{$index}.award_id" => 'Choose a supplier from the award rows.']);
            }

            $row = $id !== null ? $existing->get($id) : (new $model)->forceFill(['case_id' => $caseId]);
            $row->fill($values);

            // Judged on the row as it would be saved (an id-only row keeps its values):
            // a blank row is dropped, so an existing blank row is deleted below.
            if (self::isBlank($row, $section)) {
                continue;
            }

            if ($hasSortOrder) {
                $row->forceFill(['sort_order' => count($kept)]); // blank rows leave no gap
            }

            if ($row instanceof CaseQuotation) {
                // Staff-set columns: a vendor save always resets them.
                $row->forceFill([
                    'technical_status' => TechnicalStatus::PENDING,
                    'technical_status_set_by' => null,
                    'technical_status_set_at' => null,
                ]);
            }

            $isNew = ! $row->exists;
            $row->save();
            $kept[] = (int) $row->getKey();

            if ($isNew && $clientKey !== null) {
                $idMap[$clientKey] = (int) $row->getKey();
            }
        }

        $removed = $existing->keys()->diff($kept)->values()->all();
        if ($removed === []) {
            return $kept;
        }

        if ($section === 'tender_awards') {
            // No dangling link: orders of a removed award lose their award_id.
            CasePurchaseOrder::withoutGlobalScopes()
                ->where('case_id', $caseId)
                ->whereIn('award_id', $removed)
                ->update(['award_id' => null]);
        }

        $model::withoutGlobalScopes()->where('case_id', $caseId)->whereIn('id', $removed)->delete();

        return $kept;
    }
}
