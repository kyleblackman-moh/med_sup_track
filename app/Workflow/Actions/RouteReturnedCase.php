<?php

namespace App\Workflow\Actions;

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\ReturnKind;
use App\Enums\ReviewDesk;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\Audit;
use App\Workflow\CaseTransitionService;
use App\Workflow\EligibleReviewers;
use App\Workflow\Exceptions\GateNotPassed;
use App\Workflow\TransitionRule;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Slice 10e: the CMO sends a RETURNED_TO_CMO case onward.
 *
 *   technocrats  T11 -> IN_REVIEW   a new technical round: every active technical
 *                                   assignment is completed (read-only history),
 *                                   then one active row per chosen Technocrat;
 *                                   T11 resets the technical track. Funding,
 *                                   Legal and registry rows are not touched.
 *   legal        T12 -> WITH_LEGAL  back to the Legal officer(s) still actively
 *                                   assigned; refused when there are none.
 *   ps           T17 -> WITH_PS     only when the PS returned the case (also
 *                                   enforced by the T17 guard).
 *
 * The note is the transition reason (case_transitions.reason); the audit row
 * records only that a note was given and its length. Authorised by the
 * permission of the matching rule through CasePolicy::act. Fail closed.
 */
final class RouteReturnedCase
{
    public const TECHNOCRATS = 'technocrats';

    public const LEGAL = 'legal';

    public const PS = 'ps';

    public const DESTINATIONS = [self::TECHNOCRATS, self::LEGAL, self::PS];

    public const NOTE_MIN = 10;

    public const NOTE_MAX = 500;

    public const REVIEWERS_MIN = 1;

    public const REVIEWERS_MAX = 3;

    public const NO_LEGAL_OFFICER = 'No Legal officer is assigned to this case';

    public function __construct(
        private readonly CaseTransitionService $transitions,
        private readonly EligibleReviewers $eligible,
    ) {}

    public static function targetStatus(string $destination): CaseStatus
    {
        return match ($destination) {
            self::TECHNOCRATS => CaseStatus::IN_REVIEW,
            self::LEGAL => CaseStatus::WITH_LEGAL,
            self::PS => CaseStatus::WITH_PS,
        };
    }

    /** T11, T12 or T17. */
    public static function rule(string $destination): TransitionRule
    {
        return TransitionRules::find(CaseStatus::RETURNED_TO_CMO, self::targetStatus($destination))
            ?? throw new \LogicException("No enabled rule for destination {$destination}.");
    }

    /** The latest move into RETURNED_TO_CMO (the banner's row). */
    public static function latestReturn(ProcurementCase $case): ?CaseTransition
    {
        return CaseTransition::query()
            ->where('case_id', $case->getKey())
            ->where('to_status', CaseStatus::RETURNED_TO_CMO->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Destinations open from the source desk: Technocrats and Legal always; the
     * PS only when the PS returned the case.
     *
     * @return list<string>
     */
    public static function allowedDestinations(ProcurementCase $case): array
    {
        return self::latestReturn($case)?->from_status === CaseStatus::WITH_PS
            ? self::DESTINATIONS
            : [self::TECHNOCRATS, self::LEGAL];
    }

    /** Legal return -> Technocrats; PS legal-related -> Legal; PS technical -> Technocrats. */
    public static function suggested(ProcurementCase $case): string
    {
        $row = self::latestReturn($case);

        return $row?->from_status === CaseStatus::WITH_PS && $row->return_kind === ReturnKind::Legal
            ? self::LEGAL
            : self::TECHNOCRATS;
    }

    /** May $user route this case onward now (to at least one destination)? The checks handle() runs. */
    public static function canRoute(ProcurementCase $case, User $user): bool
    {
        if ($case->status !== CaseStatus::RETURNED_TO_CMO) {
            return false;
        }

        $gate = Gate::forUser($user);
        foreach (self::allowedDestinations($case) as $destination) {
            if ($gate->allows('act', [$case, self::rule($destination)->permission])) {
                return true;
            }
        }

        return false;
    }

    /** Destinations $user may choose now (allowed by source AND by permission). @return list<string> */
    public static function destinationsFor(ProcurementCase $case, User $user): array
    {
        $gate = Gate::forUser($user);

        return array_values(array_filter(
            self::allowedDestinations($case),
            fn (string $d) => $gate->allows('act', [$case, self::rule($d)->permission]),
        ));
    }

    /** Active, named Legal assignments on the case. */
    public static function activeLegalCount(ProcurementCase $case): int
    {
        return CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->where('purpose', SendToLegal::PURPOSE)
            ->whereNotNull('user_id')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->distinct()
            ->count('user_id');
    }

    /**
     * @param  array<mixed>  $reviewerIds
     *
     * @throws ValidationException
     */
    public function handle(ProcurementCase $case, User $actor, string $destination, ?string $note, array $reviewerIds = []): StepResult
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $case);

        if (! in_array($destination, self::DESTINATIONS, true)) {
            throw ValidationException::withMessages(['destination' => 'Choose where to send the case.']);
        }

        $note = $note === null ? '' : trim($note);
        $length = mb_strlen($note);
        if ($length < self::NOTE_MIN || $length > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => 'Give a note of '.self::NOTE_MIN.' to '.self::NOTE_MAX.' characters.']);
        }

        if ($destination !== self::TECHNOCRATS && $reviewerIds !== []) {
            throw ValidationException::withMessages(['reviewers' => 'Technocrats are chosen only when sending the case to the Technocrats.']);
        }

        $rule = self::rule($destination);

        return DB::transaction(function () use ($case, $actor, $gate, $destination, $note, $length, $reviewerIds, $rule) {
            $locked = ProcurementCase::withoutGlobalScopes()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            $gate->authorize('act', [$locked, $rule->permission]);

            if ($locked->status !== CaseStatus::RETURNED_TO_CMO) {
                return $this->alreadyRouted($locked) ?? throw ValidationException::withMessages([
                    'route' => 'Only a case returned to the CMO can be sent onward.',
                ]);
            }

            if (! in_array($destination, self::allowedDestinations($locked), true)) {
                throw ValidationException::withMessages(['destination' => TransitionRules::GUARD_MESSAGES['returned_by_ps'].'.']);
            }

            $chosen = $destination === self::TECHNOCRATS ? $this->chosenTechnocrats($reviewerIds) : [];

            $legalCount = self::activeLegalCount($locked);
            if ($destination === self::LEGAL && $legalCount === 0) {
                throw ValidationException::withMessages(['route' => self::NO_LEGAL_OFFICER.'.']);
            }

            try {
                $this->transitions->transition($locked, self::targetStatus($destination), $actor, $note);
            } catch (GateNotPassed $e) {
                throw ValidationException::withMessages([
                    'destination' => implode('; ', array_map(fn (string $k) => TransitionRules::GUARD_MESSAGES[$k] ?? $k, $e->missing)).'.',
                ]);
            }

            $completed = $destination === self::TECHNOCRATS ? $this->closeTechnicalRound($locked, $actor) : 0;

            foreach ($chosen as $reviewer) {
                $this->assign($locked, $actor, $reviewer);
            }

            // Counts and flags only: the note text stays in case_transitions.reason.
            Audit::record(
                AuditAction::CaseRoutedOnward,
                ['type' => 'case', 'id' => $locked->getKey()],
                $locked->getKey(),
                [
                    'destination' => $destination,
                    'rule' => $rule->code,
                    'reviewers' => count($chosen),
                    'reviewers_completed' => $completed,
                    'legal_officers' => $destination === self::LEGAL ? $legalCount : 0,
                    'has_note' => true,
                    'note_length' => $length,
                ],
                $actor,
            );

            return StepResult::done($locked->refresh());
        });
    }

    /** The latest move out of RETURNED_TO_CMO: who sent it onward, when and where. */
    private function alreadyRouted(ProcurementCase $case): ?StepResult
    {
        $row = CaseTransition::query()->with('actor:id,name')
            ->where('case_id', $case->getKey())
            ->where('from_status', CaseStatus::RETURNED_TO_CMO->value)
            ->orderByDesc('id')
            ->first();

        return $row ? StepResult::already($case, $row->actor?->name, $row->created_at, $row->to_status->label()) : null;
    }

    /**
     * Duplicates collapse; then 1 to 3 active staff users holding the technical
     * review permission (never a role name).
     *
     * @param  array<mixed>  $ids
     * @return list<User>
     *
     * @throws ValidationException
     */
    private function chosenTechnocrats(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $clean[(int) $id] = (int) $id;
            } else {
                throw ValidationException::withMessages(['reviewers' => 'Choose from the listed Technocrats.']);
            }
        }

        if (count($clean) < self::REVIEWERS_MIN || count($clean) > self::REVIEWERS_MAX) {
            throw ValidationException::withMessages(['reviewers' => 'Choose '.self::REVIEWERS_MIN.' to '.self::REVIEWERS_MAX.' Technocrats.']);
        }

        $eligible = $this->eligible->among(ReviewDesk::Technical, array_values($clean));
        if (count($eligible) !== count($clean)) {
            throw ValidationException::withMessages(['reviewers' => 'Choose from the listed Technocrats. One or more of the people chosen cannot take technical review.']);
        }

        return array_values(array_map(fn (int $id) => $eligible[$id], $clean));
    }

    /** Completes every active technical assignment (the earlier round). Returns how many. */
    private function closeTechnicalRound(ProcurementCase $case, User $actor): int
    {
        $rows = CaseAssignment::query()
            ->where('case_id', $case->getKey())
            ->where('purpose', ReviewDesk::Technical->value)
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->get();

        $now = CarbonImmutable::now('UTC');
        foreach ($rows as $row) {
            $row->forceFill(['completed_at' => $now])->save();

            Audit::record(
                AuditAction::CaseAssignmentCompleted,
                ['type' => 'case_assignment', 'id' => $row->getKey()],
                $case->getKey(),
                ['role' => $row->role, 'user_id' => $row->user_id !== null ? (int) $row->user_id : null, 'purpose' => $row->purpose, 'reason' => 'new_review_round'],
                $actor,
            );
        }

        return $rows->count();
    }

    private function assign(ProcurementCase $case, User $actor, User $reviewer): void
    {
        $desk = ReviewDesk::Technical;
        $assignment = (new CaseAssignment)->forceFill([
            'case_id' => $case->getKey(),
            'role' => $desk->assignmentRole(),
            'purpose' => $desk->value,
            'user_id' => $reviewer->getKey(),
            'assigned_by' => $actor->getKey(),
            'assigned_at' => CarbonImmutable::now('UTC'),
        ]);
        $assignment->save();

        Audit::record(
            AuditAction::CaseAssignmentCreated,
            ['type' => 'case_assignment', 'id' => $assignment->getKey()],
            $case->getKey(),
            ['role' => $desk->assignmentRole(), 'user_id' => (int) $reviewer->getKey(), 'purpose' => $desk->value],
            $actor,
        );
    }
}
