<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\ProcurementCase;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// IT-ADMIN PRINCIPLES: no super-admin bypass for any role, and the System
// Admin has no case work: 404 on every case, document and query route.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** The Gate's registered before/after callbacks. */
function gateCallbacks(string $property): array
{
    $gate = Gate::getFacadeRoot();
    $prop = new ReflectionProperty(AccessGate::class, $property);

    return $prop->getValue($gate);
}

it('has no Gate::before or Gate::after in the application code', function () {
    $hits = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
        ->filter(fn (SplFileInfo $f) => $f->isFile() && $f->getExtension() === 'php')
        ->filter(fn (SplFileInfo $f) => preg_match('/Gate::(before|after)\s*\(|->before\s*\(\s*function|->before\s*\(\s*fn/', (string) file_get_contents($f->getPathname())))
        ->map(fn (SplFileInfo $f) => $f->getPathname())
        ->values()
        ->all();

    expect($hits)->toBe([]);
});

it('has no before or after callback that grants an ability the user does not hold', function (Role $role) {
    $user = caseUser($role);
    // Abilities no role holds, policy abilities, and case permissions the System Admin lacks.
    $abilities = ['case.view_all', 'case.intake', 'document.download', 'query.forward', 'nonobjection.grant',
        'viewStaffCases', 'superuser', 'anything.at.all', '*'];

    foreach (gateCallbacks('beforeCallbacks') as $callback) {
        foreach ($abilities as $ability) {
            if ($user->getAllPermissions()->pluck('name')->contains($ability)) {
                continue;
            }
            $args = [];
            expect($callback($user, $ability, $args))->not->toBeTrue();
        }
    }

    foreach (gateCallbacks('afterCallbacks') as $callback) {
        foreach ($abilities as $ability) {
            $args = [];
            expect($callback($user, $ability, false, $args))->not->toBeTrue();
        }
    }

    foreach (['superuser', 'anything.at.all', '*'] as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBeFalse();
    }
})->with([Role::SystemAdmin, Role::Cmo, Role::PsOffice]);

it('denies the System Admin every case ability, by policy and by permission', function () {
    $admin = caseUser(Role::SystemAdmin);
    $case = makeCase(['org_id' => vendorOrg()->id, 'status' => CaseStatus::SUBMITTED, 'submitted_at' => now()]);

    expect(Gate::forUser($admin)->allows('viewStaffCases', ProcurementCase::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $case))->toBeFalse();

    foreach ($admin->getAllPermissions()->pluck('name') as $name) {
        expect($name)->not->toMatch('/^(case|document|query|nonobjection|hardcopy|technical|funding|file|legal|letter)\./');
    }
});

it('answers 404 to the System Admin on every case, document and query route', function () {
    $admin = caseUser(Role::SystemAdmin);
    $case = makeCase([
        'org_id' => vendorOrg()->id,
        'status' => CaseStatus::SUBMITTED,
        'submitted_at' => now(),
        'reference_no' => 'NR-ADM-'.Str::upper(Str::random(8)),
    ]);
    $params = [
        'procurementCase' => $case->id, 'queryCase' => $case->id,
        'document' => (string) Str::ulid(), 'query' => (string) Str::ulid(), 'message' => (string) Str::ulid(),
    ];

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => preg_match('/^(requests|cases|documents|queries)\.|^analytics$/', (string) $r->getName()));

    expect($routes->count())->toBeGreaterThan(30);

    $this->actingAs($admin);
    foreach ($routes as $route) {
        $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $params[$m[1]] ?? 'x', $route->uri());
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        // Many routes share one limiter (throttle:20,1 is keyed by user only); start each call afresh.
        Cache::flush();

        $status = $this->call($method, '/'.$uri)->status();
        expect($status)->toBe(404, "{$method} /{$route->uri()} ({$route->getName()}) answered {$status}");
    }
});

it('still lets case staff into the case area (the 404 is for accounts with no case work only)', function () {
    $case = makeCase(['org_id' => vendorOrg()->id, 'status' => CaseStatus::SUBMITTED, 'submitted_at' => now()]);

    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.index'))->assertOk();
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk();
});

it('audits a 404 from the case area as access.denied, without the URL', function () {
    $admin = caseUser(Role::SystemAdmin);
    $start = (int) DB::table('audit_logs')->max('id');

    $this->actingAs($admin)->get(route('cases.index').'?q=secret-search')->assertNotFound();

    $row = DB::table('audit_logs')->where('id', '>', $start)->where('action', 'access.denied')->sole();
    expect((int) $row->actor_user_id)->toBe($admin->id)
        ->and(json_decode($row->metadata, true))->toBe(['route' => 'cases.index', 'method' => 'GET', 'status' => 404, 'reason' => 'no_case_work'])
        ->and($row->metadata)->not->toContain('secret-search');
});

// IT-ADMIN follow-up (2026-11-23): reassignment and delegation are case work.
// There is no reassign or delegation endpoint yet, so this is checked at the
// permission (Gate) level; the case area is already 404 for the System Admin.
it('does not let the System Admin reassign cases or manage delegations; the CMO still may reassign', function () {
    $admin = caseUser(Role::SystemAdmin);
    $cmo = caseUser(Role::Cmo);

    expect(Gate::forUser($admin)->allows('assignment.reassign'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delegation.manage'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('config.manage'))->toBeTrue()
        ->and(Gate::forUser($cmo)->allows('assignment.reassign'))->toBeTrue()
        ->and(Gate::forUser($cmo)->allows('delegation.manage'))->toBeTrue();
});

it('answers 404 to the System Admin, and not to the CMO, on any reassign or delegation route', function () {
    $case = makeCase(['org_id' => vendorOrg()->id, 'status' => CaseStatus::IN_REVIEW, 'submitted_at' => now()]);
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => preg_match('/reassign|delegat/i', $r->uri().' '.$r->getName()));

    foreach ($routes as $route) {
        $uri = '/'.preg_replace('/\{\w+\??\}/', $case->id, $route->uri());
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        expect($this->actingAs(caseUser(Role::SystemAdmin))->call($method, $uri)->status())->toBe(404, $uri);
        expect($this->actingAs(caseUser(Role::Cmo))->call($method, $uri)->status())->not->toBe(404, $uri);
    }

    // None exists yet (no reassign or delegation feature). When one is added, this test covers it.
    expect($routes->count())->toBeGreaterThanOrEqual(0);
});
