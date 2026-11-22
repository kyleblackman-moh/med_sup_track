<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Models\User;
use App\Queries\QueryAccess;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// QUERIES-1: the sidebar Queries page (queries.index) and its badge
// (openQueriesCount, nav.queries). Rows come from QueryInbox: the queries
// waiting on the signed-in user only. DatabaseTransactions only; the dev
// database holds other rows, so assertions look at this test's case.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    $this->seed(QueryRouteSeeder::class);
    Notification::fake();

    $tag = Str::upper(Str::random(4));
    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org, ['name' => "QP Vendor {$tag}"]);
    $this->otherVendor = vendorUser(vendorOrg());
    $this->cmo = caseUser(Role::Cmo);
    $this->tech = caseUser(Role::Technocrat);
    $this->otherTech = caseUser(Role::Technocrat);
    $this->legal = caseUser(Role::LegalOfficer);
    $this->cab = caseUser(Role::CabOfficer);

    $case = makeCase([
        'org_id' => $this->org->id,
        'created_by' => $this->vendor->id,
        'status' => S::WITH_LEGAL,
        'reference_no' => 'NR-QP-'.Str::upper(Str::random(8)),
        'submitted_at' => now()->subDays(5),
    ]);
    foreach ([[Role::Technocrat, $this->tech, 'technical'], [Role::CabOfficer, $this->cab, 'funding'], [Role::LegalOfficer, $this->legal, 'legal']] as [$role, $user, $purpose]) {
        DB::table('case_assignments')->where('id', assign($case, $role, $user)->id)->update(['purpose' => $purpose]);
    }
    $this->case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
});

function qpRaise(User $user, ProcurementCase $case, string $from, string $to, string $body = 'Please confirm the clause.'): CaseQuery
{
    $route = QueryRoute::query()->where('from_role', $from)->where('to_role', $to)->firstOrFail();
    test()->actingAs($user)->post(route('cases.queries.store', $case), ['route' => $route->id, 'body' => $body])
        ->assertSessionHasNoErrors()->assertRedirect();

    return CaseQuery::withoutGlobalScopes()->where('case_id', $case->id)->latest('created_at')->latest('id')->firstOrFail();
}

function qpForward(User $cmo, ProcurementCase $case, CaseQuery $query): void
{
    $held = QueryMessage::query()->where('query_id', $query->id)->whereNull('forwarded_at')->where('requires_forward', true)->latest('created_at')->firstOrFail();
    test()->actingAs($cmo)->post(route('cases.queries.forward', [$case, $query->id, $held->id]))->assertSessionHasNoErrors();
}

/** This test case's rows on $user's Queries page. */
function qpRows(User $user, ProcurementCase $case, array $query = []): array
{
    $rows = pageProps(test()->actingAs($user)->get(route('queries.index', $query))->assertOk())['queries'];

    return array_values(array_filter($rows, fn (array $r) => $r['case_id'] === (string) $case->id));
}

it('needs a signed-in user, and refuses users who neither submit requests nor see staff cases', function () {
    $this->get(route('queries.index'))->assertRedirect(route('login'));
    // IT-ADMIN: the System Admin has no case work, so the case area (queries included) is 404.
    $this->actingAs(caseUser(Role::SystemAdmin))->get(route('queries.index'))->assertNotFound();
});

it('gives the CMO a held query to forward, and the recipients nothing until it is forwarded', function () {
    $query = qpRaise($this->legal, $this->case, 'legal_officer', 'technocrat', 'Legal words for the Technocrats');

    $cmoRows = qpRows($this->cmo, $this->case);
    expect($cmoRows)->toHaveCount(1)
        ->and($cmoRows[0]['id'])->toBe((string) $query->id)
        ->and($cmoRows[0]['step'])->toBe('query_forward')
        ->and($cmoRows[0]['action'])->toBe('Forward Query')
        ->and($cmoRows[0]['reference_no'])->toBe($this->case->reference_no)
        ->and($cmoRows[0]['url'])->toBe(route('cases.show', [$this->case->id, 'tab' => 'queries']))
        ->and(qpRows($this->tech, $this->case))->toBe([]);

    qpForward($this->cmo, $this->case, $query);

    $techRows = qpRows($this->tech, $this->case);
    expect(qpRows($this->cmo, $this->case))->toBe([])
        ->and($techRows)->toHaveCount(1)
        ->and($techRows[0]['step'])->toBe('query_answer')
        ->and($techRows[0]['action'])->toBe('Answer Query')
        ->and($techRows[0]['waiting_since'])->not->toBeNull()
        ->and(qpRows($this->otherTech, $this->case))->toBe([])
        ->and(qpRows($this->legal, $this->case))->toBe([]);
});

it('drops a query from the page once it is answered', function () {
    $query = qpRaise($this->tech, $this->case, 'technocrat', 'vendor_applicant', 'Please send the batch certificate.');
    expect(qpRows($this->vendor, $this->case))->toHaveCount(1);

    $this->actingAs($this->vendor)->post(route('requests.queries.reply', [$this->case, $query->id]), ['body' => 'Attached in Documents today.'])
        ->assertSessionHasNoErrors();

    expect(qpRows($this->vendor, $this->case))->toBe([]);
});

it('gives the vendor the vendor wording only: no office names, organisation or message text', function () {
    $query = qpRaise($this->tech, $this->case, 'technocrat', 'vendor_applicant', 'Please send the batch certificate.');

    $rows = qpRows($this->vendor, $this->case);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['title'])->toBe('Query From the Ministry of Health')
        ->and($rows[0]['action'])->toBe('Reply')
        ->and($rows[0]['organisation'])->toBeNull()
        ->and($rows[0]['url'])->toBe(route('requests.show', $this->case->id).'#vendor-query-'.$query->id)
        ->and(qpRows($this->otherVendor, $this->case))->toBe([]);

    $html = $this->actingAs($this->vendor)->get(route('queries.index'))->getContent();
    expect($html)->not->toContain('Please send the batch certificate.');
});

it('never shows the vendor an internal query', function () {
    qpRaise($this->tech, $this->case, 'technocrat', 'cmo', 'Internal technocrat text');

    expect(qpRows($this->vendor, $this->case))->toBe([]);
});

it('filters to answer and forward rows and reports a bad filter', function () {
    qpRaise($this->legal, $this->case, 'legal_officer', 'technocrat');

    $props = pageProps($this->actingAs($this->cmo)->get(route('queries.index', ['filter' => 'answer']))->assertOk());
    expect($props['filter'])->toBe('answer')
        ->and($props['forward_desk'])->toBeTrue()
        ->and(array_filter($props['queries'], fn (array $r) => $r['case_id'] === (string) $this->case->id))->toBe([])
        ->and($props['counts']['forward'])->toBeGreaterThanOrEqual(1);

    expect(qpRows($this->cmo, $this->case, ['filter' => 'forward']))->toHaveCount(1);

    $this->actingAs($this->cmo)->get(route('queries.index', ['filter' => 'everything']))
        ->assertRedirect(route('queries.index'))
        ->assertSessionHasErrors('filter');
});

it('shares the sidebar flag and the count with every page', function () {
    $before = pageProps($this->actingAs($this->tech)->get(route('dashboard'))->assertOk())['openQueriesCount'];

    $query = qpRaise($this->legal, $this->case, 'legal_officer', 'technocrat');
    qpForward($this->cmo, $this->case, $query);

    $this->actingAs($this->tech)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav.queries', true)
            ->where('openQueriesCount', $before + 1));

    $this->actingAs(caseUser(Role::SystemAdmin))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('nav.queries', false)
            ->where('openQueriesCount', 0));
});
