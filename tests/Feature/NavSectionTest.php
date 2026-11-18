<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CaseRequisition;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// The sidebar's active item: the server shares nav.section, matched by URL
// prefix, so a nested page (a request's edit page, a case page) activates its
// section. Display only; never under the shared `can`. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('matches the section by URL prefix', function (string $path, ?string $section) {
    expect(HandleInertiaRequests::navSection(Request::create($path)))->toBe($section);
})->with([
    ['/dashboard', 'dashboard'],
    ['/requests', 'requests'],
    ['/requests/01jabcdefghjkmnpqrstvwxyz0/edit', 'requests'],
    ['/requests/01jabcdefghjkmnpqrstvwxyz0/receipt', 'requests'],
    ['/cases', 'cases'],
    ['/cases/01jabcdefghjkmnpqrstvwxyz0', 'cases'],
    ['/settings/profile', null],
    ['/requestsx', null],
    ['/', null],
]);

it('activates Requests on a vendor request page and its nested pages', function () {
    [$case, $vendor] = newDraft('TENDER_EXCESS');

    foreach ([route('requests.index'), route('requests.edit', $case->id)] as $url) {
        $props = pageProps($this->actingAs($vendor)->get($url)->assertOk());

        expect($props['nav']['section'])->toBe('requests', $url)
            ->and($props['can'])->not->toHaveKey('section');
    }
});

it('activates the cases item on the staff case list and a case page, and Dashboard on the dashboard', function () {
    $cmo = caseUser(Role::Cmo);
    $case = makeCase([
        'status' => CaseStatus::SUBMITTED,
        'submitted_at' => now()->subDay(),
        'reference_no' => 'NR-NAV-'.Str::upper(Str::random(8)),
        'current_holder_role' => 'cmo',
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Nav item'])->save();

    // The case page has its own `can` prop; nav still arrives.
    expect(pageProps($this->actingAs($cmo)->get(route('cases.index'))->assertOk())['nav']['section'])->toBe('cases')
        ->and(pageProps($this->actingAs($cmo)->get(route('cases.show', $case))->assertOk())['nav']['section'])->toBe('cases')
        ->and(pageProps($this->actingAs($cmo)->get(route('dashboard'))->assertOk())['nav']['section'])->toBe('dashboard');
});

it('activates no section on the settings pages', function () {
    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('profile.edit'))->assertOk());

    expect($props['nav']['section'])->toBeNull();
});
