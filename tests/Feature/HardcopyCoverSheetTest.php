<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// FR-03 / FR-04: the printable cover sheet (vendor and staff), the dashboard's
// reference lookup, and the lookup flag. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);

    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org);
    $this->cmo = caseUser(Role::Cmo);
    $this->registry = caseUser(Role::CmoRegistry);
    $this->tech = caseUser(Role::Technocrat);
    $this->case = coverCase($this, S::SUBMITTED);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function coverCase($t, S $status, ?string $reference = null): ProcurementCase
{
    $case = makeCase([
        'org_id' => $t->org->id,
        'created_by' => $t->vendor->id,
        'status' => $status,
        'reference_no' => $status === S::DRAFT ? null : ($reference ?? 'NR-CS-'.Str::upper(Str::random(8))),
        'submitted_at' => $status === S::DRAFT ? null : now()->subDay(),
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Cover sheet item'])->save();

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

it('gives the vendor and the CMO the cover sheet with the reference and a QR code', function () {
    foreach ([[$this->vendor, 'requests.cover-sheet'], [$this->cmo, 'cases.cover-sheet']] as [$user, $route]) {
        $this->actingAs($user)->get(route($route, $this->case))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('cover-sheet')
                ->where('sheet.reference_no', $this->case->reference_no)
                ->where('sheet.organisation_name', $this->org->name)
                ->where('sheet.item_description', 'Cover sheet item')
                ->where('sheet.qr_svg', fn (string $svg) => str_starts_with($svg, '<svg') && ! str_contains($svg, '<?xml')));
    }
});

it('has no cover sheet for a draft, and none for another organisation', function () {
    $draft = coverCase($this, S::DRAFT);
    $this->actingAs($this->vendor)->get(route('requests.cover-sheet', $draft))->assertNotFound();

    $this->actingAs(vendorUser(vendorOrg()))->get(route('requests.cover-sheet', $this->case))->assertNotFound();
});

it('never puts an internal id or link in the sheet', function () {
    $sheet = pageProps($this->actingAs($this->vendor)->get(route('requests.cover-sheet', $this->case)))['sheet'];

    expect(array_keys($sheet))->toBe(['id', 'reference_no', 'organisation_name', 'procurement_type', 'item_description', 'submitted_at', 'qr_svg', 'instruction'])
        // The SVG namespace is the only URL allowed: no link to the app or the case.
        ->and(str_replace('http://www.w3.org/2000/svg', '', $sheet['qr_svg']))->not->toContain('http')
        ->and($sheet['qr_svg'])->not->toContain(url('/'));
    expectVendorSafe(pageProps($this->actingAs($this->vendor)->get(route('requests.cover-sheet', $this->case))));
});

it('opens the case from its reference, typed in any case and with spaces', function () {
    $this->actingAs($this->registry);
    assign($this->case, Role::CmoRegistry, $this->registry);

    $this->get(route('cases.lookup', ['reference' => '  '.Str::lower($this->case->reference_no).' ']))
        ->assertRedirect(route('cases.show', $this->case));

    $this->actingAs($this->cmo)->get(route('cases.lookup', ['reference' => $this->case->reference_no]))
        ->assertRedirect(route('cases.show', $this->case));
});

it('answers an unknown, a draft and an invisible reference the same way', function () {
    $hidden = coverCase($this, S::SUBMITTED); // the registry user is not assigned to it

    foreach (['NR-NOPE-0000', $hidden->reference_no] as $reference) {
        $this->actingAs($this->registry)->from(route('dashboard'))
            ->get(route('cases.lookup', ['reference' => $reference]))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('reference');
    }
});

it('refuses the lookup without hardcopy.confirm, and shares the flag only with those who have it', function () {
    $this->actingAs($this->tech)->get(route('cases.lookup', ['reference' => $this->case->reference_no]))->assertForbidden();

    expect(pageProps($this->actingAs($this->cmo)->get(route('dashboard'))->assertOk())['hardcopyLookup'])->toBeTrue()
        ->and(pageProps($this->actingAs($this->tech)->get(route('dashboard'))->assertOk())['hardcopyLookup'])->toBeFalse()
        ->and(pageProps($this->actingAs($this->vendor)->get(route('dashboard'))->assertOk())['hardcopyLookup'])->toBeFalse();
});

it('offers staff the cover sheet on a case with a reference', function () {
    expect(pageProps($this->actingAs($this->cmo)->get(route('cases.show', $this->case))->assertOk())['can']['cover_sheet'])->toBeTrue();
});
