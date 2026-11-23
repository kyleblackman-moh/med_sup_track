<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Http\Controllers\HardcopyLookupController;
use App\Models\ProcurementCase;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// HARDCOPY-1: the dashboard's reference lookup (from Kyle's WIP 9dbb7c2,
// without the cover sheet PDF). DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Mail::fake();

    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function lookupCase($t, S $status): ProcurementCase
{
    $case = makeCase([
        'org_id' => $t->org->id,
        'created_by' => $t->vendor->id,
        'status' => $status,
        'reference_no' => $status === S::DRAFT ? null : 'NR-2026-'.Str::upper(Str::random(6)),
        'submitted_at' => $status === S::DRAFT ? null : CarbonImmutable::now('UTC')->subDay(),
    ]);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

it('opens the case on an exact reference match, ignoring case and outer spaces', function () {
    $case = lookupCase($this, S::SUBMITTED);
    $registry = caseUser(Role::CmoRegistry);
    assign($case, Role::CmoRegistry, $registry);

    $this->actingAs($registry)->get(route('cases.hardcopy-lookup', ['reference' => '  '.Str::lower($case->reference_no).' ']))
        ->assertRedirect(route('cases.show', $case->id));
});

it('finds nothing for a partial reference or a case out of scope', function () {
    $case = lookupCase($this, S::SUBMITTED);
    $registry = caseUser(Role::CmoRegistry);
    assign($case, Role::CmoRegistry, $registry);
    $hidden = lookupCase($this, S::SUBMITTED);

    $this->actingAs($registry)->from(route('dashboard'))->get(route('cases.hardcopy-lookup', ['reference' => substr($case->reference_no, 0, -2)]))
        ->assertRedirect(route('dashboard'))->assertSessionHasErrors(['reference' => HardcopyLookupController::NOT_FOUND]);
    $this->actingAs($registry)->get(route('cases.hardcopy-lookup', ['reference' => $hidden->reference_no]))
        ->assertSessionHasErrors(['reference' => HardcopyLookupController::NOT_FOUND]);
});

it('shows the lookup on the dashboard only for hardcopy.confirm holders', function () {
    expect(pageProps($this->actingAs(caseUser(Role::CmoRegistry))->get(route('dashboard')))['hardcopyLookup'])->toBeTrue()
        ->and(pageProps($this->actingAs(caseUser(Role::Technocrat))->get(route('dashboard')))['hardcopyLookup'])->toBeFalse();
});
