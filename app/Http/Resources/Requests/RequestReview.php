<?php

namespace App\Http\Resources\Requests;

use App\Models\ProcurementCase;
use App\Submissions\ReadinessReport;

/**
 * OVERNIGHT-3 (A1): what the vendor's Review page shows when the draft is not
 * ready to submit: one line per readiness issue, in form order, deduplicated
 * the way the form's error summary is ("Section: message"), each linking back
 * to its section on the form. Every word is the server's.
 */
final class RequestReview
{
    /** Why Submit Request is disabled while something is missing. */
    public const NOT_READY_REASON = 'Fix the items listed above before you submit.';

    /** The page's description under the heading when the draft is ready. */
    public const READY_TEXT = 'Everything below is saved. Check it, then submit.';

    /**
     * @return list<array{key: string, section_label: string, message: string, href: string}>
     */
    public static function blockers(ProcurementCase $case, ReadinessReport $report): array
    {
        $edit = route('requests.edit', $case);
        $seen = [];
        $items = [];

        foreach (AdHocSections::requestSections($case, $report) as $section) {
            foreach ($section['issues'] as $issue) {
                $id = $section['label'].'|'.$issue['message'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $items[] = [
                    'key' => $issue['key'],
                    'section_label' => $section['label'],
                    'message' => $issue['message'],
                    'href' => $edit.'#'.$section['anchor'],
                ];
            }
        }

        return $items;
    }
}
