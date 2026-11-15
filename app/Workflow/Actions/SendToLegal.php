<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use App\Workflow\EligibleReviewers;
use App\Workflow\Exceptions\GateNotPassed;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * T6, FILE_PREPARED -> WITH_LEGAL (slice 10c). Same pattern as ForwardForReview:
 * lock, check the chosen Legal officers (eligible by legal.confirm, never role
 * name), transition (the T6 guard requires file_no), one active user assignment
 * per officer, and audit with counts only. Fail closed.
 *
 * Other assignments are left as they are.
 */
final class SendToLegal
{
    public const MIN = 1;

    public const MAX = 2;

    public const INSTRUCTION_MAX = 500;

    /** case_assignments.purpose for the Legal desk. */
    public const PURPOSE = 'legal';

    public function __construct(
        private readonly CaseTransitionService $transitions,
        private readonly EligibleReviewers $eligible,
    ) {}

    /**
     * case_assignments.role for a Legal officer: the holder role T6 declares
     * (a data label, never an authorisation check).
     */
    public static function assignmentRole(): string
    {
        return (string) TransitionRules::find(CaseStatus::FILE_PREPARED, CaseStatus::WITH_LEGAL)?->holderRole;
    }

    /**
     * @param  array<mixed>  $legalIds
     *
     * @throws ValidationException
     */
    public function handle(ProcurementCase $case, User $actor, array $legalIds, ?string $instruction = null): StepResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        $instruction = $instruction === null || trim($instruction) === '' ? null : trim($instruction);
        if ($instruction !== null && mb_strlen($instruction) > self::INSTRUCTION_MAX) {
            throw ValidationException::withMessages(['instruction' => 'The instruction can be at most '.self::INSTRUCTION_MAX.' characters.']);
        }

        return DB::transaction(function () use ($case, $actor, $gate, $legalIds, $instruction) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, Permission::FilePrepare->value]);

            if ($locked->status !== CaseStatus::FILE_PREPARED) {
                return $this->alreadySent($locked) ?? throw ValidationException::withMessages([
                    'send_to_legal' => 'The case can be sent to Legal only once the file is prepared.',
                ]);
            }

            $chosen = $this->chosen($legalIds);

            try {
                $this->transitions->transition($locked, CaseStatus::WITH_LEGAL, $actor, $instruction);
            } catch (GateNotPassed $e) {
                throw ValidationException::withMessages([
                    'send_to_legal' => implode('; ', array_map(fn (string $k) => TransitionRules::GUARD_MESSAGES[$k] ?? $k, $e->missing)).'.',
                ]);
            }

            foreach ($chosen as $officer) {
                $this->assign($locked, $actor, $officer);
            }

            Audit::record(
                AuditAction::CaseSentToLegal,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                ['legal_officers' => count($chosen), 'instruction_given' => $instruction !== null],
                $actor,
            );

            return StepResult::done($locked->refresh());
        });
    }

    private function alreadySent(ProcurementCase $case): ?StepResult
    {
        $row = CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('from_status', CaseStatus::FILE_PREPARED->value)
            ->where('to_status', CaseStatus::WITH_LEGAL->value)
            ->orderByDesc('id')
            ->first();

        return $row ? StepResult::already($case, $row->actor?->name, $row->created_at) : null;
    }

    /**
     * Duplicates collapse; then 1 to 2 active staff users holding legal.confirm.
     *
     * @param  array<mixed>  $ids
     * @return list<User>
     *
     * @throws ValidationException
     */
    private function chosen(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $clean[(int) $id] = (int) $id;
            } else {
                throw ValidationException::withMessages(['legal_officers' => 'Choose from the listed Legal officers.']);
            }
        }

        if (count($clean) < self::MIN || count($clean) > self::MAX) {
            throw ValidationException::withMessages(['legal_officers' => 'Choose '.self::MIN.' to '.self::MAX.' Legal officers.']);
        }

        $eligible = $this->eligible->legalAmong(array_values($clean));
        if (count($eligible) !== count($clean)) {
            throw ValidationException::withMessages(['legal_officers' => 'Choose from the listed Legal officers. One or more of the people chosen cannot take Legal review.']);
        }

        return array_values(array_map(fn (int $id) => $eligible[$id], $clean));
    }

    private function assign(ProcurementCase $case, User $actor, User $officer): void
    {
        $assignment = (new CaseAssignment)->forceFill([
            'case_id' => $case->getKey(),
            'role' => self::assignmentRole(),
            'purpose' => self::PURPOSE,
            'user_id' => $officer->getKey(),
            'assigned_by' => $actor->getKey(),
            'assigned_at' => CarbonImmutable::now('UTC'),
        ]);
        $assignment->save();

        Audit::record(
            AuditAction::CaseAssignmentCreated,
            ['type' => 'case_assignment', 'id' => $assignment->getKey()],
            $case->getKey(),
            ['role' => self::assignmentRole(), 'user_id' => (int) $officer->getKey(), 'purpose' => self::PURPOSE],
            $actor,
        );
    }
}
