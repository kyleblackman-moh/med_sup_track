<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Models\CaseOutcome;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// FR-09: withdraw (T15) and final rejection on the PS's instruction (T16),
// through the real routes. Both irreversible. The internal reason stays in
// case_transitions; the vendor sees only the outcome and the optional message.
// DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Notification::fake();

    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org);
    $this->otherVendor = vendorUser(vendorOrg());
    $this->cmo = caseUser(Role::Cmo);
    $this->tech = caseUser(Role::Technocrat);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function endCase($t, S $status): ProcurementCase
{
    $case = makeCase([
        'org_id' => $t->org->id,
        'created_by' => $t->vendor->id,
        'status' => $status,
        'reference_no' => 'NR-END-'.Str::upper(Str::random(8)),
        'submitted_at' => now()->subDays(3),
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'End case item'])->save();
    assign($case, Role::Technocrat, $t->tech);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

function endStatus(ProcurementCase $case): string
{
    return DB::table('cases')->where('id', $case->id)->value('status');
}

const END_REASON = 'The vendor asked us to stop the request.';

it('lets the vendor withdraw a submitted request, and records the reason internally only', function () {
    $case = endCase($this, S::SUBMITTED);

    $this->actingAs($this->vendor)->post(route('requests.withdraw', $case), ['reason' => END_REASON])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(endStatus($case))->toBe('WITHDRAWN')
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->where('to_status', 'WITHDRAWN')->value('reason'))->toBe(END_REASON);

    $outcome = CaseOutcome::where('case_id', $case->id)->firstOrFail();
    expect($outcome->kind)->toBe('withdrawn')->and($outcome->vendor_message)->toBeNull();

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.withdrawn')->value('metadata');
    expect($audit)->not->toBeNull()->and($audit)->not->toContain(END_REASON);
});

it('refuses the vendor once the case is past intake, and another organisation entirely', function () {
    $inReview = endCase($this, S::IN_REVIEW);
    $this->actingAs($this->vendor)->post(route('requests.withdraw', $inReview), ['reason' => END_REASON])->assertForbidden();
    expect(endStatus($inReview))->toBe('IN_REVIEW');

    $submitted = endCase($this, S::SUBMITTED);
    $this->actingAs($this->otherVendor)->post(route('requests.withdraw', $submitted), ['reason' => END_REASON])->assertNotFound();
    expect(endStatus($submitted))->toBe('SUBMITTED');
});

it('offers the vendor Withdraw Request on a submitted request only', function () {
    $submitted = endCase($this, S::SUBMITTED);
    $inReview = endCase($this, S::IN_REVIEW);

    expect(pageProps($this->actingAs($this->vendor)->get(route('requests.show', $submitted)))['can']['withdraw'])->toBeTrue()
        ->and(pageProps($this->actingAs($this->vendor)->get(route('requests.show', $inReview)))['can']['withdraw'])->toBeFalse();
});

it('lets the CMO withdraw a case in review with a message to the vendor', function () {
    $case = endCase($this, S::IN_REVIEW);
    expect(pageProps($this->actingAs($this->cmo)->get(route('cases.show', $case)))['can']['withdraw'])->toBeTrue();

    $this->actingAs($this->cmo)->post(route('cases.withdraw', $case), ['reason' => END_REASON, 'vendor_message' => 'Please resubmit with the new quotation.'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(endStatus($case))->toBe('WITHDRAWN')
        ->and(CaseOutcome::where('case_id', $case->id)->value('vendor_message'))->toBe('Please resubmit with the new quotation.');
});

it('needs a reason of 10 characters or more', function () {
    $case = endCase($this, S::IN_REVIEW);

    $this->actingAs($this->cmo)->post(route('cases.withdraw', $case), ['reason' => 'short'])->assertSessionHasErrors('reason');
    expect(endStatus($case))->toBe('IN_REVIEW');
});

it('refuses a Technocrat (no case.withdraw), and a status T15 does not cover', function () {
    $case = endCase($this, S::IN_REVIEW);
    $this->actingAs($this->tech)->post(route('cases.withdraw', $case), ['reason' => END_REASON])->assertForbidden();

    $withPs = endCase($this, S::WITH_PS);
    $this->actingAs($this->cmo)->post(route('cases.withdraw', $withPs), ['reason' => END_REASON])->assertSessionHasErrors('status');
    expect(endStatus($withPs))->toBe('WITH_PS');
});

it('rejects a case with PS by the CMO only with the PS instruction confirmed', function (S $status) {
    $case = endCase($this, $status);
    expect(pageProps($this->actingAs($this->cmo)->get(route('cases.show', $case)))['can']['reject_final'])->toBeTrue();

    $this->actingAs($this->cmo)->post(route('cases.reject', $case), ['reason' => END_REASON, 'vendor_message' => 'Not approved: see the letter.'])
        ->assertSessionHasErrors('ps_instructed');
    expect(endStatus($case))->toBe($status->value);

    $this->actingAs($this->cmo)->post(route('cases.reject', $case), [
        'reason' => END_REASON,
        'vendor_message' => 'Not approved: see the letter.',
        'ps_instructed' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $outcome = CaseOutcome::where('case_id', $case->id)->firstOrFail();
    expect(endStatus($case))->toBe('REJECTED_FINAL')
        ->and($outcome->kind)->toBe('rejected')
        ->and($outcome->ps_instructed)->toBeTrue();
})->with([S::WITH_PS, S::RETURNED_TO_CMO]);

it('does not offer or allow rejection before the case reaches the PS', function () {
    $case = endCase($this, S::IN_REVIEW);

    expect(pageProps($this->actingAs($this->cmo)->get(route('cases.show', $case)))['can']['reject_final'])->toBeFalse();
    $this->actingAs($this->cmo)->post(route('cases.reject', $case), ['reason' => END_REASON, 'ps_instructed' => '1'])->assertSessionHasErrors('status');
    expect(endStatus($case))->toBe('IN_REVIEW');
});

it('answers a second withdrawal with who did it, and leaves one outcome row', function () {
    $case = endCase($this, S::IN_REVIEW);
    $this->actingAs($this->cmo)->post(route('cases.withdraw', $case), ['reason' => END_REASON]);

    $this->actingAs($this->cmo)->post(route('cases.withdraw', $case), ['reason' => END_REASON])
        ->assertSessionHas('info');

    expect(CaseOutcome::where('case_id', $case->id)->count())->toBe(1);
});

it('shows the vendor "Not approved" and the message, never the internal reason', function () {
    $case = endCase($this, S::WITH_PS);
    $this->actingAs($this->cmo)->post(route('cases.reject', $case), [
        'reason' => 'Internal: pricing above the ceiling.',
        'vendor_message' => 'The request was not approved.',
        'ps_instructed' => '1',
    ]);

    $response = $this->actingAs($this->vendor)->get(route('requests.show', $case))->assertOk();
    $outcome = pageProps($response)['case']['outcome'];

    expect($outcome['label'])->toBe('Not approved')
        ->and($outcome['message'])->toBe('The request was not approved.')
        ->and(array_keys($outcome))->toBe(['kind', 'label', 'date', 'message'])
        ->and($response->getContent())->not->toContain('Internal: pricing above the ceiling.');
    expectVendorSafe(pageProps($response));
});

it('shows staff the outcome with the reason and the vendor message', function () {
    $case = endCase($this, S::IN_REVIEW);
    $this->actingAs($this->cmo)->post(route('cases.withdraw', $case), ['reason' => END_REASON, 'vendor_message' => 'Resubmit later.']);

    $outcome = pageProps($this->actingAs($this->cmo)->get(route('cases.show', $case))->assertOk())['outcome'];

    expect($outcome['label'])->toBe('Withdrawn')
        ->and($outcome['reason'])->toBe(END_REASON)
        ->and($outcome['vendor_message'])->toBe('Resubmit later.')
        ->and($outcome['by'])->toBe($this->cmo->name);
});
