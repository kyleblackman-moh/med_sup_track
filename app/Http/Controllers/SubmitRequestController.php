<?php

namespace App\Http\Controllers;

use App\Models\ProcurementCase;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\SubmitCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /requests/{procurementCase}/submit. Thin: SubmitCase decides. Hidden
 * cases are 404 through route model binding; readiness failures come back as
 * errors keyed submit.<readiness key> with vendor-safe messages.
 */
class SubmitRequestController extends Controller
{
    public function __invoke(Request $request, ProcurementCase $procurementCase, SubmitCase $submit): RedirectResponse
    {
        try {
            $result = $submit->handle($procurementCase, $request->user());
        } catch (SubmissionNotReady $e) {
            $errors = [];
            foreach ($e->report->errors as $error) {
                $errors['submit.'.$error['key']] = $error['message'];
            }

            return back()->withErrors($errors)->with('error', 'This request is not ready to submit yet.');
        }

        if ($result->alreadySubmitted) {
            return to_route('requests.show', $procurementCase);
        }

        return to_route('requests.show', $procurementCase)
            ->with('success', "Request submitted. Your reference number is {$result->case->reference_no}. Deliver the hardcopy to the CMO's Office quoting this number.");
    }
}
