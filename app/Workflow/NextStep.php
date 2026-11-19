<?php

namespace App\Workflow;

use App\Enums\CaseStatus as S;
use App\Enums\Permission;
use App\Enums\ReviewDesk;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RouteReturnedCase;
use App\Workflow\Actions\SendToLegal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/**
 * NEXT-STEP-1: the Next Step card's DTO for one staff user on one case: where
 * the file is, who holds it and since when, what happens next, what is still
 * missing, and the actions this user may take now.
 *
 * Nothing here decides what is allowed. Each action's permission comes from
 * its rule in TransitionRules and is checked through CasePolicy::act; the
 * extra checks are the actions' own (RecordLegalDecision::isAssigned,
 * TrackCalculator::canRecordReview, RouteReturnedCase::destinationsFor), and
 * the In Review checklist is TransitionRules::gateMissing. Wording is in
 * StageWording. No deadlines, no lateness: plain dates only.
 *
 * Staff only (built for the staff case page, which vendors cannot open).
 */
final class NextStep
{
    public const TIMEZONE = 'America/Port_of_Spain';

    /** Assignment purposes whose named, active users hold the file at a stage. */
    private const HOLDER_PURPOSES = [
        S::CMO_INTAKE->value => ['intake'],
        S::IN_REVIEW->value => [ReviewDesk::Technical->value, ReviewDesk::Funding->value],
        S::WITH_LEGAL->value => [SendToLegal::PURPOSE],
    ];

    private string $vendor;

    private string $reference;

    private function __construct(private readonly ProcurementCase $case, private readonly User $user)
    {
        $this->vendor = (string) ($case->organisation?->name ?? 'the vendor');
        $this->reference = (string) ($case->reference_no ?? 'this case');
    }

    /** @return array<string, mixed> */
    public static function for(ProcurementCase $case, User $user): array
    {
        $case->loadMissing('organisation:id,name');

        return (new self($case, $user))->build();
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $status = $this->case->status;
        $words = StageWording::for($status, $this->vendor);
        $checklist = $this->checklist();
        $actions = $this->actions($checklist);
        $since = $this->since();

        return [
            'stage' => $words['stage'],
            'holder' => $words['holder'],
            'assigned' => $this->assigned(),
            'since' => $since?->toDateString(),
            'since_label' => $since?->format('j M Y'),
            'instruction' => $this->instruction($words['instruction']),
            'checklist' => $checklist,
            'actions' => $actions,
        ];
    }

    private function instruction(string $default): string
    {
        if ($this->case->status !== S::RETURNED_TO_CMO) {
            return $default;
        }

        $row = RouteReturnedCase::latestReturn($this->case);
        $text = StageWording::RETURNED_BY[$row?->from_status?->value] ?? null;

        return $text === null ? $default : str_replace('{kind}', $row?->return_kind?->queryLabel() ?? 'query', $text);
    }

    /** @return list<string> */
    private function assigned(): array
    {
        $purposes = self::HOLDER_PURPOSES[$this->case->status->value] ?? [];
        if ($purposes === []) {
            return [];
        }

        return CaseAssignment::query()->with('user:id,name')
            ->where('case_id', $this->case->getKey())
            ->whereIn('purpose', $purposes)
            ->whereNotNull('user_id')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->orderBy('id')->get()
            ->map(fn (CaseAssignment $a) => $a->user?->name)
            ->filter()->unique()->values()->all();
    }

    /** The day the case arrived at its current status (Port of Spain). */
    private function since(): ?CarbonInterface
    {
        $at = CaseTransition::query()
            ->where('case_id', $this->case->getKey())
            ->where('to_status', $this->case->status->value)
            ->orderByDesc('id')
            ->value('created_at') ?? $this->case->submitted_at;

        if ($at === null) {
            return null;
        }

        return ($at instanceof CarbonInterface ? CarbonImmutable::instance($at) : CarbonImmutable::parse($at, 'UTC'))
            ->utc()->setTimezone(self::TIMEZONE);
    }

    // Checklist -----------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function checklist(): array
    {
        $status = $this->case->status;
        $items = [];

        if (in_array($status, RecordHardcopy::STATUSES, true)) {
            $items[] = $this->hardcopyItem();
        }

        if ($status === S::IN_REVIEW) {
            $missing = TransitionRules::gateMissing($this->case);
            foreach ([ReviewDesk::Technical, ReviewDesk::Funding] as $desk) {
                $track = $this->case->{$desk->track()};
                $items[] = [
                    'key' => $desk->value,
                    'label' => $desk === ReviewDesk::Technical ? 'Technical Consideration' : 'Funding Confirmed',
                    'done' => ! in_array($desk->value, $missing, true),
                    'state' => $track?->label() ?? 'Not started',
                    'holder' => $desk === ReviewDesk::Technical ? StageWording::TECHNOCRATS : StageWording::CAB,
                    'people' => TrackCalculator::reviewers($this->case, $desk)
                        ->map(fn (array $r) => $r['assignment']->user?->name)->filter()->values()->all(),
                    'hardcopy_form' => null,
                ];
            }
            $items[0]['done'] = ! in_array('hardcopy', $missing, true);
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function hardcopyItem(): array
    {
        $received = $this->case->hardcopyReceived();
        $by = $received ? User::query()->whereKey($this->case->hardcopy_received_by)->value('name') : null;
        $on = $received ? $this->case->hardcopy_received_on->format('j M Y') : null;
        $canRecord = ! $received && Gate::forUser($this->user)->allows('act', [$this->case, Permission::HardcopyConfirm->value]);

        return [
            'key' => 'hardcopy',
            'label' => 'Hardcopy Received',
            'done' => $received,
            'state' => $received ? 'Received on '.$on.($by ? ' by '.$by : '') : 'Not received yet',
            'holder' => StageWording::CMO_OFFICE,
            'people' => [],
            // The inline Date Received and Save, only for a user who may record it now.
            'hardcopy_form' => $canRecord ? [
                'today' => CarbonImmutable::now(self::TIMEZONE)->toDateString(),
                'earliest' => $this->case->submitted_at?->copy()->setTimezone(self::TIMEZONE)->toDateString(),
            ] : null,
        ];
    }

    // Actions -------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $checklist
     * @return list<array<string, mixed>>
     */
    private function actions(array $checklist): array
    {
        $r = $this->reference;

        $actions = match ($this->case->status) {
            S::SUBMITTED => [$this->transition('intake', S::CMO_INTAKE, 'Accept Into Intake', null,
                "Accept {$r} into intake?",
                "The case moves to CMO intake in the CMO's Office and is assigned to you. Other registry officers will no longer see it in their list.")],
            S::CMO_INTAKE => [$this->transition('forward_for_review', S::IN_REVIEW, null, StageWording::TECHNOCRATS_AND_CAB,
                "Send {$r} to the Technocrats and CAB?",
                'The Technocrats you choose consider the request and the CAB officers you choose confirm funding, at the same time. The case moves to In Review.')],
            S::IN_REVIEW => [
                ...$this->reviewActions(),
                $this->transition('mark_ready_for_file', S::READY_FOR_FILE, 'Mark Ready for File', null,
                    "Mark {$r} ready for file?",
                    "The hardcopy, technical consideration and funding are all done. The case moves to Ready for File and stays with the CMO's Office to prepare the file.",
                    blocked: $this->gateBlocked($checklist)),
            ],
            S::READY_FOR_FILE => [$this->transition('prepare_file', S::FILE_PREPARED, 'Prepare File', null,
                "Prepare the file for {$r}",
                "Enter the file number. The case moves to File Prepared and stays with the CMO's Office.")],
            S::FILE_PREPARED => [$this->transition('send_to_legal', S::WITH_LEGAL, null, StageWording::LEGAL_UNIT,
                "Send {$r} to the Legal Unit?",
                'The Legal officers you choose are assigned and the case moves to With Legal. It stays with the Legal Unit until they record a decision.')],
            S::WITH_LEGAL => [$this->legalDecision()],
            S::WITH_PS => [$this->transition('ps_decision', S::NON_OBJECTION_GRANTED, 'Record PS Decision', null,
                "Record the PS decision for {$r}",
                "Grant non-objection, and the file goes back to the CMO's Office for the letter. Or return it to the CMO's Office with a query.")],
            S::NON_OBJECTION_GRANTED => [$this->transition('issue_letter', S::LETTER_ISSUED, 'Issue Letter to '.$this->vendor, $this->vendor,
                'Issue the letter to '.$this->vendor.'?',
                'Send the signed letter to '.$this->vendor.' by email and hardcopy yourself, then record the dates here. The case moves to Letter Issued.')],
            S::LETTER_ISSUED => [$this->transition('close_case', S::CLOSED, 'Close Case', null,
                "Close {$r}?",
                'A closed case is read-only. It cannot be reopened.')],
            S::RETURNED_TO_CMO => $this->routeActions(),
            default => [],
        };

        $actions = array_values(array_filter($actions));

        // ONE primary: the suggested route on a returned case, else the first one this user can take now.
        $primary = null;
        foreach ($actions as $i => $action) {
            if ($action['enabled'] && ($primary === null || ($action['suggested'] ?? false))) {
                $primary = $i;
            }
        }
        $primary ??= $actions === [] ? null : 0;

        return array_map(function (array $a, int $i) use ($primary) {
            unset($a['suggested']);

            return [...$a, 'primary' => $i === $primary];
        }, $actions, array_keys($actions));
    }

    /**
     * An action backed by the rule from the current status to $to: the rule's
     * permission through CasePolicy::act. Null when there is no enabled rule
     * or the user may not act (they see the holder and instruction only).
     *
     * @return array<string, mixed>|null
     */
    private function transition(string $key, S $to, ?string $label, ?string $destination, string $title, string $body, ?string $blocked = null): ?array
    {
        $rule = TransitionRules::find($this->case->status, $to);
        if ($rule === null || ! Gate::forUser($this->user)->allows('act', [$this->case, $rule->permission])) {
            return null;
        }

        return $this->action($key, $label ?? 'Send to '.$destination, $destination, $title, $body, $blocked);
    }

    /** @return array<string, mixed> */
    private function action(string $key, string $label, ?string $destination, string $title, string $body, ?string $blocked = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'destination' => $destination,
            'enabled' => $blocked === null,
            'disabled_reason' => $blocked,
            'route' => null,
            'confirm' => ['title' => $title, 'body' => $body, 'button' => $label],
        ];
    }

    /** T4: blocked until the gate passes; the reason names who holds each missing item. */
    private function gateBlocked(array $checklist): ?string
    {
        $missing = array_filter($checklist, fn (array $item) => ! $item['done']);
        if ($missing === []) {
            return null;
        }

        return 'Waiting on: '.implode('; ', array_map(
            fn (array $item) => $item['label'].' ('.$item['holder'].($item['people'] !== [] ? ': '.implode(', ', $item['people']) : '').')',
            $missing,
        )).'.';
    }

    /** A reviewer's own desk (TrackCalculator::canRecordReview). Not a transition. @return list<array<string, mixed>> */
    private function reviewActions(): array
    {
        $actions = [];
        foreach ([ReviewDesk::Technical, ReviewDesk::Funding] as $desk) {
            if (TrackCalculator::canRecordReview($this->case, $this->user, $desk)) {
                $label = $desk === ReviewDesk::Technical ? 'Record Technical Consideration' : 'Record Funding Confirmation';
                $actions[] = $this->action('record_'.$desk->value, $label, null, $label,
                    "Record your outcome for {$this->reference}. You can change it until the CMO's Office marks the case ready for file.");
            }
        }

        return $actions;
    }

    /** T7/T8: legal.confirm and an active Legal assignment (RecordLegalDecision::isAssigned). @return array<string, mixed>|null */
    private function legalDecision(): ?array
    {
        $action = $this->transition('legal_decision', S::WITH_PS, 'Record Legal Decision', null,
            "Record the Legal decision for {$this->reference}",
            "Confirm the contract, and the file goes to the PS Office. Or return it to the CMO's Office with a query.");

        return $action !== null && RecordLegalDecision::isAssigned($this->case, $this->user) ? $action : null;
    }

    /** T11, T12, T17: only the destinations the rules allow from this return, and this user's permission. @return list<array<string, mixed>> */
    private function routeActions(): array
    {
        $suggested = RouteReturnedCase::suggested($this->case);
        $actions = [];

        foreach (RouteReturnedCase::destinationsFor($this->case, $this->user) as $destination) {
            $name = StageWording::ROUTE_DESTINATIONS[$destination];
            $blocked = $destination === RouteReturnedCase::LEGAL && RouteReturnedCase::activeLegalCount($this->case) === 0
                ? RouteReturnedCase::NO_LEGAL_OFFICER.'.'
                : null;
            $body = match ($destination) {
                RouteReturnedCase::TECHNOCRATS => 'The case returns to In Review. The technical review restarts with the Technocrats you choose; funding and hardcopy stay as recorded.',
                RouteReturnedCase::LEGAL => 'The case goes back to With Legal, to the Legal officers already assigned.',
                RouteReturnedCase::PS => 'The case goes back to the PS Office for a decision.',
            };

            $actions[] = [
                ...$this->action('route_'.$destination, 'Send to '.$name, $name, "Send {$this->reference} to the {$name}?", $body, $blocked),
                'route' => $destination,
                'suggested' => $destination === $suggested,
            ];
        }

        return $actions;
    }
}
