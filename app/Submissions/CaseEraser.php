<?php

namespace App\Submissions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\Exceptions\DraftAlreadyDeleted;
use App\Submissions\Exceptions\DraftNotEditable;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/**
 * The ONLY code that deletes a case (LIST-1). Submitted cases are never
 * deleted; a vendor's own DRAFT can be, through eraseDraft(). dev:clear-cases
 * (DEV-2, local and testing only) must call erase() here rather than delete
 * rows itself.
 *
 * Rows go children-first in one transaction: no TRUNCATE, no cascades (every
 * foreign key is NO ACTION). Uploaded files are removed from the private disk
 * only after the commit; a file that cannot be removed is logged, not fatal.
 *
 * A case with rows in any PROTECTED table is refused outright. Those rows are
 * history (case_transitions and case_track_events are insert-only, enforced by
 * triggers) and a DRAFT never has them. audit_logs has no foreign key and is
 * never touched: audit rows outlive the case.
 */
final class CaseEraser
{
    /** Child tables keyed by their name in the audit counts, in delete order. */
    private const CHILD_TABLES = [
        'purchase_orders' => 'case_purchase_orders', // before tender_awards (award_id)
        'tender_awards' => 'case_tender_awards',
        'quotations' => 'case_quotations',
        'stock_expiries' => 'case_stock_expiries',
        'variations' => 'case_variations', // slice V2-1 (unused since V2-5; old drafts may still have a row)
        'variation_rows' => 'case_variation_rows', // V2-5
        'requisitions' => 'case_requisitions',
        'documents' => 'documents',
    ];

    /** Tables a case keeps for good. Any row here means the case cannot be erased. */
    private const PROTECTED_TABLES = [
        'case_transitions',
        'case_track_events',
        'case_assignments',
        'queries',
        'letters',
        'notifications',
    ];

    private const DISK = 'documents';

    /**
     * Erase $user's own DRAFT and record one draft.deleted audit row in the same
     * transaction. The caller has already bound the case through the visibility
     * scope, so an out-of-scope case never reaches here.
     *
     * @throws AuthorizationException not visible, or no case.submit on this case
     * @throws DraftNotEditable the case is not (or is no longer) a DRAFT
     * @throws DraftAlreadyDeleted an earlier request erased it
     */
    public function eraseDraft(ProcurementCase $case, User $user): ErasedCase
    {
        $gate = Gate::forUser($user);

        $gate->authorize('view', $case);

        if ($case->status !== CaseStatus::DRAFT) {
            throw new DraftNotEditable($case->status instanceof CaseStatus ? $case->status : null);
        }

        // The permission that allows editing a draft (RequestController::edit/update).
        $gate->authorize('act', [$case, Permission::CaseSubmit->value]);

        return $this->erase($case->getKey(), function (ProcurementCase $locked) {
            // Re-checked on the locked row: a submit may have won the race.
            if ($locked->status !== CaseStatus::DRAFT) {
                throw new DraftNotEditable($locked->status instanceof CaseStatus ? $locked->status : null);
            }
        }, function (ErasedCase $erased) use ($user) {
            // Ids, a type code and counts only: never the description or any typed text.
            Audit::record(
                AuditAction::DraftDeleted,
                ['type' => 'case', 'id' => $erased->caseId],
                $erased->caseId,
                [
                    'case_id' => $erased->caseId,
                    'procurement_type' => $erased->procurementTypeCode,
                    'rows' => $erased->rowCounts,
                    'files' => $erased->fileCount,
                ],
                $user,
            );
        });
    }

    /**
     * Erase one case and its uploaded files, with no authorisation and no
     * audit row of its own. For eraseDraft() and dev:clear-cases only.
     *
     * @param  (callable(ProcurementCase): void)|null  $check  runs on the locked row before anything is deleted
     * @param  (callable(ErasedCase): void)|null  $inTransaction  runs after the deletes, inside the transaction (the audit row)
     *
     * @throws DraftAlreadyDeleted the case no longer exists
     * @throws LogicException the case has protected history rows
     */
    public function erase(string $caseId, ?callable $check = null, ?callable $inTransaction = null): ErasedCase
    {
        $erased = DB::transaction(function () use ($caseId, $check, $inTransaction) {
            $locked = ProcurementCase::withoutGlobalScopes()->with('procurementType')
                ->whereKey($caseId)->lockForUpdate()->first();

            if ($locked === null) {
                throw new DraftAlreadyDeleted;
            }

            foreach (self::PROTECTED_TABLES as $table) {
                if (DB::table($table)->where('case_id', $caseId)->exists()) {
                    throw new LogicException("Case {$caseId} has {$table} rows and cannot be erased.");
                }
            }

            $storageKeys = DB::table('documents')->where('case_id', $caseId)->pluck('storage_key')->all();

            $counts = [];
            foreach (self::CHILD_TABLES as $name => $table) {
                $counts[$name] = DB::table($table)->where('case_id', $caseId)->count();
            }

            $erased = new ErasedCase($caseId, $locked->procurementType?->code, $counts, count($storageKeys), $storageKeys);

            if ($check !== null) {
                $check($locked);
            }

            foreach (self::CHILD_TABLES as $table) {
                if ($table === 'documents') {
                    // Versions point at the version they replace (supersedes_id, NO ACTION).
                    DB::table('documents')->where('case_id', $caseId)->whereNotNull('supersedes_id')->update(['supersedes_id' => null]);
                }

                DB::table($table)->where('case_id', $caseId)->delete();
            }

            DB::table('cases')->where('id', $caseId)->delete();

            if ($inTransaction !== null) {
                $inTransaction($erased);
            }

            return $erased;
        });

        $this->deleteFiles($erased);

        return $erased;
    }

    /** After the commit. A file that will not go is logged; the case is already gone. */
    private function deleteFiles(ErasedCase $erased): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($erased->storageKeys as $key) {
            try {
                if (! $disk->delete($key) && $disk->exists($key)) {
                    Log::warning('CaseEraser could not delete a file.', ['case_id' => $erased->caseId, 'storage_key' => $key]);
                }
            } catch (Throwable $e) {
                Log::warning('CaseEraser could not delete a file.', ['case_id' => $erased->caseId, 'storage_key' => $key, 'error' => $e->getMessage()]);
            }
        }

        // Anything left in the case's folders (for example an upload whose row never committed).
        foreach (["cases/{$erased->caseId}", "quarantine/cases/{$erased->caseId}"] as $directory) {
            try {
                $disk->deleteDirectory($directory); // a missing folder is not an error
            } catch (Throwable $e) {
                Log::warning('CaseEraser could not delete a folder.', ['case_id' => $erased->caseId, 'directory' => $directory, 'error' => $e->getMessage()]);
            }
        }
    }
}
