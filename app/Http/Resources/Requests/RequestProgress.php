<?php

namespace App\Http\Resources\Requests;

use App\Enums\CaseStatus;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use Carbon\CarbonInterface;

/**
 * OVERNIGHT-2 (B1): the vendor's "Progress" timeline on their request page.
 *
 * Built from case_transitions and the hardcopy record, in vendor-facing
 * groups only (CaseStatus::vendorGroup): every internal stage is one
 * "Under Review", so consecutive internal moves (intake, review, file, Legal,
 * PS, a return to the CMO) show once, dated when the case first entered it.
 * Each milestone is a label and a plain date: no actor, no reason, no
 * internal stage name, no time in stage. Drafts have no timeline.
 */
final class RequestProgress
{
    /** Title Case milestone label per vendor group. */
    public const LABELS = [
        'SUBMITTED' => 'Submitted',
        'UNDER_REVIEW' => 'Under Review',
        'LETTER_ISSUED' => 'Letter Issued',
        'CLOSED' => 'Closed',
        'WITHDRAWN' => 'Withdrawn',
        'NOT_APPROVED' => 'Not Approved',
    ];

    public const HARDCOPY = 'Hardcopy Received';

    /** @return list<array{key: string, label: string, date: string, date_label: string}> */
    public static function for(ProcurementCase $case): array
    {
        if ($case->status === CaseStatus::DRAFT) {
            return [];
        }

        $milestones = [];
        $lastGroup = null;

        $transitions = CaseTransition::query()
            ->where('case_id', $case->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['to_status', 'created_at']);

        foreach ($transitions as $transition) {
            $group = $transition->to_status->vendorGroup();

            if ($group === 'DRAFT' || $group === $lastGroup) {
                continue;
            }

            $lastGroup = $group;
            $milestones[] = self::milestone(strtolower($group), self::LABELS[$group], $transition->created_at);
        }

        // A case submitted before transitions were recorded still shows when it was submitted.
        if ($milestones === [] && $case->submitted_at !== null) {
            $milestones[] = self::milestone('submitted', self::LABELS['SUBMITTED'], $case->submitted_at);
        }

        if ($case->hardcopyReceived()) {
            // A calendar date as recorded: no time zone shift.
            $day = $case->hardcopy_received_on->format('Y-m-d');
            $milestones[] = ['key' => 'hardcopy', 'label' => self::HARDCOPY, 'date' => $day, 'date_label' => $case->hardcopy_received_on->format('j M Y')];
        }

        // Stable: on the same day, the order above (status first, then hardcopy) is kept.
        usort($milestones, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        // Keys stay unique even if a group were ever entered twice.
        $seen = [];
        foreach ($milestones as $i => $m) {
            $seen[$m['key']] = ($seen[$m['key']] ?? 0) + 1;
            if ($seen[$m['key']] > 1) {
                $milestones[$i]['key'] = $m['key'].'-'.$seen[$m['key']];
            }
        }

        return array_values($milestones);
    }

    /** @return array{key: string, label: string, date: string, date_label: string} */
    private static function milestone(string $key, string $label, CarbonInterface $at): array
    {
        $local = $at->copy()->setTimezone('America/Port_of_Spain');

        return ['key' => $key, 'label' => $label, 'date' => $local->format('Y-m-d'), 'date_label' => $local->format('j M Y')];
    }
}
