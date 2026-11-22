<?php

namespace App\Http\Controllers;

use App\Enums\ReturnKind;
use App\Models\ProcurementCase;
use App\Support\StaffFormMessages;
use App\Workflow\Actions\CloseCase;
use App\Workflow\Actions\EndCase;
use App\Workflow\Actions\ForwardForReview;
use App\Workflow\Actions\IntakeCase;
use App\Workflow\Actions\IssueLetter;
use App\Workflow\Actions\MarkReadyForFile;
use App\Workflow\Actions\PrepareFile;
use App\Workflow\Actions\RecordFundingConfirmation;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RecordPsDecision;
use App\Workflow\Actions\RecordReviewOutcome;
use App\Workflow\Actions\RecordTechnicalReview;
use App\Workflow\Actions\RouteReturnedCase;
use App\Workflow\Actions\SendToLegal;
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
        $validated = $this->validateForm($request, [
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

    /** Slice 10c: T5. The file number is typed by the CMO (pre-filled from config). */
    public function prepareFile(Request $request, ProcurementCase $procurementCase, PrepareFile $prepare): RedirectResponse
    {
        $validated = $this->validateForm($request, [
            'file_no' => ['required', 'string', 'max:'.PrepareFile::FILE_NO_MAX],
        ], [
            'file_no.required' => 'Enter the file number.',
            'file_no.max' => 'The file number can be at most '.PrepareFile::FILE_NO_MAX.' characters.',
        ]);

        $result = $prepare->handle($procurementCase, $request->user(), $validated['file_no']);

        if ($result->already) {
            return back()->with('info', 'The file was already prepared'.($result->detail ? " as {$result->detail}" : '').' by '.$result->byLine().'.');
        }

        return back()->with('success', 'File prepared.');
    }

    /** Slice 10c: T6. The service re-checks each officer's eligibility by permission. */
    public function sendToLegal(Request $request, ProcurementCase $procurementCase, SendToLegal $send): RedirectResponse
    {
        if (is_array($request->input('legal_officers'))) {
            $request->merge(['legal_officers' => array_values(array_unique(array_map('strval', array_filter($request->input('legal_officers'), 'is_scalar'))))]);
        }

        $max = SendToLegal::MAX;
        $validated = $this->validateForm($request, [
            'legal_officers' => ['required', 'array', 'min:'.SendToLegal::MIN, "max:{$max}"],
            'legal_officers.*' => ['integer', 'exists:users,id'],
            'instruction' => ['nullable', 'string', 'max:'.SendToLegal::INSTRUCTION_MAX],
        ], [
            'legal_officers.required' => 'Choose at least one Legal officer.',
            'legal_officers.min' => 'Choose at least one Legal officer.',
            'legal_officers.max' => "Choose at most {$max} Legal officers.",
            'legal_officers.*.integer' => 'Choose from the listed Legal officers.',
            'legal_officers.*.exists' => 'Choose from the listed Legal officers.',
        ]);

        $result = $send->handle($procurementCase, $request->user(), $validated['legal_officers'], $validated['instruction'] ?? null);

        if ($result->already) {
            return back()->with('info', 'This case was already sent to Legal by '.$result->byLine().'.');
        }

        return back()->with('success', 'Case sent to Legal.');
    }

    /** Slice 10c: T7 (confirmed) or T8 (return_to_cmo). First decision wins. */
    public function legalDecision(Request $request, ProcurementCase $procurementCase, RecordLegalDecision $record): RedirectResponse
    {
        $min = RecordLegalDecision::REASON_MIN;
        $max = RecordLegalDecision::REASON_MAX;
        $return = RecordLegalDecision::RETURN_TO_CMO;

        $validated = $this->validateForm($request, [
            'decision' => ['required', 'string', 'in:'.implode(',', RecordLegalDecision::DECISIONS)],
            // The 10-character minimum applies to a return only; RecordLegalDecision checks it.
            'reason' => ['nullable', 'string', "max:{$max}", "required_if:decision,{$return}"],
        ], [
            'decision.required' => 'Choose a decision.',
            'decision.in' => 'Choose a decision.',
            'reason.required_if' => "Give a reason of {$min} to {$max} characters.",
            'reason.max' => "The reason can be at most {$max} characters.",
        ]);

        $result = $record->handle($procurementCase, $request->user(), $validated['decision'], $validated['reason'] ?? null);

        if ($result->already) {
            return back()->with('info', 'The Legal decision was already recorded ('.$result->detail.') by '.$result->byLine().'.');
        }

        return back()->with('success', $validated['decision'] === RecordLegalDecision::CONFIRMED
            ? 'Contract confirmed. The case is now with the PS office.'
            : 'Case returned to the CMO with a query.');
    }

    /** Slice 10d: T9 (grant) or T10 (return_to_cmo with a return_kind). First decision wins. */
    public function psDecision(Request $request, ProcurementCase $procurementCase, RecordPsDecision $record): RedirectResponse
    {
        $min = RecordPsDecision::REASON_MIN;
        $max = RecordPsDecision::REASON_MAX;
        $return = RecordPsDecision::RETURN_TO_CMO;

        $validated = $this->validateForm($request, [
            'decision' => ['required', 'string', 'in:'.implode(',', RecordPsDecision::DECISIONS)],
            'return_kind' => ['nullable', 'string', "required_if:decision,{$return}", 'in:'.implode(',', ReturnKind::values())],
            // The 10-character minimum applies to a return only; RecordPsDecision checks it.
            'reason' => ['nullable', 'string', "max:{$max}", "required_if:decision,{$return}"],
        ], [
            'decision.required' => 'Choose a decision.',
            'decision.in' => 'Choose a decision.',
            'return_kind.required_if' => 'Choose whether the query is legal-related or technical.',
            'return_kind.in' => 'Choose whether the query is legal-related or technical.',
            'reason.required_if' => "Give a reason of {$min} to {$max} characters.",
            'reason.max' => "The reason can be at most {$max} characters.",
        ]);

        $result = $record->handle(
            $procurementCase,
            $request->user(),
            $validated['decision'],
            $validated['reason'] ?? null,
            $validated['return_kind'] ?? null,
        );

        if ($result->already) {
            return back()->with('info', 'The PS decision was already recorded ('.$result->detail.') by '.$result->byLine().'.');
        }

        return back()->with('success', $validated['decision'] === RecordPsDecision::GRANT
            ? 'Non-objection granted.'
            : 'Case returned to the CMO with a query.');
    }

    /** Slice 10f: T13. Dates are checked (not in the future) by IssueLetter. */
    public function issueLetter(Request $request, ProcurementCase $procurementCase, IssueLetter $issue): RedirectResponse
    {
        $validated = $this->validateForm($request, [
            'letter_date' => ['required', 'string', 'date_format:Y-m-d'],
            'letter_reference' => ['nullable', 'string', 'max:'.IssueLetter::REFERENCE_MAX],
            'emailed_on' => ['nullable', 'string', 'date_format:Y-m-d'],
            'hardcopy_sent_on' => ['nullable', 'string', 'date_format:Y-m-d'],
        ], [
            'letter_date.required' => 'Enter the date on the letter.',
            'letter_date.date_format' => 'Enter the date on the letter.',
            'letter_reference.max' => 'The reference can be at most '.IssueLetter::REFERENCE_MAX.' characters.',
            'emailed_on.date_format' => 'Enter the date the letter was emailed.',
            'hardcopy_sent_on.date_format' => 'Enter the date the hardcopy was sent.',
        ]);

        $result = $issue->handle(
            $procurementCase,
            $request->user(),
            $validated['letter_date'],
            $validated['letter_reference'] ?? null,
            $validated['emailed_on'] ?? null,
            $validated['hardcopy_sent_on'] ?? null,
        );

        if ($result->already) {
            return back()->with('info', 'The letter was already recorded as issued by '.$result->byLine().'.');
        }

        return back()->with('success', 'Letter recorded as issued.');
    }

    /** Slice 10f: T14. */
    public function closeCase(Request $request, ProcurementCase $procurementCase, CloseCase $close): RedirectResponse
    {
        $result = $close->handle($procurementCase, $request->user());

        if ($result->already) {
            return back()->with('info', 'This case was already closed by '.$result->byLine().'.');
        }

        return back()->with('success', 'Case closed.');
    }

    /** TERMINAL-1: T15, SUBMITTED | CMO_INTAKE | IN_REVIEW -> WITHDRAWN. Irreversible. */
    public function withdraw(Request $request, ProcurementCase $procurementCase, EndCase $end): RedirectResponse
    {
        $validated = $this->validateForm($request, self::endRules(), self::endMessages());

        $result = $end->withdraw($procurementCase, $request->user(), $validated['reason'], $validated['vendor_message'] ?? null);

        if ($result->already) {
            return back()->with('info', 'This case was already withdrawn by '.$result->byLine().'.');
        }

        return back()->with('success', 'Case withdrawn.');
    }

    /** TERMINAL-1: T16, WITH_PS | RETURNED_TO_CMO -> REJECTED_FINAL, on the PS's instruction. Irreversible. */
    public function rejectFinal(Request $request, ProcurementCase $procurementCase, EndCase $end): RedirectResponse
    {
        $validated = $this->validateForm($request, [
            ...self::endRules(),
            'on_ps_instruction' => ['accepted'],
        ], [
            ...self::endMessages(),
            'on_ps_instruction.accepted' => 'Confirm that the Permanent Secretary instructed this rejection.',
        ]);

        $result = $end->rejectFinal($procurementCase, $request->user(), $validated['reason'], $validated['vendor_message'] ?? null, true);

        if ($result->already) {
            return back()->with('info', 'This case was already rejected by '.$result->byLine().'.');
        }

        return back()->with('success', 'Case rejected (final).');
    }

    /** @return array<string, list<string>> */
    private static function endRules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:'.EndCase::REASON_MAX],
            'vendor_message' => ['nullable', 'string', 'max:'.EndCase::MESSAGE_MAX],
        ];
    }

    /** @return array<string, string> */
    private static function endMessages(): array
    {
        return [
            'reason.required' => 'Reason is required.',
            'reason.max' => 'Reason needs to be '.EndCase::REASON_MAX.' characters or fewer.',
            'vendor_message.max' => 'Message to vendor needs to be '.EndCase::MESSAGE_MAX.' characters or fewer.',
        ];
    }

    /** Slice 10e: T11 (technocrats), T12 (legal) or T17 (ps) from RETURNED_TO_CMO. */
    public function routeOnward(Request $request, ProcurementCase $procurementCase, RouteReturnedCase $route): RedirectResponse
    {
        if (is_array($request->input('reviewers'))) {
            $request->merge(['reviewers' => array_values(array_unique(array_map('strval', array_filter($request->input('reviewers'), 'is_scalar'))))]);
        }

        $min = RouteReturnedCase::NOTE_MIN;
        $max = RouteReturnedCase::NOTE_MAX;
        $validated = $this->validateForm($request, [
            'destination' => ['required', 'string', 'in:'.implode(',', RouteReturnedCase::DESTINATIONS)],
            // The 10-character minimum is checked by RouteReturnedCase after trimming.
            'note' => ['required', 'string', "max:{$max}"],
            'reviewers' => ['nullable', 'array', 'max:'.RouteReturnedCase::REVIEWERS_MAX],
            'reviewers.*' => ['integer'],
        ], [
            'destination.required' => 'Choose where to send the case.',
            'destination.in' => 'Choose where to send the case.',
            'note.required' => "Give a note of {$min} to {$max} characters.",
            'note.max' => "The note can be at most {$max} characters.",
            'reviewers.max' => 'Choose 1 to '.RouteReturnedCase::REVIEWERS_MAX.' Technocrats.',
            'reviewers.*.integer' => 'Choose from the listed Technocrats.',
        ]);

        $result = $route->handle(
            $procurementCase,
            $request->user(),
            $validated['destination'],
            $validated['note'],
            $validated['reviewers'] ?? [],
        );

        if ($result->already) {
            return back()->with('info', 'This case was already sent onward ('.$result->detail.') by '.$result->byLine().'.');
        }

        return back()->with('success', 'Case sent onward: '.$result->case->status->label().'.');
    }

    /**
     * @param  list<string>  $outcomes
     * @return array{outcome: string, reason?: string|null}
     */
    private function outcomeInput(Request $request, array $outcomes, string $negative): array
    {
        $min = RecordReviewOutcome::REASON_MIN;
        $max = RecordReviewOutcome::REASON_MAX;

        return $this->validateForm($request, [
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
        $validated = $this->validateForm($request, [
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
        $validated = $this->validateForm($request, [
            'reason' => ['required', 'string', 'min:'.RecordHardcopy::REASON_MIN, 'max:'.RecordHardcopy::REASON_MAX],
        ]);

        $hardcopy->reverse($procurementCase, $request->user(), $validated['reason']);

        return back()->with('success', 'Hardcopy record removed.');
    }

    /**
     * FORM-SAVE-1 (A5e): every staff action form validates through the same
     * wording layer: the action's own messages first, then StaffFormMessages'
     * fallbacks, with fields named as the screen shows them.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    private function validateForm(Request $request, array $rules, array $messages = []): array
    {
        return $request->validate($rules, [...StaffFormMessages::FALLBACK, ...$messages], StaffFormMessages::ATTRIBUTES);
    }
}
