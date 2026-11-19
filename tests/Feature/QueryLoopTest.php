<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus as S;
use App\Enums\QueryStatus;
use App\Enums\Role;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Models\User;
use App\Queries\QueryAccess;
use App\Queries\QueryView;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// OVERNIGHT-2 (D2-D5): query loops, driven through the real routes.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    $this->seed(QueryRouteSeeder::class);
    Notification::fake();

    $this->org = vendorOrg();
    $this->a = qlActors($this->org);
    $this->case = qlCase($this->a, S::WITH_LEGAL);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
});

/** @return array<string, User> */
function qlActors($org): array
{
    $tag = Str::upper(Str::random(4));

    return [
        'cmo' => caseUser(Role::Cmo, ['name' => "QL CMO {$tag}"]),
        'technocrat' => caseUser(Role::Technocrat, ['name' => "QL Tech {$tag}"]),
        'other_technocrat' => caseUser(Role::Technocrat, ['name' => "QL Tech Two {$tag}"]),
        'cab' => caseUser(Role::CabOfficer, ['name' => "QL CAB {$tag}"]),
        'legal' => caseUser(Role::LegalOfficer, ['name' => "QL Legal {$tag}"]),
        'ps' => caseUser(Role::PsOffice, ['name' => "QL PS {$tag}"]),
        'registry' => caseUser(Role::CmoRegistry, ['name' => "QL Registry {$tag}"]),
        'vendor' => vendorUser($org, ['name' => "QL Vendor {$tag}"]),
        'other_vendor' => vendorUser(vendorOrg(), ['name' => "QL Other Vendor {$tag}"]),
        'system_admin' => caseUser(Role::SystemAdmin),
        'auditor' => caseUser(Role::Auditor),
    ];
}

/** A case at $status with the Technocrat, CAB and Legal desks assigned (the second Technocrat is not). */
function qlCase(array $a, S $status): ProcurementCase
{
    $case = makeCase([
        'org_id' => $a['vendor']->org_id,
        'created_by' => $a['vendor']->id,
        'status' => $status,
        'reference_no' => 'NR-QL-'.Str::upper(Str::random(8)),
        'submitted_at' => now()->subDays(5),
    ]);
    assign($case, Role::Technocrat, $a['technocrat']);
    assign($case, Role::CabOfficer, $a['cab']);
    assign($case, Role::LegalOfficer, $a['legal']);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

function qlRoute(string $from, string $to): QueryRoute
{
    return QueryRoute::query()->where('from_role', $from)->where('to_role', $to)->firstOrFail();
}

/** Raise through the real route; returns the new query. */
function qlRaise(User $user, ProcurementCase $case, QueryRoute $route, string $body = 'Please confirm the clause.'): CaseQuery
{
    test()->actingAs($user)->post(route('cases.queries.store', $case), ['route' => $route->id, 'body' => $body])
        ->assertSessionHasNoErrors()->assertRedirect();

    return CaseQuery::withoutGlobalScopes()->where('case_id', $case->id)->latest('created_at')->latest('id')->firstOrFail();
}

function qlHeld(CaseQuery $query): QueryMessage
{
    return QueryMessage::query()->where('query_id', $query->id)->whereNull('forwarded_at')->where('requires_forward', true)->latest('created_at')->firstOrFail();
}

/** The query ids $user sees on $case through the staff DTO. */
function qlSees(User $user, ProcurementCase $case): array
{
    test()->actingAs($user);

    return array_column(QueryView::forStaff(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $user)['queries'], 'id');
}

function qlFresh(CaseQuery $query): CaseQuery
{
    return CaseQuery::withoutGlobalScopes()->findOrFail($query->id);
}

// D2: staff flows ---------------------------------------------------------------

it('holds a Legal query for the CMO, then delivers it once forwarded', function () {
    $query = qlRaise($this->a['legal'], $this->case, qlRoute('legal_officer', 'technocrat'), 'Legal words for the Technocrats');

    expect(qlFresh($query)->status)->toBe(QueryStatus::AWAITING_FORWARD)
        ->and(qlSees($this->a['legal'], $this->case))->toBe([$query->id])
        ->and(qlSees($this->a['cmo'], $this->case))->toBe([$query->id])
        ->and(qlSees($this->a['technocrat'], $this->case))->toBe([]);

    // The Technocrat cannot reach it at all while it is held.
    $this->actingAs($this->a['technocrat'])->post(route('cases.queries.reply', [$this->case, $query->id]), ['body' => 'An answer'])->assertNotFound();

    $cmoView = QueryView::forStaff($this->case, $this->a['cmo'])['queries'][0];
    expect($cmoView['messages'][0]['forward']['label'])->toBe('Forward Query to Technocrats')
        ->and($cmoView['waiting_on'])->toBe("Waiting on the CMO's Office to forward it");

    $held = qlHeld($query);
    $this->actingAs($this->a['cmo'])->post(route('cases.queries.forward', [$this->case, $query->id, $held->id]))
        ->assertSessionHasNoErrors()->assertRedirect();

    $forwarded = QueryMessage::findOrFail($held->id);
    expect((int) $forwarded->forwarded_by_cmo_id)->toBe($this->a['cmo']->id)
        ->and($forwarded->forwarded_at)->not->toBeNull()
        ->and($forwarded->body)->toBe('Legal words for the Technocrats')
        ->and(qlFresh($query)->status)->toBe(QueryStatus::OPEN)
        ->and(qlSees($this->a['technocrat'], $this->case))->toBe([$query->id]);
});

it('returns the answer by the reverse route, held for the CMO again', function () {
    $query = qlRaise($this->a['legal'], $this->case, qlRoute('legal_officer', 'technocrat'));
    $this->actingAs($this->a['cmo'])->post(route('cases.queries.forward', [$this->case, $query->id, qlHeld($query)->id]));

    $this->actingAs($this->a['technocrat'])->post(route('cases.queries.reply', [$this->case, $query->id]), ['body' => 'The technical answer'])
        ->assertSessionHasNoErrors();

    $answer = qlHeld($query);
    expect($answer->author_role)->toBe('technocrat')
        ->and($answer->recipient_role)->toBe('legal_officer')
        ->and(qlFresh($query)->status)->toBe(QueryStatus::AWAITING_FORWARD);

    $this->actingAs($this->a['legal']);
    $legalView = QueryView::forStaff($this->case, $this->a['legal'])['queries'][0];
    expect(array_column($legalView['messages'], 'body'))->not->toContain('The technical answer');

    $this->actingAs($this->a['cmo']);
    expect(QueryView::forStaff($this->case, $this->a['cmo'])['queries'][0]['messages'][1]['forward']['label'])->toBe('Forward Query to Legal Unit');
    $this->post(route('cases.queries.forward', [$this->case, $query->id, $answer->id]))->assertSessionHasNoErrors();

    $this->actingAs($this->a['legal']);
    $legalView = QueryView::forStaff($this->case, $this->a['legal'])['queries'][0];
    expect(array_column($legalView['messages'], 'body'))->toContain('The technical answer')
        ->and(qlFresh($query)->status)->toBe(QueryStatus::ANSWERED);
});

it('delivers a direct Technocrat query to the vendor at once, read-only for the CMO', function () {
    $query = qlRaise($this->a['technocrat'], $this->case, qlRoute('technocrat', 'vendor_applicant'));

    expect(qlFresh($query)->status)->toBe(QueryStatus::OPEN)
        ->and(QueryMessage::where('query_id', $query->id)->value('visibility'))->toBe('external')
        ->and(QueryMessage::where('query_id', $query->id)->value('requires_forward'))->toBeFalsy();

    $this->actingAs($this->a['cmo']);
    $cmoView = QueryView::forStaff($this->case, $this->a['cmo'])['queries'][0];
    expect($cmoView['read_only'])->toBeTrue()
        ->and($cmoView['can_reply'])->toBeFalse()
        ->and($cmoView['messages'][0]['forward'])->toBeNull();

    $this->post(route('cases.queries.reply', [$this->case, $query->id]), ['body' => 'CMO words'])->assertForbidden();
});

it('offers only the active routes for the actor', function (string $actor, array $expected) {
    $this->actingAs($this->a[$actor]);
    $routes = QueryView::forStaff($this->case, $this->a[$actor])['raise']['routes'] ?? [];
    $ids = array_column($routes, 'id');

    $want = collect($expected)->map(fn (array $r) => qlRoute(...$r)->id)->sort()->values()->all();
    expect(collect($ids)->sort()->values()->all())->toBe($want);
})->with([
    'Legal' => ['legal', [['legal_officer', 'technocrat']]],
    'Technocrat' => ['technocrat', [['technocrat', 'vendor_applicant'], ['technocrat', 'cmo']]],
    'PS' => ['ps', [['ps_office', 'technocrat'], ['ps_office', 'legal_officer']]],
    'CMO' => ['cmo', [['cmo', 'legal_officer'], ['cmo', 'technocrat']]],
    'CAB' => ['cab', []],
    'Registry' => ['registry', []],
]);

it('refuses a route that is not the actor\'s, or switched off', function () {
    $this->actingAs($this->a['legal'])->post(route('cases.queries.store', $this->case), ['route' => qlRoute('ps_office', 'technocrat')->id, 'body' => 'Hello there'])
        ->assertSessionHasErrors('route');

    DB::table('query_routes')->where('from_role', 'legal_officer')->update(['active' => false]);
    $this->actingAs($this->a['legal'])->post(route('cases.queries.store', $this->case), ['route' => qlRoute('legal_officer', 'technocrat')->id, 'body' => 'Hello there'])
        ->assertForbidden();

    expect(CaseQuery::withoutGlobalScopes()->where('case_id', $this->case->id)->count())->toBe(0);
});

it('lets either side or the CMO close a query, and then nobody adds to it', function (string $closer) {
    $query = qlRaise($this->a['technocrat'], $this->case, qlRoute('technocrat', 'cmo'));

    $this->actingAs($this->a[$closer])->post(route('cases.queries.close', [$this->case, $query->id]))->assertSessionHasNoErrors();

    expect(qlFresh($query)->status)->toBe(QueryStatus::CLOSED)
        ->and((int) qlFresh($query)->resolved_by)->toBe($this->a[$closer]->id);

    $this->actingAs($this->a['technocrat'])->post(route('cases.queries.reply', [$this->case, $query->id]), ['body' => 'Late words'])->assertForbidden();
})->with(['technocrat', 'cmo']);

it('never changes the case status and records no message text in the audit log', function () {
    $query = qlRaise($this->a['legal'], $this->case, qlRoute('legal_officer', 'technocrat'), 'Very private legal wording');
    $this->actingAs($this->a['cmo'])->post(route('cases.queries.forward', [$this->case, $query->id, qlHeld($query)->id]));
    $this->actingAs($this->a['technocrat'])->post(route('cases.queries.reply', [$this->case, $query->id]), ['body' => 'Private technical wording']);
    $this->actingAs($this->a['cmo'])->post(route('cases.queries.close', [$this->case, $query->id]));

    $rows = DB::table('audit_logs')->where('case_id', $this->case->id)->where('action', 'like', 'query.%')->orderBy('id')->get();
    expect($rows->pluck('action')->all())->toBe([
        AuditAction::QueryRaised->value, AuditAction::QueryForwarded->value, AuditAction::QueryMessagePosted->value, AuditAction::QueryClosed->value,
    ]);
    foreach ($rows as $row) {
        expect((string) $row->metadata)->not->toContain('wording');
    }
    expect(ProcurementCase::withoutGlobalScopes()->find($this->case->id)->status)->toBe(S::WITH_LEGAL);
});

it('returns 404 for a query on another case, and for staff who cannot see the case', function () {
    $query = qlRaise($this->a['technocrat'], $this->case, qlRoute('technocrat', 'cmo'));
    $other = qlCase($this->a, S::IN_REVIEW);

    $this->actingAs($this->a['cmo'])->post(route('cases.queries.close', [$other, $query->id]))->assertNotFound();
    $this->actingAs($this->a['other_technocrat'])->post(route('cases.queries.reply', [$this->case, $query->id]), ['body' => 'Hi there'])->assertNotFound();
    $this->actingAs($this->a['system_admin'])->post(route('cases.queries.store', $this->case), ['route' => 1, 'body' => 'Hi there'])->assertNotFound();
});

// D3: the vendor -------------------------------------------------------------------

function qlVendorQueries(User $vendor, ProcurementCase $case): array
{
    return test()->actingAs($vendor)->get(route('requests.show', $case))->assertOk()->viewData('page')['props']['queries'];
}

it('shows the vendor a direct Technocrat query and takes a text reply', function () {
    $query = qlRaise($this->a['technocrat'], $this->case, qlRoute('technocrat', 'vendor_applicant'), 'Please send the batch certificate.');

    $queries = qlVendorQueries($this->a['vendor'], $this->case);
    expect($queries)->toHaveCount(1)
        ->and($queries[0]['title'])->toBe('Query From the Ministry of Health')
        ->and($queries[0]['messages'][0]['from_label'])->toBe('Ministry of Health')
        ->and($queries[0]['messages'][0]['body'])->toBe('Please send the batch certificate.')
        ->and($queries[0]['can_reply'])->toBeTrue();

    $this->actingAs($this->a['vendor'])->post(route('requests.queries.reply', [$this->case, $query->id]), ['body' => 'Attached in Documents today.'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $reply = QueryMessage::where('query_id', $query->id)->latest('created_at')->latest('id')->firstOrFail();
    expect($reply->author_role)->toBe('vendor_applicant')
        ->and($reply->recipient_role)->toBe('technocrat')
        ->and($reply->visibility)->toBe('external')
        ->and(qlFresh($query)->status)->toBe(QueryStatus::ANSWERED)
        ->and(qlVendorQueries($this->a['vendor'], $this->case)[0]['messages'][1]['from_label'])->toBe('You');
});

it('never shows the vendor an internal or held query', function () {
    qlRaise($this->a['legal'], $this->case, qlRoute('legal_officer', 'technocrat'), 'Internal legal text');
    qlRaise($this->a['technocrat'], $this->case, qlRoute('technocrat', 'cmo'), 'Internal technocrat text');

    expect(qlVendorQueries($this->a['vendor'], $this->case))->toBe([]);

    $page = $this->actingAs($this->a['vendor'])->get(route('requests.show', $this->case))->getContent();
    expect($page)->not->toContain('Internal legal text')->not->toContain('Internal technocrat text');
});

it('returns 404 to another organisation, and to a vendor on an internal query', function () {
    $direct = qlRaise($this->a['technocrat'], $this->case, qlRoute('technocrat', 'vendor_applicant'));
    $internal = qlRaise($this->a['technocrat'], $this->case, qlRoute('technocrat', 'cmo'));

    $this->actingAs($this->a['other_vendor'])->post(route('requests.queries.reply', [$this->case, $direct->id]), ['body' => 'Not mine'])->assertNotFound();
    $this->actingAs($this->a['vendor'])->post(route('requests.queries.reply', [$this->case, $internal->id]), ['body' => 'Hello there'])->assertNotFound();
    // The staff routes are not for vendors.
    $this->actingAs($this->a['vendor'])->post(route('cases.queries.reply', [$this->case, $direct->id]), ['body' => 'Hello there'])->assertForbidden();
});

it('refuses a vendor reply once the case is closed', function () {
    $query = qlRaise($this->a['technocrat'], $this->case, qlRoute('technocrat', 'vendor_applicant'));
    DB::table('cases')->where('id', $this->case->id)->update(['status' => S::CLOSED->value]);

    expect(qlVendorQueries($this->a['vendor'], $this->case)[0]['can_reply'])->toBeFalse();
    $this->actingAs($this->a['vendor'])->post(route('requests.queries.reply', [$this->case, $query->id]), ['body' => 'Too late'])->assertForbidden();
});
