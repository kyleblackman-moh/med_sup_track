<?php

use App\Enums\CaseStatus as S;
use App\Enums\QueryStatus;
use App\Enums\Role;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryRoute;
use App\Models\User;
use App\Queries\QueryAccess;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// One open query per route on a case (Kyle, 2026-11-23): once Legal (say) has
// sent a query, the case page no longer offers raising another on that route
// until the first is closed or cancelled; follow-ups are replies in the
// thread. The raise endpoint refuses it as well. Other routes stay open.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    $this->seed(QueryRouteSeeder::class);
    Notification::fake();

    $this->vendor = vendorUser(vendorOrg());
    $this->legal = caseUser(Role::LegalOfficer);
    $this->ps = caseUser(Role::PsOffice);
    $this->technocrat = caseUser(Role::Technocrat);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
});

function opCase(User $vendor, S $status, array $desks): ProcurementCase
{
    $case = makeCase([
        'org_id' => $vendor->org_id, 'created_by' => $vendor->id, 'status' => $status,
        'reference_no' => 'NR-OP-'.Str::upper(Str::random(8)), 'submitted_at' => now()->subDays(5),
    ]);
    foreach ($desks as [$role, $user, $purpose]) {
        DB::table('case_assignments')->where('id', assign($case, $role, $user)->id)->update(['purpose' => $purpose]);
    }

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

function opRoute(string $from, string $to): QueryRoute
{
    return QueryRoute::query()->where('from_role', $from)->where('to_role', $to)->firstOrFail();
}

/** The route ids the case page offers $user (null: no raise form at all). */
function opOffered(User $user, ProcurementCase $case): ?array
{
    $raise = pageProps(test()->actingAs($user)->get(route('cases.show', $case))->assertOk())['queries']['raise'] ?? null;

    return $raise === null ? null : array_column($raise['routes'], 'id');
}

function opRaise(User $user, ProcurementCase $case, QueryRoute $route)
{
    return test()->actingAs($user)->post(route('cases.queries.store', $case), ['route' => $route->id, 'body' => 'Please confirm the clause.']);
}

it('removes the raise form once Legal has sent its query, and brings it back when the query is closed', function () {
    $case = opCase($this->vendor, S::WITH_LEGAL, [[Role::LegalOfficer, $this->legal, 'legal'], [Role::Technocrat, $this->technocrat, 'technical']]);
    $route = opRoute('legal_officer', 'technocrat');

    expect(opOffered($this->legal, $case))->toBe([(int) $route->id]);

    opRaise($this->legal, $case, $route)->assertSessionHasNoErrors();
    expect(opOffered($this->legal, $case))->toBeNull();

    // The endpoint refuses a second one too (the page is not the only guard).
    // The endpoint refuses a second one too (the page is not the only guard): Legal has
    // no other route, so nothing is raisable and the raise is 403, as for any refusal.
    opRaise($this->legal, $case, $route)->assertForbidden();
    expect(CaseQuery::where('case_id', $case->id)->count())->toBe(1);

    // Ended: the route is offered again.
    CaseQuery::where('case_id', $case->id)->update(['status' => QueryStatus::CLOSED->value]);
    expect(opOffered($this->legal, $case))->toBe([(int) $route->id]);
});

it('keeps every query state that is not ended counted as open', function (QueryStatus $status, bool $offered) {
    $case = opCase($this->vendor, S::WITH_LEGAL, [[Role::LegalOfficer, $this->legal, 'legal'], [Role::Technocrat, $this->technocrat, 'technical']]);
    $route = opRoute('legal_officer', 'technocrat');
    opRaise($this->legal, $case, $route)->assertSessionHasNoErrors();
    CaseQuery::where('case_id', $case->id)->update(['status' => $status->value]);

    expect(opOffered($this->legal, $case) !== null)->toBe($offered);
})->with([
    'awaiting forward' => [QueryStatus::AWAITING_FORWARD, false],
    'open' => [QueryStatus::OPEN, false],
    'answered' => [QueryStatus::ANSWERED, false],
    'closed' => [QueryStatus::CLOSED, true],
    'cancelled' => [QueryStatus::CANCELLED, true],
]);

it('blocks only the route already used: the PS can still raise its other query', function () {
    $case = opCase($this->vendor, S::WITH_PS, [[Role::LegalOfficer, $this->legal, 'legal'], [Role::Technocrat, $this->technocrat, 'technical']]);
    $toLegal = opRoute('ps_office', 'legal_officer');
    $toTech = opRoute('ps_office', 'technocrat');

    expect(collect(opOffered($this->ps, $case))->sort()->values()->all())->toBe(collect([(int) $toLegal->id, (int) $toTech->id])->sort()->values()->all());

    opRaise($this->ps, $case, $toLegal)->assertSessionHasNoErrors();

    expect(opOffered($this->ps, $case))->toBe([(int) $toTech->id]);
    // A second query on the used route is refused; nothing new is saved.
    opRaise($this->ps, $case, $toLegal)->assertSessionHasErrors('route');
    expect(CaseQuery::where('case_id', $case->id)->count())->toBe(1);
    opRaise($this->ps, $case, $toTech)->assertSessionHasNoErrors();
    expect(opOffered($this->ps, $case))->toBeNull();
});

it('treats the Technocrat\'s query to the vendor the same way', function () {
    $case = opCase($this->vendor, S::IN_REVIEW, [[Role::Technocrat, $this->technocrat, 'technical']]);
    $toVendor = opRoute('technocrat', 'vendor_applicant');

    expect(opOffered($this->technocrat, $case))->toContain((int) $toVendor->id);
    opRaise($this->technocrat, $case, $toVendor)->assertSessionHasNoErrors();

    expect(opOffered($this->technocrat, $case) ?? [])->not->toContain((int) $toVendor->id);
});
