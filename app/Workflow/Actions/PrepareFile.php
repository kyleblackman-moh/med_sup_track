<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * T5, READY_FOR_FILE -> FILE_PREPARED (slice 10c). In one transaction: lock the
 * case, write the file number the CMO entered, transition (the T5 guard checks
 * file_no is present), and audit with the file number. Any failure, including
 * the audit write, rolls back all of it (fail closed).
 *
 * The file number is typed in, not generated. It is not unique: the same
 * number may appear on more than one case. ASSUMPTION: a fixed series
 * (config cases.file_no_prefix); the per-case part is unknown, to confirm with
 * the CMO's office. The prefix is therefore not validated.
 */
final class PrepareFile
{
    public const FILE_NO_MAX = 30;

    public function __construct(private readonly CaseTransitionService $transitions) {}

    /** @throws ValidationException */
    public function handle(ProcurementCase $case, User $actor, ?string $fileNo): StepResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        $fileNo = trim((string) $fileNo);
        if ($fileNo === '' || mb_strlen($fileNo) > self::FILE_NO_MAX) {
            throw ValidationException::withMessages(['file_no' => 'Enter the file number (1 to '.self::FILE_NO_MAX.' characters).']);
        }

        return DB::transaction(function () use ($case, $actor, $gate, $fileNo) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, Permission::FilePrepare->value]);

            if ($locked->status !== CaseStatus::READY_FOR_FILE) {
                return $this->alreadyPrepared($locked) ?? throw ValidationException::withMessages([
                    'prepare' => 'The file can be prepared only while the case is Ready for file.',
                ]);
            }

            $locked->forceFill([
                'file_no' => $fileNo,
                'file_prepared_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $this->transitions->transition($locked, CaseStatus::FILE_PREPARED, $actor);

            Audit::record(
                AuditAction::CaseFilePrepared,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['file_no' => $fileNo],
                $actor,
            );

            return StepResult::done($locked->refresh());
        });
    }

    /** The file was prepared before: who did it and when, from the T5 history row. */
    private function alreadyPrepared(ProcurementCase $case): ?StepResult
    {
        $row = CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('from_status', CaseStatus::READY_FOR_FILE->value)
            ->where('to_status', CaseStatus::FILE_PREPARED->value)
            ->orderByDesc('id')
            ->first();

        return $row ? StepResult::already($case, $row->actor?->name, $row->created_at, $case->file_no) : null;
    }
}
