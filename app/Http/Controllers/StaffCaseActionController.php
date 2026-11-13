<?php

namespace App\Http\Controllers;

use App\Models\ProcurementCase;
use App\Workflow\Actions\ForwardForReview;
use App\Workflow\Actions\IntakeCase;
use App\Workflow\Actions\MarkReadyForFile;
use App\Workflow\Actions\RecordFundingConfirmation;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Actions\RecordReviewOutcome;
use App\Workflow\Actions\RecordTechnicalReview;
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

    /** T3 (slice 10a). The service re-checks eligibility by permission inside its transaction. */
    public function forwardForReview(Request $request, ProcurementCase $procurementCase, ForwardForReview $forward): RedirectResponse
    {
        // Duplicate ids collapse before the 1-to-3 count is checked.
        foreach (['technocrats', 'cab_officers'] as $field) {
            if (is_array($request->input($field))) {
                $request->merge([$field => array_values(array_unique(array_map('strval', array_filter($request->input($field), 'is_scalar'))))]);
            }
        }

        $max = ForwardForReview::MAX_PER_DESK;
        $validated = $request->validate([
            'technocrats' => ['required', 'array', 'min:1', "max:{$max}"],
            'technocrats.*' => ['integer', 'exists:users,id'],
            'cab_officers' => ['required', 'array', 'min:1', "max:{$max}"],
            'cab_officers.*' => ['integer', 'exists:users,id'],
            'instruction' => ['nullable', 'string', 'max:'.ForwardForReview::INSTRUCTION_MAX],
        ], [
            'technocrats.required' => 'Choose at least one technocrat.',
            'technocrats.min' => 'Choose at least one technocrat.',
            'technocrats.max' => "Choose at most {$max} technocrats.",
            'technocrats.*.integer' => 'Choose from the listed technocrats.',
            'technocrats.*.exists' => 'Choose from the listed technocrats.',
            'cab_officers.required' => 'Choose at least one CAB officer.',
            'cab_officers.min' => 'Choose at least one CAB officer.',
            'cab_officers.max' => "Choose at most {$max} CAB officers.",
            'cab_officers.*.integer' => 'Choose from the listed CAB officers.',
            'cab_officers.*.exists' => 'Choose from the listed CAB officers.',
        ]);

        try {
            $result = $forward->handle(
                $procurementCase,
                $request->user(),
                $validated['technocrats'],
                $validated['cab_officers'],
                $validated['instruction'] ?? null,
            );
        } catch (InvalidTransition|StaleTransition) {
            return back()->withErrors(['forward' => 'This case cannot be sent for review in its current status.']);
        }

        if ($result->alreadyForwarded) {
            $when = $result->forwardedAt?->copy()->setTimezone(RecordHardcopy::TIMEZONE)->format('j M Y, g:i a');

            return back()->with('info', 'This case was already sent for review by '.($result->forwardedBy ?? 'another user').($when ? " on {$when}" : '').'.');
        }

        return back()->with('success', 'Case sent for review.');
    }

    /** Slice 10b: a technocrat records favourable | not_favourable. */
    public function technicalReview(Request $request, ProcurementCase $procurementCase, RecordTechnicalReview $record): RedirectResponse
    {
        $validated = $this->outcomeInput($request, ['favourable', 'not_favourable'], 'not_favourable');

        $record->handle($procurementCase, $request->user(), $validated['outcome'], $validated['reason'] ?? null);

        return back()->with('success', 'Technical review recorded.');
    }

    /** Slice 10b: a CAB officer records confirmed | not_confirmed. */
    public function funding(Request $request, ProcurementCase $procurementCase, RecordFundingConfirmation $record): RedirectResponse
    {
        $validated = $this->outcomeInput($request, ['confirmed', 'not_confirmed'], 'not_confirmed');

        $record->handle($procurementCase, $request->user(), $validated['outcome'], $validated['reason'] ?? null);

        return back()->with('success', 'Funding outcome recorded.');
    }

    /** Slice 10b: T4. The gate guard decides; failures come back as the 'gate' error. */
    public function readyForFile(Request $request, ProcurementCase $procurementCase, MarkReadyForFile $ready): RedirectResponse
    {
        $ready->handle($procurementCase, $request->user());

        return back()->with('success', 'Case marked ready for file.');
    }

    /**
     * @param  list<string>  $outcomes
     * @return array{outcome: string, reason?: string|null}
     */
    private function outcomeInput(Request $request, array $outcomes, string $negative): array
    {
        $min = RecordReviewOutcome::REASON_MIN;
        $max = RecordReviewOutcome::REASON_MAX;

        return $request->validate([
            'outcome' => ['required', 'string', 'in:'.implode(',', $outcomes)],
            // The 10-character minimum applies to negative outcomes only; RecordReviewOutcome checks it.
            'reason' => ['nullable', 'string', "max:{$max}", "required_if:outcome,{$negative}"],
        ], [
            'outcome.required' => 'Choose an outcome.',
            'outcome.in' => 'Choose an outcome.',
            'reason.required_if' => "Give a reason of {$min} to {$max} characters.",
            'reason.max' => "The reason can be at most {$max} characters.",
        ]);
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
