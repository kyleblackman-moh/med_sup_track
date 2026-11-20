<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Models\User;
use App\Notifications\Queries\QueryRaised;
use App\Queries\QueryAccess;
use App\Support\WorkQueue;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// WORKFLOW-2 (Step 1): who sees a query, by party x route x lifecycle state.
// One table of rules (R1-R7), driven through the real routes. Each cell is
// checked twice: the server rule (QueryAccess) and what the party's own page
// carries (the staff case page or the vendor request page). DatabaseTransactions only.
//
//  R1 the raiser always sees their thread.
//  R2 a held message: only its author and the CMO.
//  R3 after forward (direct routes: at once) the recipient party: officers
//     assigned to the case on the recipient's track, plus any officer the CMO
//     named at forward time.
//  R4 the CMO sees every query; on a direct Technocrat-vendor thread read-only.
//  R5 a vendor: external threads of its own organisation only, never held or
//     internal messages; another vendor organisation gets 404.
//  R6 anyone else: 404.
//  R7 answered and closed threads stay visible, read-only, to the same parties.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    $this->seed(QueryRouteSeeder::class);
    Notification::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
});

/** @return array<string, User> */
function qvActors(): array
{
    $org = vendorOrg();
    $tag = Str::upper(Str::random(4));

    return [
        'cmo' => caseUser(Role::Cmo, ['name' => "QV CMO {$tag}"]),
        'tech' => caseUser(Role::Technocrat, ['name' => "QV Tech {$tag}"]),
        'tech_unassigned' => caseUser(Role::Technocrat, ['name' => "QV Tech Free {$tag}"]),
        'tech_named' => caseUser(Role::Technocrat, ['name' => "QV Tech Named {$tag}"]),
        'legal' => caseUser(Role::LegalOfficer, ['name' => "QV Legal {$tag}"]),
        'legal_unassigned' => caseUser(Role::LegalOfficer, ['name' => "QV Legal Free {$tag}"]),
        'legal_named' => caseUser(Role::LegalOfficer, ['name' => "QV Legal Named {$tag}"]),
        'ps' => caseUser(Role::PsOffice, ['name' => "QV PS {$tag}"]),
        'cab' => caseUser(Role::CabOfficer, ['name' => "QV CAB {$tag}"]),
        'registry' => caseUser(Role::CmoRegistry, ['name' => "QV Registry {$tag}"]),
        'vendor' => vendorUser($org, ['name' => "QV Vendor {$tag}"]),
        'other_vendor' => vendorUser(vendorOrg(), ['name' => "QV Other Vendor {$tag}"]),
    ];
}

/**
 * A case at $status with the assignments a real case has there: Technocrat
 * and CAB on their tracks (completed once past review), Legal from WITH_LEGAL
 * (completed once past Legal), the registry's intake row completed.
 */
function qvCase(array $a, S $status): ProcurementCase
{
    $case = makeCase([
        'org_id' => $a['vendor']->org_id,
        'created_by' => $a['vendor']->id,
        'status' => $status,
        'reference_no' => 'NR-QV-'.Str::upper(Str::random(8)),
        'submitted_at' => now()->subDays(5),
    ]);

    $pastReview = $status !== S::IN_REVIEW;
    $rows = [
        [Role::CmoRegistry, 'registry', 'intake', true],
        [Role::Technocrat, 'tech', 'technical', $pastReview],
        [Role::CabOfficer, 'cab', 'funding', $pastReview],
    ];
    if ($status !== S::IN_REVIEW) {
        $rows[] = [Role::LegalOfficer, 'legal', 'legal', $status !== S::WITH_LEGAL];
    }
    foreach ($rows as [$role, $key, $purpose, $done]) {
        DB::table('case_assignments')->where('id', assign($case, $role, $a[$key], $done)->id)->update(['purpose' => $purpose]);
    }

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

/**
 * The seven seeded routes: [from, to, case status, raiser key, assigned
 * recipient key, unassigned recipient key|null, named officer key|null].
 *
 * @return array<string, array{0: string, 1: string, 2: S, 3: string, 4: string, 5: string|null, 6: string|null}>
 */
function qvRoutes(): array
{
    return [
        'Technocrat to vendor (direct)' => ['technocrat', 'vendor_applicant', S::IN_REVIEW, 'tech', 'vendor', null, null],
        'Legal to Technocrats via CMO' => ['legal_officer', 'technocrat', S::WITH_LEGAL, 'legal', 'tech', 'tech_unassigned', 'tech_named'],
        'PS to Technocrats via CMO' => ['ps_office', 'technocrat', S::WITH_PS, 'ps', 'tech', 'tech_unassigned', 'tech_named'],
        'PS to Legal via CMO' => ['ps_office', 'legal_officer', S::WITH_PS, 'ps', 'legal', 'legal_unassigned', 'legal_named'],
        'Technocrat to CMO (direct)' => ['technocrat', 'cmo', S::IN_REVIEW, 'tech', 'cmo', null, null],
        'CMO to Legal (direct)' => ['cmo', 'legal_officer', S::RETURNED_TO_CMO, 'cmo', 'legal', 'legal_unassigned', null],
        'CMO to Technocrats (direct)' => ['cmo', 'technocrat', S::RETURNED_TO_CMO, 'cmo', 'tech', 'tech_unassigned', null],
    ];
}

/** Every route x state cell (a direct route has no held state). */
function qvCells(): array
{
    $cells = [];
    foreach (qvRoutes() as $name => $route) {
        $via = QueryRouteSeeder::routes()[array_search($name, array_keys(qvRoutes()), true)]['via_cmo'];
        foreach (['held', 'delivered', 'answered', 'closed'] as $state) {
            if ($state === 'held' && ! $via) {
                continue;
            }
            $cells["{$name} / {$state}"] = [$name, $state];
        }
    }

    return $cells;
}

function qvHeld(CaseQuery $query): ?QueryMessage
{
    return QueryMessage::query()->where('query_id', $query->id)->where('requires_forward', true)->whereNull('forwarded_at')->first();
}

/**
 * Drives one query on $case to $state through the real routes. The CMO names
 * $named (if any) on every forward.
 */
function qvDrive(array $a, ProcurementCase $case, QueryRoute $route, string $raiser, string $recipient, ?string $named, string $state): CaseQuery
{
    test()->actingAs($a[$raiser])->post(route('cases.queries.store', $case), ['route' => $route->id, 'body' => 'Internal words QV-RAISE'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $query = CaseQuery::withoutGlobalScopes()->where('case_id', $case->id)->latest('created_at')->latest('id')->firstOrFail();

    $forward = function (bool $outbound) use ($a, $case, $query, $named) {
        $held = qvHeld($query);
        if ($held !== null) {
            test()->actingAs($a['cmo'])->post(route('cases.queries.forward', [$case, $query->id, $held->id]), $named && $outbound ? ['officer_id' => $a[$named]->id] : [])
                ->assertSessionHasNoErrors()->assertRedirect();
        }
    };

    if ($state === 'held') {
        return $query;
    }
    $forward(true);
    if ($state === 'delivered') {
        return $query;
    }

    $replier = $a[$recipient];
    $replyRoute = $replier->isVendor() ? 'requests.queries.reply' : 'cases.queries.reply';
    test()->actingAs($replier)->post(route($replyRoute, [$case, $query->id]), ['body' => 'Answer words QV-REPLY'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $forward(false);
    if ($state === 'answered') {
        return $query;
    }

    test()->actingAs($a['cmo'])->post(route('cases.queries.close', [$case, $query->id]))->assertSessionHasNoErrors()->assertRedirect();

    return $query;
}

/** Does the party's own page carry the query? [listed, http status of the page]. */
function qvPage(User $user, ProcurementCase $case, CaseQuery $query): array
{
    $response = test()->actingAs($user)->get($user->isVendor() ? route('requests.show', $case->id) : route('cases.show', $case->id));
    if ($response->status() !== 200) {
        return [false, $response->status()];
    }
    $props = pageProps($response);
    $list = $user->isVendor() ? $props['queries'] : $props['queries']['queries'];

    return [in_array($query->id, array_column($list, 'id'), true), 200];
}

/** The query rows of $user's work queue for $case. */
function qvQueueRows(User $user, ProcurementCase $case): array
{
    test()->actingAs($user);
    $found = [];
    $queue = WorkQueue::for($user) ?? [];
    $walk = function ($node) use (&$walk, &$found, $case) {
        if (! is_array($node)) {
            return;
        }
        if (isset($node['step'], $node['case_id']) && array_key_exists($node['step'], WorkQueue::QUERY_STEPS) && $node['case_id'] === $case->id) {
            $found[] = $node['step'];
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk($queue);

    return $found;
}

it('shows each query to exactly its parties in every state', function (string $name, string $state) {
    [$from, $to, $status, $raiser, $recipient, $unassigned, $named] = qvRoutes()[$name];
    $a = qvActors();
    $case = qvCase($a, $status);
    $route = QueryRoute::query()->where('from_role', $from)->where('to_role', $to)->firstOrFail();
    $query = qvDrive($a, $case, $route, $raiser, $recipient, $named, $state);
    $query = CaseQuery::withoutGlobalScopes()->with('messages')->findOrFail($query->id);
    $case = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

    $delivered = $state !== 'held';
    $expected = [];
    foreach (array_keys($a) as $party) {
        $expected[$party] = false;
    }
    $expected['cmo'] = true;                                  // R4
    $expected[$raiser] = true;                                // R1
    $expected[$recipient] = $expected[$recipient] || $delivered; // R2, R3
    if ($named !== null) {
        $expected[$named] = $delivered;                       // R3: named at forward
    }

    $failures = [];
    foreach ($a as $party => $user) {
        test()->actingAs($user);
        $rule = QueryAccess::visibleMessages($user, $case, $query)->isNotEmpty();
        if ($rule !== $expected[$party]) {
            $failures[] = "{$party}: rule says ".($rule ? 'sees' : 'hidden').', expected '.($expected[$party] ? 'sees' : 'hidden');
        }

        // A party named on forward but not assigned has no case page (R3: thread and header only).
        if ($party === $named) {
            continue;
        }
        [$listed, $code] = qvPage($user, $case, $query);
        if ($listed !== $expected[$party]) {
            $failures[] = "{$party}: page ".($listed ? 'lists it' : "does not list it (HTTP {$code})").', expected '.($expected[$party] ? 'listed' : 'not listed');
        }
    }

    // R5: another vendor organisation never reaches the case at all.
    test()->actingAs($a['other_vendor'])->get(route('requests.show', $case->id))->assertNotFound();

    // R5: the vendor never sees a held or internal message, even on its own thread.
    test()->actingAs($a['vendor']);
    foreach ($query->messages as $m) {
        if (QueryAccess::canSeeMessage($a['vendor'], $case, $query, $m) && (! $m->isExternal() || $m->isHeld())) {
            $failures[] = "vendor: sees held or internal message {$m->id}";
        }
    }

    // R4, R7: read-only where the rules say so.
    if ($state === 'closed') {
        foreach ($a as $party => $user) {
            if (QueryAccess::canReply($user, $case, $query)) {
                $failures[] = "{$party}: may reply to a closed query";
            }
        }
    }
    if ($name === 'Technocrat to vendor (direct)' && QueryAccess::canReply($a['cmo'], $case, $query)) {
        $failures[] = 'cmo: may reply to a direct Technocrat-vendor thread';
    }

    // Queues never reveal the thread to a non-participant.
    foreach ($a as $party => $user) {
        if (! $expected[$party] && ! $user->isVendor() && qvQueueRows($user, $case) !== []) {
            $failures[] = "{$party}: work queue shows the query";
        }
    }

    expect($failures)->toBe([], "{$name} / {$state}");
})->with(qvCells());

it('keeps the vendor request page free of internal query text on every route', function () {
    $a = qvActors();
    foreach (qvRoutes() as $name => [$from, $to, $status, $raiser, $recipient, , $named]) {
        $case = qvCase($a, $status);
        $route = QueryRoute::query()->where('from_role', $from)->where('to_role', $to)->firstOrFail();
        qvDrive($a, $case, $route, $raiser, $recipient, $named, 'answered');

        $json = json_encode(pageProps(test()->actingAs($a['vendor'])->get(route('requests.show', $case->id))->assertOk()));
        $toVendor = $to === 'vendor_applicant';
        expect(str_contains($json, 'QV-RAISE'))->toBe($toVendor, $name)
            ->and(str_contains($json, 'QV Tech'))->toBeFalse($name)
            ->and(str_contains($json, 'QV CMO'))->toBeFalse($name);
    }
});

// Step 2: the named officer and the empty desk ------------------------------------------------

it('lets an officer named on forward answer the query without opening the rest of the case', function () {
    $a = qvActors();
    $case = qvCase($a, S::WITH_LEGAL);
    $route = QueryRoute::query()->where('from_role', 'legal_officer')->where('to_role', 'technocrat')->firstOrFail();
    $query = qvDrive($a, $case, $route, 'legal', 'tech', 'tech_named', 'delivered');

    $message = QueryMessage::query()->where('query_id', $query->id)->firstOrFail();
    expect((int) $message->forwarded_to_user_id)->toBe($a['tech_named']->id);

    $audit = DB::table('audit_logs')->where('subject_type', 'query')->where('subject_id', $query->id)->where('action', AuditAction::QueryForwarded->value)->latest('id')->first();
    expect(json_decode($audit->metadata, true)['named_officer_id'])->toBe($a['tech_named']->id);

    // The thread: reply works; the case page and its documents stay closed (R3).
    $this->actingAs($a['tech_named'])->post(route('cases.queries.reply', [$case->id, $query->id]), ['body' => 'Named answer'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($a['tech_named'])->get(route('cases.show', $case->id))->assertNotFound();
    // Not the CMO's desk and not on the case: no close.
    $this->actingAs($a['tech_named'])->post(route('cases.queries.close', [$case->id, $query->id]))->assertForbidden();

    // Another unassigned Technocrat is still nowhere (R6).
    $this->actingAs($a['tech_unassigned'])->post(route('cases.queries.reply', [$case->id, $query->id]), ['body' => 'Not mine'])->assertNotFound();
    $this->actingAs($a['tech_unassigned'])->post(route('cases.queries.store', $case->id), ['route' => $route->id, 'body' => 'x x'])->assertNotFound();
});

it('emails the named officer when the CMO forwards to them', function () {
    $a = qvActors();
    $case = qvCase($a, S::WITH_LEGAL);
    $route = QueryRoute::query()->where('from_role', 'legal_officer')->where('to_role', 'technocrat')->firstOrFail();
    qvDrive($a, $case, $route, 'legal', 'tech', 'tech_named', 'delivered');

    Notification::assertSentTo($a['tech_named'], QueryRaised::class);
    Notification::assertSentTo($a['tech'], QueryRaised::class);
    Notification::assertNotSentTo($a['tech_unassigned'], QueryRaised::class);
});

it('refuses a forward to a desk with nobody on the case unless an officer is named', function () {
    $a = qvActors();
    // IN_REVIEW: no Legal officer assigned yet.
    $case = qvCase($a, S::WITH_PS);
    DB::table('case_assignments')->where('case_id', $case->id)->where('role', 'legal_officer')->update(['user_id' => $a['legal_unassigned']->id, 'cancelled_at' => now()]);
    DB::table('case_assignments')->where('case_id', $case->id)->where('role', 'legal_officer')->update(['user_id' => null]);
    $route = QueryRoute::query()->where('from_role', 'ps_office')->where('to_role', 'legal_officer')->firstOrFail();
    $query = qvDrive($a, $case, $route, 'ps', 'legal', null, 'held');
    $held = qvHeld($query);

    $this->actingAs($a['cmo'])->post(route('cases.queries.forward', [$case->id, $query->id, $held->id]))
        ->assertSessionHasErrors('officer_id');
    expect(qvHeld($query))->not->toBeNull();

    // Someone of the wrong desk, or a vendor, is refused.
    $this->actingAs($a['cmo'])->post(route('cases.queries.forward', [$case->id, $query->id, $held->id]), ['officer_id' => $a['tech']->id])
        ->assertSessionHasErrors('officer_id');
    $this->actingAs($a['cmo'])->post(route('cases.queries.forward', [$case->id, $query->id, $held->id]), ['officer_id' => $a['vendor']->id])
        ->assertSessionHasErrors('officer_id');

    $this->actingAs($a['cmo'])->post(route('cases.queries.forward', [$case->id, $query->id, $held->id]), ['officer_id' => $a['legal_named']->id])
        ->assertSessionHasNoErrors();
    test()->actingAs($a['legal_named']);
    expect(QueryAccess::visibleQueries($a['legal_named'], $case)->pluck('id')->all())->toBe([$query->id]);
});

it('does not name an officer on a message going back to the raiser', function () {
    $a = qvActors();
    $case = qvCase($a, S::WITH_LEGAL);
    $route = QueryRoute::query()->where('from_role', 'legal_officer')->where('to_role', 'technocrat')->firstOrFail();
    $query = qvDrive($a, $case, $route, 'legal', 'tech', null, 'delivered');
    $this->actingAs($a['tech'])->post(route('cases.queries.reply', [$case->id, $query->id]), ['body' => 'Answer back'])->assertSessionHasNoErrors();
    $held = qvHeld($query);

    $this->actingAs($a['cmo'])->post(route('cases.queries.forward', [$case->id, $query->id, $held->id]), ['officer_id' => $a['legal_named']->id])
        ->assertSessionHasErrors('officer_id');
    $this->actingAs($a['cmo'])->post(route('cases.queries.forward', [$case->id, $query->id, $held->id]))->assertSessionHasNoErrors();
});

it('does not offer a direct route to a desk with nobody on the case', function () {
    $a = qvActors();
    // IN_REVIEW: no Legal officer on the case, so "CMO to Legal" (direct) would reach nobody.
    $case = qvCase($a, S::IN_REVIEW);
    $direct = QueryRoute::query()->where('from_role', 'cmo')->where('to_role', 'legal_officer')->firstOrFail();

    test()->actingAs($a['cmo']);
    expect(QueryAccess::raisableRoutes($a['cmo'], $case)->pluck('id')->all())->not->toContain($direct->id);

    $this->actingAs($a['cmo'])->post(route('cases.queries.store', $case->id), ['route' => $direct->id, 'body' => 'To nobody'])
        ->assertSessionHasErrors('route');
});
