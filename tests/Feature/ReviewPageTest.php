<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Http\Resources\Requests\RequestReview;
use App\Models\ProcurementCase;
use App\Submissions\SubmitCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// OVERNIGHT-3 (A1 to A3): Review as its own page, GET /requests/{case}/review.
// The vendor's own organisation's DRAFT only. DatabaseTransactions only.

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

// A1: who sees it -------------------------------------------------------------------------

it('shows the vendor their own organisation\'s draft', function () {
    [$case, $vendor] = readyDraft();

    $this->actingAs($vendor)->get(route('requests.review', $case->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('requests/review')
            ->where('case.id', $case->id)
            ->has('case.hero')
            ->has('summary.sections'));
});

it('answers 404 to a vendor of another organisation', function () {
    [$case] = readyDraft();

    $this->actingAs(vendorUser(vendorOrg()))->get(route('requests.review', $case->id))->assertNotFound();
});

it('answers 404 to staff (staff never see a draft)', function (Role $role) {
    [$case] = readyDraft();

    $this->actingAs(caseUser($role))->get(route('requests.review', $case->id))->assertNotFound();
})->with([Role::Cmo, Role::PsOffice]);

it('sends a case that is no longer a draft to its request page', function () {
    [$case, $vendor] = readyDraft();
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    $this->actingAs($vendor)->get(route('requests.review', $case->id))->assertRedirect(route('requests.show', $case->id));
});

it('sends a draft of an old type back to its own form', function () {
    [$case, $vendor] = legacyDraft('AD_HOC_PHARM');

    $this->actingAs($vendor)->get(route('requests.review', $case->id))->assertRedirect(route('requests.edit', $case->id));
});

// A1: ready and not ready -----------------------------------------------------------------

it('lists what is missing, links each line to its section and does not allow a submit', function () {
    [$case, $vendor] = newDraft();

    $props = pageProps($this->actingAs($vendor)->get(route('requests.review', $case->id))->assertOk());

    expect($props['can']['submit'])->toBeFalse()
        ->and($props['not_ready_reason'])->toBe(RequestReview::NOT_READY_REASON)
        ->and($props['issue_heading'])->toBeString()
        ->and($props['blockers'])->not->toBeEmpty();

    $edit = route('requests.edit', $case->id);
    foreach ($props['blockers'] as $blocker) {
        expect($blocker['href'])->toStartWith($edit.'#section-')
            ->and($blocker['section_label'])->toBeString()
            ->and($blocker['message'])->toBeString();
    }
    // One line per section and message, as the form's error summary.
    $lines = array_map(fn ($b) => $b['section_label'].'|'.$b['message'], $props['blockers']);
    expect($lines)->toBe(array_values(array_unique($lines)));
});

it('allows a submit of a ready draft, with nothing listed as missing', function () {
    [$case, $vendor] = readyDraft();

    $this->actingAs($vendor)->get(route('requests.review', $case->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.submit', true)
            ->where('blockers', [])
            ->where('not_ready_reason', null)
            ->where('issue_heading', null));
});

it('keeps internal content out of the review page', function (string $code) {
    [$case, $vendor] = readyDraft($code);

    expectVendorSafe(pageProps($this->actingAs($vendor)->get(route('requests.review', $case->id))->assertOk()));

    [$draft, $other] = newDraft($code);
    expectVendorSafe(pageProps($this->actingAs($other)->get(route('requests.review', $draft->id))->assertOk()));
})->with(['AD_HOC_TENDER_UNABLE', 'TENDER_VARIATION']);

// A3: submitting from the review page -----------------------------------------------------

it('submits a ready draft from the review page and lands on the request page, with the same audit events', function () {
    [$case, $vendor] = readyDraft();
    $before = DB::table('audit_logs')->where('case_id', $case->id)->pluck('action')->all();

    $response = $this->actingAs($vendor)->from(route('requests.review', $case->id))->post(route('requests.submit', $case->id));

    $reference = DB::table('cases')->where('id', $case->id)->value('reference_no');
    $response->assertRedirect(route('requests.show', $case->id))->assertSessionHas('success', fn ($m) => str_contains($m, $reference));
    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('SUBMITTED');

    // The submit writes what a submit from the request page writes (the same SubmitCase).
    $added = fn (string $id, array $before) => collect(DB::table('audit_logs')->where('case_id', $id)->orderBy('id')->pluck('action'))
        ->slice(count($before))->values()->all();
    [$other, $otherVendor] = readyDraft();
    $otherBefore = DB::table('audit_logs')->where('case_id', $other->id)->pluck('action')->all();
    $this->actingAs($otherVendor)->from(route('requests.show', $other->id))->post(route('requests.submit', $other->id));

    expect($added($case->id, $before))->toBe($added($other->id, $otherBefore))
        ->and($added($case->id, $before))->toContain(AuditAction::CaseTransitioned->value);
});

it('sends a refused submit back to the review page with what is missing', function () {
    [$case, $vendor] = newDraft();

    $this->actingAs($vendor)->from(route('requests.review', $case->id))->post(route('requests.submit', $case->id))
        ->assertRedirect(route('requests.review', $case->id))
        ->assertSessionHas('error');

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('DRAFT');
});

it('writes no audit row when the review page is viewed', function () {
    [$case, $vendor] = readyDraft();
    $count = DB::table('audit_logs')->where('case_id', $case->id)->count();

    $this->actingAs($vendor)->get(route('requests.review', $case->id))->assertOk();

    expect(DB::table('audit_logs')->where('case_id', $case->id)->count())->toBe($count);
});

// A2, A3: the form page and the review page, statically --------------------------------------

it('has no inline review on the form page', function () {
    $page = file_get_contents(resource_path('js/pages/requests/ad-hoc/edit.tsx'));

    expect($page)->not->toContain('RequestSummary')
        ->not->toContain('SummaryPanel')
        ->not->toContain('SubmitDialog')
        ->not->toContain("route('requests.submit'")
        // Review and Submit saves first, then goes to the Review page when nothing blocks.
        ->and($page)->toContain('await autosave.flush()')
        ->and($page)->toContain("router.visit(route('requests.review', detail.id)");
});

it('submits from the review page through the existing route and confirmation, with no fetch', function () {
    $page = file_get_contents(resource_path('js/pages/requests/review.tsx'));

    expect($page)->toContain("route('requests.submit', detail.id)")
        ->and($page)->toContain('<SubmitDialog')
        ->and($page)->toContain('<IssuesPanel')
        ->and($page)->toContain('<RequestSummary')
        ->and($page)->toContain('Back to Edit')
        ->and($page)->toContain('disabled={!can.submit || submitting}')
        ->and(preg_match('/\bfetch\s*\(|axios|XMLHttpRequest|localStorage|sessionStorage/', $page))->toBe(0);
});
