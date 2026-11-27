<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\CaseVariationRow;
use App\Models\ProcurementCase;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use App\Submissions\RequisitionData;
use App\Submissions\RequisitionRules;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// V2-5 step 4: Details of Variation Requested, one row per variation, saved
// like the award rows (upsert on row id, the same route, cap and throttle).
// Kyle, 2026-11-27: shown and required on a Tender Variation only.
// DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function variationRowsOf(ProcurementCase $case): array
{
    return CaseVariationRow::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->orderBy('id')
        ->get()->map(fn ($r) => $r->only(['id', 'sort_order', 'variation_type', 'existing_detail', 'proposed_detail', 'effective_detail']))->all();
}

function variationKeys(ProcurementCase $case): array
{
    $errors = app(SubmissionReadiness::class)->evaluate(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->errors;

    return array_values(array_filter(array_column($errors, 'key'), fn ($k) => RequestSections::sectionOf($k) === 'variation_details'));
}

/** A complete, ready draft of $code that answers Yes, with $rows as its variation rows. */
function readyWithAnswer(string $code, bool $answer, array $rows): array
{
    [$case, $vendor] = newDraft($code);
    saveCompleteAdHoc($case, $vendor);
    saveDraft($case, $vendor, ['previous_request_in_cycle' => $answer, 'variation_rows' => $rows]);
    attachRequiredDocuments($case);

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

// 1. Add, update, remove ------------------------------------------------------------------

it('adds, updates on the row id and removes variation rows through the autosave route', function () {
    [$case, $vendor] = newDraft();
    $put = fn (array $payload) => $this->actingAs($vendor)->put(route('requests.update', $case->id), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    // Add two (the page gives new rows a client key and gets the ids back in the "saved" flash).
    $response = $put(['variation_rows' => [
        ['client_key' => 'k1', ...variationEntry()],
        ['client_key' => 'k2', 'variation_type' => 'Quantity'],
    ]]);
    $saved = $response->getSession()->get('saved');
    [$first, $second] = variationRowsOf($case);
    expect($saved['id_map'])->toEqual((object) ['k1' => $first['id'], 'k2' => $second['id']])
        ->and($saved['row_ids'])->toEqual((object) ['variation_rows' => [$first['id'], $second['id']]])
        ->and($second)->toMatchArray(['sort_order' => 1, 'variation_type' => 'Quantity', 'existing_detail' => null]);

    // Update in place, partly: only the keys sent change; the ids stay.
    $put(['variation_rows' => [['id' => $first['id']], ['id' => $second['id'], 'effective_detail' => 'PO-77']]]);
    [$first2, $second2] = variationRowsOf($case);
    expect($first2)->toBe($first)
        ->and($second2)->toMatchArray(['id' => $second['id'], 'variation_type' => 'Quantity', 'effective_detail' => 'PO-77']);

    // Remove: a row missing from the payload is deleted; the order closes up.
    $put(['variation_rows' => [['id' => $second['id']]]]);
    expect(variationRowsOf($case))->toHaveCount(1)
        ->and(variationRowsOf($case)[0])->toMatchArray(['id' => $second['id'], 'sort_order' => 0]);

    // A blank row is not kept.
    $put(['variation_rows' => [['id' => $second['id'], 'variation_type' => '', 'effective_detail' => null]]]);
    expect(variationRowsOf($case))->toBe([]);
});

it('refuses a row id that is not a variation row of this case', function () {
    [$case, $vendor] = newDraft();
    [$other, $otherVendor] = newDraft();
    saveDraft($other, $otherVendor, ['variation_rows' => [variationEntry()]]);
    $foreignId = variationRowsOf($other)[0]['id'];

    expect(fn () => saveDraft($case, $vendor, ['variation_rows' => [['id' => $foreignId, 'variation_type' => 'Price']]]))
        ->toThrow(ValidationException::class);
    expect(variationRowsOf($other)[0]['variation_type'])->toBe('Price');
});

it('audits a save of the variation rows by count only, never their text', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['variation_rows' => [variationEntry()]]);

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftSaved->value)->latest('id')->first();
    expect(json_decode($audit->metadata, true))->toBe(['sections' => ['variation_rows'], 'counts' => ['variation_rows' => 1]])
        ->and($audit->metadata)->not->toContain('Unit cost')->not->toContain('Price')->not->toContain('PO-2207');
});

// 2. Rules: cap, lengths, old types ---------------------------------------------------------

it('caps the variation rows at the award rows\' cap', function () {
    [$case, $vendor] = newDraft();
    $cap = RequisitionRules::ROW_CAPS['variation_rows'];

    expect($cap)->toBe(RequisitionRules::ROW_CAPS['tender_awards'])->toBe(20);
    saveDraft($case, $vendor, ['variation_rows' => array_fill(0, $cap, variationEntry())]);
    expect(variationRowsOf($case))->toHaveCount($cap);

    expect(fn () => saveDraft($case, $vendor, ['variation_rows' => array_fill(0, $cap + 1, variationEntry())]))
        ->toThrow(ValidationException::class);
    expect(variationRowsOf($case))->toHaveCount($cap);
});

it('validates the columns and refuses the rows for the old types', function (string $code, array $payload, string $key) {
    [$case] = draftOf($code);

    try {
        RequisitionData::validate($payload, $case->procurementType()->firstOrFail());
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
})->with([
    ['TENDER_VARIATION', ['variation_rows' => [['variation_type' => str_repeat('x', 101)]]], 'variation_rows.0.variation_type'],
    ['TENDER_VARIATION', ['variation_rows' => [['existing_detail' => str_repeat('x', 501)]]], 'variation_rows.0.existing_detail'],
    ['TENDER_VARIATION', ['variation_rows' => [['proposed_detail' => str_repeat('x', 501)]]], 'variation_rows.0.proposed_detail'],
    ['TENDER_VARIATION', ['variation_rows' => [['effective_detail' => str_repeat('x', 101)]]], 'variation_rows.0.effective_detail'],
    ['TENDER_VARIATION', ['variation_rows' => [['case_id' => 'x']]], 'variation_rows.0.case_id'],
    ['TENDER_VARIATION', ['variation_rows' => [['sort_order' => 3]]], 'variation_rows.0.sort_order'],
    ['TENDER_VARIATION', ['variation_rows' => 'text'], 'variation_rows'],
    ['AD_HOC_EMERGENCY', ['variation' => variationEntry()], 'variation'], // the old single entry is gone
    ['AD_HOC_PHARM', ['variation_rows' => [variationEntry()]], 'variation_rows'],
]);

it('accepts the effective date or application P.O. as free text up to 100 characters', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['variation_rows' => [variationEntry(['effective_detail' => str_repeat('P', 100)])]]);

    expect(variationRowsOf($case)[0]['effective_detail'])->toBe(str_repeat('P', 100));
});

// 3. Access: own organisation, DRAFT only ----------------------------------------------------

it('returns 404 to another organisation and to staff, and saves nothing', function () {
    [$case] = newDraft();

    foreach ([vendorUser(vendorOrg()), caseUser(Role::Cmo)] as $outsider) {
        $this->actingAs($outsider)->put(route('requests.update', $case->id), ['variation_rows' => [variationEntry()]])->assertNotFound();
    }
    expect(variationRowsOf($case))->toBe([]);
});

it('edits the rows of a DRAFT only', function () {
    [$case, $vendor] = readyWithAnswer('TENDER_VARIATION', true, [variationEntry()]);
    app(SubmitCase::class)->handle($case, $vendor);
    $before = variationRowsOf($case);

    $this->actingAs($vendor)->put(route('requests.update', $case->id), ['variation_rows' => []])
        ->assertRedirect(route('requests.show', $case->id));
    expect(variationRowsOf($case))->toBe($before)
        ->and(DB::table('cases')->where('id', $case->id)->value('status'))->toBe(CaseStatus::SUBMITTED->value);
});

it('saves the rows through the same route and throttle as the award rows', function () {
    $route = app('router')->getRoutes()->getByName('requests.update');

    expect($route->gatherMiddleware())->toContain('throttle:30,1');
});

// 4. Kyle, 2026-11-27: shown on a Tender Variation only, whatever the answer ---------------------

it('shows the section on a Tender Variation only, whatever the answer', function (string $code, ?bool $answer) {
    [$case, $vendor] = newDraft($code);
    saveDraft($case, $vendor, ['previous_request_in_cycle' => $answer, 'variation_rows' => [variationEntry()]]);

    $props = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk());
    $section = collect($props['form']['sections'])->firstWhere('key', 'variation_details');
    $shown = $code === 'TENDER_VARIATION'; // Kyle, 2026-11-27: Tender Variation only

    expect($section['visible'])->toBe($shown)
        ->and($section['number'])->toBe($shown ? 3 : null)
        ->and($section['label'])->toBe('Details of Variation Requested')
        ->and(collect($props['request_sections'])->pluck('key')->contains('variation_details'))->toBe($shown)
        // The rows are kept in the draft while hidden.
        ->and($props['case']['variation_rows'])->toHaveCount(1);
})->with(ProcurementTypeProfile::REQUEST_TYPES)->with(['unanswered' => null, 'no' => false, 'yes' => true]);

it('sends the column requirements of an entered row', function () {
    [$case, $vendor] = newDraft();
    $section = collect(pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id)))['form']['sections'])->firstWhere('key', 'variation_details');

    expect($section['required'])->toBe([
        'variation_rows.variation_type' => true, 'variation_rows.existing_detail' => true,
        'variation_rows.proposed_detail' => true, 'variation_rows.effective_detail' => true,
    ]);
});

// 5. Readiness and submit -----------------------------------------------------------------------

it('needs at least one complete row on a Tender Variation', function (array $rows, array $keys) {
    [$case, $vendor] = newDraft('TENDER_VARIATION');
    saveDraft($case, $vendor, ['previous_request_in_cycle' => true, 'variation_rows' => $rows]);

    expect(variationKeys($case))->toBe($keys);
})->with([
    'no rows' => [[], ['variation_rows']],
    'a partial row' => [[['variation_type' => 'Price', 'proposed_detail' => 'More']],
        ['variation_rows.0.existing_detail', 'variation_rows.0.effective_detail']],
    'a complete row' => [[variationEntry()], []],
    'a complete row and a partial one' => [[variationEntry(), ['variation_type' => 'Quantity']],
        ['variation_rows.1.existing_detail', 'variation_rows.1.proposed_detail', 'variation_rows.1.effective_detail']],
]);

it('ignores the rows, complete or not, on any other type, whatever the answer', function (?bool $answer) {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['previous_request_in_cycle' => $answer, 'variation_rows' => [['variation_type' => 'Price']]]);

    expect(variationKeys($case))->toBe([]);
})->with(['no' => false, 'unanswered' => null, 'yes' => true]);

it('words a partial row\'s issues with its row number and one full stop', function () {
    [$case, $vendor] = newDraft('TENDER_VARIATION');
    saveDraft($case, $vendor, ['previous_request_in_cycle' => true, 'variation_rows' => [variationEntry(), ['variation_type' => 'Price']]]);

    $errors = collect(app(SubmissionReadiness::class)->evaluate(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->errors);
    expect($errors->firstWhere('key', 'variation_rows.1.existing_detail')['message'])->toBe('Variation Row 2: Existing Approved Detail is required.')
        ->and($errors->firstWhere('key', 'variation_rows.1.effective_detail')['message'])->toBe('Variation Row 2: Effective Date / Application P.O. is required.');
});

it('blocks the submit of a Tender Variation when no row is complete', function () {
    [$case, $vendor] = readyWithAnswer('TENDER_VARIATION', true, [['variation_type' => 'Price']]);

    expect(fn () => app(SubmitCase::class)->handle($case, $vendor))->toThrow(SubmissionNotReady::class);
    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('DRAFT')
        ->and(variationRowsOf($case))->toHaveCount(1); // kept: the refused submit rolled back
});

it('submits a Yes with a complete row and keeps the rows', function () {
    [$case, $vendor] = readyWithAnswer('TENDER_VARIATION', true, [variationEntry(), variationEntry(['variation_type' => 'Quantity'])]);

    app(SubmitCase::class)->handle($case, $vendor);

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('SUBMITTED')
        ->and(variationRowsOf($case))->toHaveCount(2)
        ->and(DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftRowsDiscarded->value)->exists())->toBeFalse();
});

it('submits a No with no rows, except a Tender Variation', function (string $code) {
    [$case, $vendor] = readyWithAnswer($code, false, []);

    if ($code === 'TENDER_VARIATION') {
        // Kyle, 2026-11-25: a Tender Variation always needs its variation details.
        expect(fn () => app(SubmitCase::class)->handle($case, $vendor))->toThrow(SubmissionNotReady::class);

        return;
    }

    app(SubmitCase::class)->handle($case, $vendor);

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('SUBMITTED');
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('discards hidden rows at submit when the answer is No, and audits the count only', function () {
    [$case, $vendor] = readyWithAnswer('TENDER_EXCESS', false, [variationEntry(), ['variation_type' => 'Partial']]);

    app(SubmitCase::class)->handle($case, $vendor);

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftRowsDiscarded->value)->sole();
    expect(variationRowsOf($case))->toBe([])
        ->and(json_decode($audit->metadata, true))->toBe(['reason' => 'section_hidden', 'counts' => ['variation_details' => 2]])
        ->and(DB::table('case_tender_awards')->where('case_id', $case->id)->count())->toBe(2);
});

it('always needs the variation details on a Tender Variation, and never on another type', function () {
    // Kyle, 2026-11-25: a Tender Variation needs a row whatever the answer. Kyle, 2026-11-27: a Yes on
    // another type shows Approved Tender Excesses instead, which is optional.
    [$variation] = readyWithAnswer('TENDER_VARIATION', false, []);
    [$excessYes] = readyWithAnswer('TENDER_EXCESS', true, []);
    [$excessNo] = readyWithAnswer('TENDER_EXCESS', false, []);

    expect(variationKeys($variation))->toBe(['variation_rows'])
        ->and(variationKeys($excessYes))->toBe([])
        ->and(variationKeys($excessNo))->toBe([]);
});

// 6. The form (static) --------------------------------------------------------------------------

it('renders the variation table like the supplier table', function () {
    $section = file_get_contents(resource_path('js/components/requests/ad-hoc/variation-section.tsx'));

    expect($section)->toContain('<EntryTable')
        ->toContain('breakpoint="45rem"') // FORM-ERR-1 (B): one line once the card fits every column (EntryTableFitTest)
        ->toContain("label: 'Variation Type'")
        ->toContain("label: 'Existing Approved Detail'")
        ->toContain("label: 'Proposed Revised Detail'")
        ->toContain("label: 'Effective Date / Application P.O.'")
        ->toContain('`Variation Row ${index + 1}`')
        ->toContain('`Remove Variation Row ${index + 1}`')
        ->toContain('label="Add Another Variation"')
        ->toContain('variant="text"')
        ->toContain('<EntryTextArea {...cell(\'existing_detail\')} rows={2} />')
        ->toContain('<EntryTextArea {...cell(\'proposed_detail\')} rows={2} />')
        ->toContain('api.config.row_caps.variation_rows')
        ->not->toContain('fetch(')->not->toContain('axios');

    // One empty row when there are none (as every entry table).
    expect(file_get_contents(resource_path('js/components/requests/ad-hoc/form-state.ts')))
        ->toContain("'variation_rows'")
        ->toContain('rows[section] = saved.length > 0 ? saved : [newRow(section)];');
});
