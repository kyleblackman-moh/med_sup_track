<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus as S;
use App\Http\Resources\Cases\StaffCaseDetail;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryMessage;
use App\Models\QueryRoute;
use App\Notifications\Queries\QueryRaised;
use App\Queries\QueryAccess;
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

        // A party named on forward but not assigned opens the page in query-only mode (R3: thread and header only).
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

    // The thread: the page opens in query-only mode (header and this query, nothing
    // else of the case), reply works, and the documents stay closed (R3).
    $props = pageProps($this->actingAs($a['tech_named'])->get(route('cases.show', $case->id))->assertOk());
    expect($props['query_only'])->toBeTrue()
        ->and(array_column($props['queries']['queries'], 'id'))->toBe([$query->id])
        ->and($props['queries']['raise'])->toBeNull()
        ->and($props['case']['reference_no'])->toBe($case->reference_no)
        ->and($props['case']['item_description'])->toBeNull()
        ->and($props['documents'])->toBe([])
        ->and($props['timeline'])->toBe([])
        ->and($props['assignments'])->toBe([])
        ->and($props['summary'])->toBeNull()
        ->and($props['next_step'])->toBeNull()
        ->and($props['gate'])->toBeNull()
        ->and(array_filter(array_diff_key($props['can'], ['requests' => 0, 'cases' => 0])))->toBe([]);
    // NOTIF-3: every page key other than the header, the queries and the flags is present but empty.
    foreach (StaffCaseDetail::queryOnly($case, $a['tech_named']) as $key => $value) {
        if (in_array($key, ['query_only', 'case', 'queries', 'can'], true)) {
            continue;
        }
        expect($props)->toHaveKey($key)
            ->and($props[$key])->toBeIn([null, []], "query_only sent a non-empty {$key}");
    }
    // Documents stay on the assignment rules: the case binding refuses before any document is looked up.
    $this->actingAs($a['tech_named'])->get(route('cases.documents.download', [$case->id, Str::lower((string) Str::ulid())]))->assertNotFound();

    $this->actingAs($a['tech_named'])->post(route('cases.queries.reply', [$case->id, $query->id]), ['body' => 'Named answer'])
        ->assertSessionHasNoErrors()->assertRedirect();

    // An assigned party still gets the full page.
    expect(pageProps($this->actingAs($a['tech'])->get(route('cases.show', $case->id))->assertOk())['query_only'])->toBeFalse();
    // An officer neither assigned nor named is 404 (R6).
    $this->actingAs($a['tech_unassigned'])->get(route('cases.show', $case->id))->assertNotFound();
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
