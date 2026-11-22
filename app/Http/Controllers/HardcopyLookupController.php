<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\ProcurementCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * FR-04: the dashboard's reference lookup (typed, or a scanner reading the
 * cover sheet's QR). Finds the case among those the user may see (the global
 * scope) and opens it; an unknown or invisible reference is the same "not
 * found" message, so nothing leaks.
 */
class HardcopyLookupController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(Permission::HardcopyConfirm->value), 403);

        $validated = $request->validate(
            ['reference' => ['required', 'string', 'max:50']],
            ['reference.required' => 'Enter a reference number.', 'reference.max' => 'Enter a reference number.'],
        );
        $reference = mb_strtoupper(trim($validated['reference']));

        $case = ProcurementCase::query()->where('reference_no', $reference)->first(['id']);

        if ($case === null) {
            return back()->withErrors(['reference' => "No case you can open has the reference {$reference}."])->withInput();
        }

        return to_route('cases.show', $case);
    }
}
