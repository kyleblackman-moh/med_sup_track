<?php

namespace App\Http\Controllers;

use App\Models\ProcurementCase;
use App\Workflow\Actions\IntakeCase;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Exceptions\InvalidTransition;
use App\Workflow\Exceptions\StaleTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Staff case actions (slice 9b). Thin: rules and authorisation live in
 * App\Workflow\Actions (CasePolicy::act with case.intake / hardcopy.confirm).
 * Cases are bound through the visibility scope, so hidden and DRAFT cases are
 * 404; a visible case the user may not act on is 403.
 */
class StaffCaseActionController extends Controller
{
    public function intake(Request $request, ProcurementCase $procurementCase, IntakeCase $intake): RedirectResponse
    {
        try {
            $result = $intake->handle($procurementCase, $request->user());
        } catch (InvalidTransition|StaleTransition) {
            return back()->withErrors(['intake' => 'This case cannot be accepted into intake in its current status.']);
        }

        if ($result->alreadyAccepted) {
            $when = $result->acceptedAt?->copy()->setTimezone(RecordHardcopy::TIMEZONE)->format('j M Y, g:i a');

            return back()->with('info', 'This case was already accepted into intake by '.($result->acceptedBy ?? 'another user').($when ? " on {$when}" : '').'.');
        }

        return back()->with('success', 'Case accepted into intake.');
    }

    public function markHardcopy(Request $request, ProcurementCase $procurementCase, RecordHardcopy $hardcopy): RedirectResponse
    {
        $validated = $request->validate([
            'received_on' => ['required', 'date_format:Y-m-d'],
        ], [
            'received_on.required' => 'Enter the date the hardcopy was received.',
            'received_on.date_format' => 'Enter the date the hardcopy was received.',
        ]);

        $hardcopy->mark($procurementCase, $request->user(), $validated['received_on']);

        return back()->with('success', 'Hardcopy recorded as received.');
    }

    public function reverseHardcopy(Request $request, ProcurementCase $procurementCase, RecordHardcopy $hardcopy): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.RecordHardcopy::REASON_MIN, 'max:'.RecordHardcopy::REASON_MAX],
        ]);

        $hardcopy->reverse($procurementCase, $request->user(), $validated['reason']);

        return back()->with('success', 'Hardcopy record removed.');
    }
}
