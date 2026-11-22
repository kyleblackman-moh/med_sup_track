<?php

namespace App\Http\Controllers;

use App\Models\ProcurementCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * HARDCOPY-1: the "Hardcopy received" search box on the CMO/Registry
 * dashboard. Exact match on the case reference (case-insensitive, outer
 * spaces trimmed), among the cases the user can see (the visibility scope on
 * ProcurementCase): one case opens it, anything else is "No case with that
 * reference". Partial references never match.
 */
class HardcopyLookupController extends Controller
{
    public const NOT_FOUND = 'No case with that reference';

    public const MAX = 40;

    public function __invoke(Request $request): RedirectResponse
    {
        $reference = mb_strtoupper(trim((string) $request->input('reference', '')));

        if ($reference === '' || mb_strlen($reference) > self::MAX) {
            return back()->withErrors(['reference' => self::NOT_FOUND])->withInput();
        }

        // The global scope applies: a case the user cannot see is never found.
        $ids = ProcurementCase::query()->whereRaw('UPPER(reference_no) = ?', [$reference])->limit(2)->pluck('id');

        if ($ids->count() !== 1) {
            return back()->withErrors(['reference' => self::NOT_FOUND])->withInput();
        }

        return to_route('cases.show', $ids->first());
    }
}
