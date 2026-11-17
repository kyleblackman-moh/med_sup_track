<?php

use App\Enums\Role;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// Slice 8d: the submit route, the Show/Index props and the printable receipt.

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

function caseRow(string $id): object
{
    return DB::table('cases')->where('id', $id)->first();
}

it('submits from the show page and redirects with the reference', function () {
    [$case, $vendor] = readyDraft();

    $response = $this->actingAs($vendor)->post("/requests/{$case->id}/submit");

    $reference = caseRow($case->id)->reference_no;
    $response->assertRedirect("/requests/{$case->id}")->assertSessionHas('success', fn ($m) => str_contains($m, $reference));
    expect(caseRow($case->id)->status)->toBe('SUBMITTED');

    $this->actingAs($vendor)->get("/requests/{$case->id}")->assertInertia(fn (Assert $page) => $page
        ->component('requests/show')
        ->where('case.status_label', 'Submitted')
        ->where('case.reference_no', $reference)
        ->where('can.submit', false)
        ->where('readiness', null));
});

it('returns 404 to a vendor of another organisation', function () {
    [$case] = readyDraft();

    $this->actingAs(vendorUser(vendorOrg()))->post("/requests/{$case->id}/submit")->assertNotFound();

    expect(caseRow($case->id)->status)->toBe('DRAFT');
});

// Slice 9a: staff never see a DRAFT, so route model binding answers 404 before
// the case.submit middleware runs; routes without a case still answer 403.
it('refuses staff without case.submit: 404 on a draft, 403 on the request list', function (Role $role) {
    [$case] = readyDraft();
    $staff = caseUser($role);

    $this->actingAs($staff)->post("/requests/{$case->id}/submit")->assertNotFound();
    $this->actingAs($staff)->get('/requests')->assertForbidden();

    expect(caseRow($case->id)->status)->toBe('DRAFT');
})->with([Role::Cmo, Role::PsOffice]);

it('redirects back with the reasons when the draft is not ready', function () {
    [$case, $vendor] = newDraft();

    $this->actingAs($vendor)->from("/requests/{$case->id}")->post("/requests/{$case->id}/submit")
        ->assertRedirect("/requests/{$case->id}")
        ->assertSessionHasErrors(['submit.requisition.item_description', 'submit.documents.bids'])
        ->assertSessionHas('error');

    expect(caseRow($case->id)->status)->toBe('DRAFT')
        ->and(caseRow($case->id)->reference_no)->toBeNull();
});

it('creates one reference for a double POST', function () {
    [$case, $vendor] = readyDraft();

    $this->actingAs($vendor)->post("/requests/{$case->id}/submit")->assertRedirect("/requests/{$case->id}");
    $reference = caseRow($case->id)->reference_no;
    $this->actingAs($vendor)->post("/requests/{$case->id}/submit")
        ->assertRedirect("/requests/{$case->id}")
        ->assertSessionMissing('error');

    expect(caseRow($case->id)->reference_no)->toBe($reference)
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(1)
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->whereNull('user_id')->count())->toBe(1);
});

it('throttles submit at 10 a minute', function () {
    [$case, $vendor] = readyDraft();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($vendor)->post("/requests/{$case->id}/submit")->assertRedirect();
    }

    $this->actingAs($vendor)->post("/requests/{$case->id}/submit")->assertStatus(429);
});

// Show and index props ---------------------------------------------------------------------

it('offers submit on a ready draft and shows the reasons on an incomplete one', function () {
    [$ready, $vendor] = readyDraft();
    [$incomplete, $vendor2] = newDraft();

    $readyProps = pageProps($this->actingAs($vendor)->get("/requests/{$ready->id}")->assertOk());
    expect($readyProps['can']['submit'])->toBeTrue()
        ->and($readyProps['readiness']['is_ready'])->toBeTrue();
    expectVendorSafe($readyProps);

    $incompleteProps = pageProps($this->actingAs($vendor2)->get("/requests/{$incomplete->id}")->assertOk());
    expect($incompleteProps['can']['submit'])->toBeFalse()
        ->and($incompleteProps['readiness']['is_ready'])->toBeFalse()
        ->and(collect($incompleteProps['readiness']['errors'])->pluck('key'))->toContain('documents.bids');
});

it('shows the reference on the index once submitted and nothing for drafts', function () {
    [$submitted, $vendor] = readyDraft();
    [$draft] = newDraft(vendor: $vendor);
    $this->actingAs($vendor)->post("/requests/{$submitted->id}/submit");

    $props = pageProps($this->actingAs($vendor)->get('/requests')->assertOk());
    $rows = collect($props['requests']['data'])->keyBy('id');

    expect($rows[$submitted->id]['reference_no'])->toBe(caseRow($submitted->id)->reference_no)
        ->and($rows[$submitted->id]['status_label'])->toBe('Submitted')
        ->and($rows[$draft->id]['reference_no'])->toBeNull();
    expectVendorSafe($props);
});

// Receipt -------------------------------------------------------------------------------

it('returns 404 for the receipt of a draft', function () {
    [$case, $vendor] = readyDraft();

    $this->actingAs($vendor)->get("/requests/{$case->id}/receipt")->assertNotFound();
});

it('returns 404 for another organisation\'s receipt', function () {
    [$case, $vendor] = readyDraft();
    $this->actingAs($vendor)->post("/requests/{$case->id}/submit");

    $this->actingAs(vendorUser(vendorOrg()))->get("/requests/{$case->id}/receipt")->assertNotFound();
    $this->actingAs(vendorUser(vendorOrg()))->get('/requests/'.Str::lower((string) Str::ulid()).'/receipt')->assertNotFound();
});

it('renders the receipt after submission with only vendor-safe fields', function () {
    [$case, $vendor] = readyDraft();
    $this->actingAs($vendor)->post("/requests/{$case->id}/submit");
    $row = caseRow($case->id);

    $response = $this->actingAs(otherVendorInOrg($vendor))->get("/requests/{$case->id}/receipt")->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('requests/receipt')
        ->where('receipt.reference_no', $row->reference_no)
        ->where('receipt.organisation_name', $vendor->organisation()->first()->name)
        ->where('receipt.procurement_type', 'Ad hoc purchase')
        ->where('receipt.item_description', 'Infant feeding tube, size 6Fr')
        ->where('receipt.submitted_by', $vendor->name)
        ->where('receipt.submitted_at', fn ($v) => str_ends_with($v, 'Z'))
        ->has('receipt.documents', count(requiredPurposes($case)))
        ->has('receipt.documents.0', fn (Assert $doc) => $doc->hasAll(['label', 'display_name', 'version', 'uploaded_at']))
        ->where('receipt.hardcopy_notice', "Hardcopy must be delivered to the CMO's Office. Quote the reference number above."));

    $props = pageProps($response);
    expectVendorSafe($props);
    expect(json_encode($props))->not->toContain('sha256')
        ->not->toContain('storage_key')
        ->not->toContain('scan_status');
});

it('shows the submit failure reasons on the show page props after a failed submit', function () {
    [$case, $vendor] = newDraft();

    $this->actingAs($vendor)->from("/requests/{$case->id}")->post("/requests/{$case->id}/submit");
    $props = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}"));

    expect($props['errors'])->toHaveKey('submit.documents.bids');
});

// Slice F1: the receipt lists every category that has files (was: required ones only).
it('lists every category with files on the receipt, in the type order, earlier uploads last', function () {
    [$case, $vendor] = readyDraft(); // one CLEAN Bids file
    makeDocument($case, 'evidence', ['original_name' => 'usage.xlsx']);
    makeDocument($case, 'regulatory', ['original_name' => 'registration.pdf']);
    makeDocument($case, 'original_rfq', ['original_name' => 'old-rfq.pdf']);
    makeDocument($case, 'regulatory', ['original_name' => 'still-scanning.pdf', 'scan_status' => 'PENDING', 'released_at' => null]);
    $this->actingAs($vendor)->post("/requests/{$case->id}/submit");

    $documents = pageProps($this->actingAs($vendor)->get("/requests/{$case->id}/receipt"))['receipt']['documents'];

    expect(collect($documents)->map(fn ($d) => [$d['label'], $d['display_name']])->all())->toBe([
        ['Bids', 'bids.pdf'],
        ['Regulatory documents', 'registration.pdf'],
        ['Evidence', 'usage.xlsx'],
        ['Earlier uploads', 'old-rfq.pdf'],
    ]);
});
