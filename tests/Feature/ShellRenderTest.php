<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Models\User;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// V2-4: every main page renders inside the navy shell for the right users, and
// the shell's shared props (app name, crest, user card subtitle) come from the
// server. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A submitted case with a requisition, in $orgId's organisation when given. */
function shellSubmittedCase(int|string|null $orgId = null): ProcurementCase
{
    $case = makeCase([
        ...($orgId !== null ? ['org_id' => $orgId] : []),
        'status' => CaseStatus::SUBMITTED,
        'submitted_at' => now()->subDay(),
        'reference_no' => 'NR-SHELL-'.Str::upper(Str::random(8)),
        'current_holder_role' => 'cmo',
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Shell item'])->save();

    return $case;
}

/** The shared props the shell needs are present. */
function expectShellProps(array $props): void
{
    expect($props)->toHaveKeys(['name', 'auth', 'nav', 'brand'])
        ->and($props['name'])->toBe(config('app.name'))
        ->and($props['auth']['user'])->toHaveKey('subtitle');
}

it('renders the vendor pages inside the shell', function () {
    [$draft, $vendor] = newDraft('TENDER_EXCESS');
    $submitted = shellSubmittedCase($vendor->org_id);

    foreach ([
        [route('requests.index'), 'requests/index'],
        [route('requests.show', $submitted), 'requests/show'],
        [route('requests.edit', $draft), 'requests/ad-hoc/edit'],
        [route('profile.edit'), 'settings/profile'],
    ] as [$url, $component]) {
        $response = $this->actingAs($vendor)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        expectShellProps(pageProps($response));
    }
});

it('renders the staff pages inside the shell', function () {
    $cmo = caseUser(Role::Cmo);
    $case = shellSubmittedCase();

    foreach ([
        [route('dashboard'), 'dashboard'],
        [route('cases.index'), 'cases/index'],
        [route('cases.show', $case), 'cases/show'],
        [route('profile.edit'), 'settings/profile'],
    ] as [$url, $component]) {
        $response = $this->actingAs($cmo)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        expectShellProps(pageProps($response));
    }
});

it('renders the sign-in page and the forced password change page', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/login'));

    $user = caseUser(Role::Cmo);
    $user->forceFill(['password_change_required' => true])->save();

    $this->actingAs($user)->get(route('password.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/password'));
});

it('shares the app name from config for the header and the tab title', function () {
    config(['app.name' => 'Shell Test Name']);

    expect(pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('dashboard'))->assertOk())['name'])->toBe('Shell Test Name');
});

it('shares brand.logo as null until the crest file exists, never under can', function () {
    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('dashboard'))->assertOk());

    expect(file_exists(public_path(HandleInertiaRequests::BRAND_LOGO)))->toBeFalse()
        ->and($props['brand'])->toBe(['logo' => null])
        ->and($props['can'])->not->toHaveKey('brand')
        ->and($props['can'])->not->toHaveKey('logo');
});

it('gives a vendor their organisation and staff their role name as the user card subtitle', function () {
    [, $vendor] = newDraft('TENDER_EXCESS');
    $vendorProps = pageProps($this->actingAs($vendor)->get(route('requests.index'))->assertOk());
    expect($vendorProps['auth']['user']['subtitle'])->toBe($vendor->organisation->name);

    expect(HandleInertiaRequests::userSubtitle(caseUser(Role::Technocrat)))->toBe(Role::Technocrat->label())
        ->and(HandleInertiaRequests::userSubtitle(User::factory()->create(['user_type' => 'staff'])))->toBeNull();
});
