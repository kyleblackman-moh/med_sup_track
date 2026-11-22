<?php

namespace App\Http\Controllers;

use App\Models\CaseOutcome;
use App\Models\ProcurementCase;
use App\Workflow\Actions\EndCase;
use App\Workflow\Actions\StepResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * FR-09: withdraw (T15) and final rejection (T16). EndCase and
 * CaseTransitionService authorise every request (CasePolicy::act, the T15
 * vendor guard); out-of-scope cases are 404 through route model binding.
 */
class CaseEndController extends Controller
{
    /** The vendor's "Withdraw request" (SUBMITTED only: a draft is deleted instead). */
    public function vendorWithdraw(Request $request, ProcurementCase $procurementCase, EndCase $end): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.EndCase::REASON_MIN, 'max:'.EndCase::REASON_MAX],
        ], self::messages());

        return $this->done($end->withdraw($procurementCase, $request->user(), $validated['reason']), 'Request withdrawn.', 'withdrawn');
    }

    public function withdraw(Request $request, ProcurementCase $procurementCase, EndCase $end): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.EndCase::REASON_MIN, 'max:'.EndCase::REASON_MAX],
            'vendor_message' => ['nullable', 'string', 'max:'.CaseOutcome::MESSAGE_MAX],
        ], self::messages());

        return $this->done(
            $end->withdraw($procurementCase, $request->user(), $validated['reason'], $validated['vendor_message'] ?? null),
            'Case withdrawn.',
            'withdrawn',
        );
    }

    public function reject(Request $request, ProcurementCase $procurementCase, EndCase $end): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.EndCase::REASON_MIN, 'max:'.EndCase::REASON_MAX],
            'vendor_message' => ['nullable', 'string', 'max:'.CaseOutcome::MESSAGE_MAX],
            'ps_instructed' => ['accepted'],
        ], self::messages());

        return $this->done(
            $end->reject($procurementCase, $request->user(), $validated['reason'], $validated['vendor_message'] ?? null, true),
            'Case rejected.',
            'rejected',
        );
    }

    private function done(StepResult $result, string $message, string $verb): RedirectResponse
    {
        if ($result->already) {
            return back()->with('info', "This case was already {$verb} by ".$result->byLine().'.');
        }

        return back()->with('success', $message);
    }

    /** @return array<string, string> */
    private static function messages(): array
    {
        $range = 'Give a reason of '.EndCase::REASON_MIN.' to '.EndCase::REASON_MAX.' characters.';

        return [
            'reason.required' => $range,
            'reason.min' => $range,
            'reason.max' => $range,
            'vendor_message.max' => 'The message to the vendor can be at most '.CaseOutcome::MESSAGE_MAX.' characters.',
            'ps_instructed.accepted' => 'Confirm that the Permanent Secretary instructed this rejection.',
        ];
    }
}
