<?php

namespace App\Support;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\ReviewDesk;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Queries\QueryAccess;
use App\Queries\QueryInbox;
use App\Submissions\ExpiryRisk;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RouteReturnedCase;
use App\Workflow\TrackCalculator;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * "Needs my action" queue for the dashboard (spec 12.2 A). Each item is one
 * case and one step the user may take on it NOW: the same permission and
 * CasePolicy::act check the action routes use. Display only: the row links to
 * the case page, where the action itself is authorised again.
 */
final class WorkQueue
{
    /** Hard cap on candidate cases, so the dashboard stays fast. */
    public const MAX_CASES = 300;

    /**
     * key => [group label, button text, permissions (holding any one makes it a candidate), statuses, waiting measured from].
     * 'status' = when the case entered its current status; 'submitted' = submission.
     * Statuses and permissions come from the action classes and TransitionRules
     * where those define them, so the queue cannot drift from the actions.
     *
     * @return array<string, array{0: string, 1: string, 2: list<Permission>, 3: list<CaseStatus>, 4: string}>
     */
    private static function steps(): array
    {
        return [
            'intake' => ['New Submissions', 'Log Intake', [Permission::CaseIntake], [CaseStatus::SUBMITTED], 'status'],
            'hardcopy' => ['Hardcopy Pending', 'Record Hardcopy', [Permission::HardcopyConfirm], RecordHardcopy::STATUSES, 'submitted'],
            'forward' => ['To Forward for Review', 'Forward for Review', [Permission::CaseRouteToReview], [CaseStatus::CMO_INTAKE], 'status'],
            'gate' => ['Gate Passed', 'Mark Ready for File', [Permission::FilePrepare], [CaseStatus::IN_REVIEW], 'status'],
            'prepare' => ['Files to Prepare', 'Prepare File', [Permission::FilePrepare], [CaseStatus::READY_FOR_FILE], 'status'],
            'legal' => ['To Send to Legal', 'Send to Legal', [Permission::FilePrepare], [CaseStatus::FILE_PREPARED], 'status'],
            // Any permission of a routing rule out of RETURNED_TO_CMO; canRoute() decides per case.
            'returned' => ['Returned to CMO', 'Route Onward', self::routePermissions(), [CaseStatus::RETURNED_TO_CMO], 'status'],
            'letter' => ['Letters to Draft', 'Issue Letter', [Permission::LetterIssue], [CaseStatus::NON_OBJECTION_GRANTED], 'status'],
            'close' => ['To Close', 'Confirm Dispatch and Close', [Permission::LetterIssue], [CaseStatus::LETTER_ISSUED], 'status'],
            // Assigned reviewers (TrackCalculator::canRecordReview), only until they record an outcome.
            'technical' => ['Technical Reviews', 'Record Technical Review', [ReviewDesk::Technical->permission()], [CaseStatus::IN_REVIEW], 'status'],
            'funding' => ['Funding Confirmations', 'Record Funding Outcome', [ReviewDesk::Funding->permission()], [CaseStatus::IN_REVIEW], 'status'],
            // The assigned Legal officer (RecordLegalDecision::isAssigned).
            'legal_decision' => ['Contract Decisions', 'Record Legal Decision', [Permission::LegalConfirm], [CaseStatus::WITH_LEGAL], 'status'],
            'ps_decision' => ['Non-Objection Decisions', 'Record PS Decision', [Permission::NonObjectionGrant], [CaseStatus::WITH_PS], 'status'],
        ];
    }

    /** @return list<Permission> */
    private static function routePermissions(): array
    {
        return collect(RouteReturnedCase::DESTINATIONS)
            ->map(fn (string $d) => Permission::from(RouteReturnedCase::rule($d)->permission))
            ->unique()->values()->all();
    }

    /** Does the user hold any of the step's permissions? */
    private static function holdsAny(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The statuses where this user has queue steps: their "desk" on the
     * dashboard's stage rail. Empty for vendors and users with no queue.
     *
     * @return list<CaseStatus>
     */
    public static function deskStatuses(?User $user): array
    {
        if (! self::appliesTo($user)) {
            return [];
        }

        return collect(self::steps())
            ->filter(fn (array $step) => self::holdsAny($user, $step[2]))
            ->flatMap(fn (array $step) => $step[3])
            ->unique(fn (CaseStatus $s) => $s->value)
            ->values()
            ->all();
    }

    /** Does the user hold any queue permission? Vendors never get a queue. */
    public static function appliesTo(?User $user): bool
    {
        if ($user === null || $user->isVendor()) {
            return false;
        }

        foreach (self::steps() as [, , $permissions]) {
            if (self::holdsAny($user, $permissions)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{groups: list<array{key: string, label: string, count: int}>, items: list<array<string, mixed>>, truncated: bool}|null */
    public static function for(?User $user): ?array
    {
        if (! self::appliesTo($user)) {
            return null;
        }

        $steps = array_filter(self::steps(), fn (array $step) => self::holdsAny($user, $step[2]));
        $statuses = collect($steps)->flatMap(fn (array $step) => array_map(fn (CaseStatus $s) => $s->value, $step[3]))->unique()->values()->all();

        // Visible cases only: ProcurementCase's global scope applies for the signed-in user.
        $cases = ProcurementCase::query()
            ->with(['organisation:id,name', 'requisition:id,case_id,item_description,'.implode(',', ExpiryRisk::REQUISITION_COLUMNS), 'stockExpiries:'.implode(',', ExpiryRisk::BATCH_COLUMNS)])
            ->whereIn('cases.status', $statuses)
            ->select('cases.*')
            ->addSelect(['entered_status_at' => CaseTransition::query()
                ->selectRaw('MAX(case_transitions.created_at)')
                ->whereColumn('case_transitions.case_id', 'cases.id')
                ->whereColumn('case_transitions.to_status', 'cases.status')])
            ->orderBy('cases.submitted_at')
            ->limit(self::MAX_CASES + 1)
            ->get();

        $truncated = $cases->count() > self::MAX_CASES;
        $today = CarbonImmutable::now(StaffCaseSummary::TIMEZONE)->startOfDay();
        $gate = Gate::forUser($user);
        $items = [];

        foreach ($cases->take(self::MAX_CASES) as $case) {
            foreach ($steps as $key => [, $action, $permissions, $stepStatuses, $from]) {
                if (! in_array($case->status, $stepStatuses, true) || ! self::stepApplies($key, $case)) {
                    continue;
                }
                // The same checks the case page uses for the action: CasePolicy::act,
                // plus the action's own predicate where it has one.
                $allowed = match ($key) {
                    'returned' => RouteReturnedCase::canRoute($case, $user),
                    'technical' => self::reviewPending($case, $user, ReviewDesk::Technical),
                    'funding' => self::reviewPending($case, $user, ReviewDesk::Funding),
                    'legal_decision' => $gate->allows('act', [$case, $permissions[0]->value])
                        && RecordLegalDecision::isAssigned($case, $user),
                    default => $gate->allows('act', [$case, $permissions[0]->value]),
                };
                if (! $allowed) {
                    continue;
                }

                $since = $from === 'submitted' ? $case->submitted_at : ($case->entered_status_at ?? $case->submitted_at);
                $since = $since !== null ? CarbonImmutable::parse($since)->utc() : null;

                $items[] = [
                    'id' => "{$case->id}:{$key}",
                    'case_id' => $case->id,
                    'step' => $key,
                    'action' => $action,
                    'reference_no' => $case->reference_no,
                    'item_description' => $case->requisition?->item_description !== null
                        ? Str::limit($case->requisition->item_description, 80)
                        : null,
                    'organisation' => $case->organisation?->name,
                    'status_label' => $case->status->label(),
                    'status_tone' => $case->status->tone(),
                    'waiting_since' => $since?->toIso8601ZuluString(),
                    'days_waiting' => $since !== null
                        ? (int) $since->setTimezone(StaffCaseSummary::TIMEZONE)->startOfDay()->diffInDays($today)
                        : null,
                    // Kyle, 2026-11-23: stock may expire before use (ExpiryRisk::flag); null when nothing to flag.
                    'expiry_flag' => ExpiryRisk::flag($case),
                ];
            }
        }

        // OVERNIGHT-2 (D4): query work, on any open case the user can see.
        array_push($items, ...self::queryItems($user, $today));

        // Oldest first; unknown ages last.
        usort($items, fn (array $a, array $b) => [$a['waiting_since'] === null, $a['waiting_since']] <=> [$b['waiting_since'] === null, $b['waiting_since']]);

        $counts = array_count_values(array_column($items, 'step'));
        $groups = [];
        foreach ($steps as $key => [$label]) {
            $groups[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0];
        }
        foreach (self::QUERY_STEPS as $key => [$label]) {
            if (($counts[$key] ?? 0) > 0 || ($key === 'query_forward' && QueryAccess::isCmoDesk($user))) {
                $groups[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0];
            }
        }

        return ['groups' => $groups, 'items' => $items, 'truncated' => $truncated];
    }

    /** OVERNIGHT-2 (D4): query rows, keyed like the steps: [group label, button text]. */
    public const QUERY_STEPS = [
        'query_forward' => ['Query to Forward', 'Forward Query'],
        'query_answer' => ['Query to Answer', 'Answer Query'],
    ];

    /**
     * One row per case and query step, by the same predicates as the Queries
     * tab and the Next Step card (QueryAccess::forwardable, needsAnswer).
     * Waiting since: the held message (forward) or the last delivered message
     * (answer), oldest first across the case's queries.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryItems(User $user, CarbonImmutable $today): array
    {
        // QUERIES-1: the same rows as the Queries page (QueryInbox), grouped per case
        // and step here; the queue keeps its case-visibility rule (no named officers).
        $due = [];
        foreach (QueryInbox::due($user, includeNamed: false) as ['case' => $case, 'step' => $step, 'since' => $since]) {
            $key = "{$case->id}:{$step}";
            if (isset($due[$key]) && $due[$key]['since'] !== null && ($since === null || $due[$key]['since']->lte($since))) {
                continue;
            }
            $due[$key] = ['case' => $case, 'step' => $step, 'since' => $since];
        }

        return array_values(array_map(fn (array $d) => [
            'id' => "{$d['case']->id}:{$d['step']}",
            'case_id' => $d['case']->id,
            'step' => $d['step'],
            'action' => self::QUERY_STEPS[$d['step']][1],
            'reference_no' => $d['case']->reference_no,
            'item_description' => $d['case']->requisition?->item_description !== null
                ? Str::limit($d['case']->requisition->item_description, 80)
                : null,
            'organisation' => $d['case']->organisation?->name,
            'status_label' => $d['case']->status->label(),
            'status_tone' => $d['case']->status->tone(),
            'waiting_since' => $d['since']?->toIso8601ZuluString(),
            'days_waiting' => $d['since'] !== null
                ? (int) $d['since']->setTimezone(StaffCaseSummary::TIMEZONE)->startOfDay()->diffInDays($today)
                : null,
            // The same flag as the case rows (query rows are few: only queries waiting on this user).
            'expiry_flag' => ExpiryRisk::flag($d['case']),
        ], $due));
    }

    /**
     * Review step: the case page's rule (TrackCalculator::canRecordReview, which
     * includes CasePolicy::act), and only while this reviewer has no outcome in
     * their current assignment (TrackCalculator's same-round rule). The case page
     * keeps the form after that so the outcome can be changed; that is not
     * queue work.
     */
    private static function reviewPending(ProcurementCase $case, User $user, ReviewDesk $desk): bool
    {
        return TrackCalculator::canRecordReview($case, $user, $desk)
            && TrackCalculator::reviewers($case, $desk)[(int) $user->getKey()]['latest'] === null;
    }

    /**
     * Conditions beyond status and permission. Hardcopy: not yet received (the
     * case page also offers a correction once received; that is not queue work).
     * Gate: nothing missing, by the one gate definition in TransitionRules.
     */
    private static function stepApplies(string $key, ProcurementCase $case): bool
    {
        return match ($key) {
            'hardcopy' => ! $case->hardcopyReceived(),
            'gate' => TransitionRules::gateMissing($case) === [],
            default => true,
        };
    }
}
