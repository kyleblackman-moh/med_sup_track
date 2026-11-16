<?php

namespace App\Support;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Http\Resources\Cases\StaffCaseSummary;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Actions\RouteReturnedCase;
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
            'intake' => ['New submissions', 'Log intake', [Permission::CaseIntake], [CaseStatus::SUBMITTED], 'status'],
            'hardcopy' => ['Hardcopy pending', 'Record hardcopy', [Permission::HardcopyConfirm], RecordHardcopy::STATUSES, 'submitted'],
            'forward' => ['To forward for review', 'Forward for review', [Permission::CaseRouteToReview], [CaseStatus::CMO_INTAKE], 'status'],
            'gate' => ['Gate passed', 'Mark ready for file', [Permission::FilePrepare], [CaseStatus::IN_REVIEW], 'status'],
            'prepare' => ['Files to prepare', 'Prepare file', [Permission::FilePrepare], [CaseStatus::READY_FOR_FILE], 'status'],
            'legal' => ['To send to Legal', 'Send to Legal', [Permission::FilePrepare], [CaseStatus::FILE_PREPARED], 'status'],
            // Any permission of a routing rule out of RETURNED_TO_CMO; canRoute() decides per case.
            'returned' => ['Returned to CMO', 'Route onward', self::routePermissions(), [CaseStatus::RETURNED_TO_CMO], 'status'],
            'letter' => ['Letters to draft', 'Issue letter', [Permission::LetterIssue], [CaseStatus::NON_OBJECTION_GRANTED], 'status'],
            'close' => ['To close', 'Confirm dispatch and close', [Permission::LetterIssue], [CaseStatus::LETTER_ISSUED], 'status'],
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
            ->with(['organisation:id,name', 'requisition:id,case_id,item_description'])
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
                // The same checks the action runs: CasePolicy::act, or for a returned
                // case RouteReturnedCase::canRoute (act for each allowed destination).
                $allowed = $key === 'returned'
                    ? RouteReturnedCase::canRoute($case, $user)
                    : $gate->allows('act', [$case, $permissions[0]->value]);
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
                ];
            }
        }

        // Oldest first; unknown ages last.
        usort($items, fn (array $a, array $b) => [$a['waiting_since'] === null, $a['waiting_since']] <=> [$b['waiting_since'] === null, $b['waiting_since']]);

        $counts = array_count_values(array_column($items, 'step'));
        $groups = [];
        foreach ($steps as $key => [$label]) {
            $groups[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0];
        }

        return ['groups' => $groups, 'items' => $items, 'truncated' => $truncated];
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
