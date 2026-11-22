<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// IT-ADMIN Part 2: the audit log viewer. audit.view only (CMO, Legal, System
// Admin; not the Auditor); everyone else 404. Read-only. Rows are written to
// the shared dev database inside the test transaction, so each test narrows
// the list to rows it made (by actor, case or action).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->auditStart = (int) DB::table('audit_logs')->max('id');
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Insert an audit row directly (as Audit::record does), at a given UTC time. */
function auditRow(array $values): int
{
    return (int) DB::table('audit_logs')->insertGetId([
        'occurred_at' => ($values['occurred_at'] ?? CarbonImmutable::now('UTC'))->format('Y-m-d H:i:s.v'),
        'actor_type' => $values['actor_type'] ?? 'user',
        'actor_user_id' => $values['actor_user_id'] ?? null,
        'action' => $values['action'] ?? 'user.created',
        'subject_type' => $values['subject_type'] ?? null,
        'subject_id' => $values['subject_id'] ?? null,
        'case_id' => $values['case_id'] ?? null,
        'ip_address' => '10.0.0.1',
        'metadata' => isset($values['metadata']) ? json_encode($values['metadata']) : null,
    ]);
}

/** The viewer's rows for $viewer at $route with $query. */
function auditViewerRows(User $viewer, array $query = [], string $route = 'admin.audit.index'): array
{
    return pageProps(test()->actingAs($viewer)->get(route($route, $query))->assertOk())['rows'];
}

function newViewedRows(): Collection
{
    return DB::table('audit_logs')->where('id', '>', test()->auditStart)->where('action', 'log.viewed')->get();
}

it('opens for the System Admin in the console, and for the CMO and Legal on /audit', function () {
    $this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.audit.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('audit/index', false)->where('route_base', 'admin.audit'));
    $this->actingAs(caseUser(Role::SystemAdmin))->get(route('audit.index'))->assertOk();
    $this->actingAs(caseUser(Role::Cmo))->get(route('audit.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('audit/index', false)->where('route_base', 'audit'));
    $this->actingAs(caseUser(Role::LegalOfficer))->get(route('audit.index'))->assertOk();
});

it('answers 404 to everyone else, the Auditor included', function (Role $role) {
    $user = $role === Role::VendorApplicant ? vendorUser(vendorOrg()) : caseUser($role);
    $id = auditRow(['action' => 'user.created']);

    $this->actingAs($user)->get(route('audit.index'))->assertNotFound();
    $this->actingAs($user)->get(route('audit.show', $id))->assertNotFound();
    $this->actingAs($user)->get(route('admin.audit.index'))->assertNotFound();
    $this->actingAs($user)->get(route('admin.audit.show', $id))->assertNotFound();
})->with([Role::Auditor, Role::VendorApplicant, Role::Technocrat, Role::CabOfficer, Role::PsOffice, Role::CmoRegistry]);

it('keeps the console door closed to the CMO and Legal (no admin.access)', function (Role $role) {
    $this->actingAs(caseUser($role))->get(route('admin.audit.index'))->assertNotFound();
})->with([Role::Cmo, Role::LegalOfficer]);

it('lists newest first with time, actor, action, category, outcome, subject, case and ip', function () {
    $actor = caseUser(Role::Cmo, ['name' => 'Audit Actor']);
    $case = makeCase(['org_id' => vendorOrg()->id, 'status' => CaseStatus::SUBMITTED, 'submitted_at' => now(), 'reference_no' => 'NR-AUD-'.Str::upper(Str::random(6))]);
    $older = auditRow(['actor_user_id' => $actor->id, 'action' => 'case.intake', 'occurred_at' => CarbonImmutable::parse('2026-11-20 14:00:00', 'UTC'), 'case_id' => $case->id]);
    $newer = auditRow(['actor_user_id' => $actor->id, 'action' => 'auth.login.failed', 'occurred_at' => CarbonImmutable::parse('2026-11-21 14:00:00', 'UTC'), 'subject_type' => 'user', 'subject_id' => '7']);

    $rows = auditViewerRows(caseUser(Role::SystemAdmin), ['actor' => (string) $actor->id]);

    expect(collect($rows)->pluck('id')->all())->toBe([$newer, $older])
        ->and($rows[0])->toMatchArray([
            'occurred_at' => '21 Nov 2026, 10:00:00 am', // Port of Spain, UTC-4
            'actor' => 'Audit Actor ('.$actor->email.')',
            'action' => 'auth.login.failed',
            'category' => 'Sign-in',
            'outcome_label' => 'Denied',
            'outcome_tone' => 'error',
            'subject' => 'user #7',
            'ip_address' => '10.0.0.1',
        ])
        ->and($rows[1]['case_reference'])->toBe($case->reference_no)
        ->and($rows[1]['outcome_label'])->toBe('Allowed')
        ->and($rows[0])->not->toHaveKey('metadata');
});

it('filters by actor id, actor email, system and anonymous', function () {
    $actor = caseUser(Role::Cmo);
    $mine = auditRow(['actor_user_id' => $actor->id]);
    $admin = caseUser(Role::SystemAdmin);

    expect(collect(auditViewerRows($admin, ['actor' => (string) $actor->id]))->pluck('id')->all())->toBe([$mine])
        ->and(collect(auditViewerRows($admin, ['actor' => Str::upper($actor->email)]))->pluck('id')->all())->toBe([$mine])
        ->and(auditViewerRows($admin, ['actor' => 'nobody-'.Str::random(6).'@example.test']))->toBe([]);

    $system = auditRow(['actor_type' => 'system', 'action' => 'organisation.created', 'occurred_at' => CarbonImmutable::now('UTC')->addMinute()]);
    expect(collect(auditViewerRows($admin, ['actor' => 'system', 'action' => 'organisation.created']))->pluck('id')->first())->toBe($system);
});

it('filters by category, action, outcome and date range', function () {
    $actor = caseUser(Role::Cmo);
    $a = fn (string $action, string $at) => auditRow(['actor_user_id' => $actor->id, 'action' => $action, 'occurred_at' => CarbonImmutable::parse($at, 'UTC')]);
    $failed = $a('auth.login.failed', '2026-11-10 12:00:00');
    $login = $a('auth.login.success', '2026-11-11 12:00:00');
    $denied = $a('access.denied', '2026-11-12 12:00:00');
    // 03:00 UTC on the 13th is 23:00 on the 12th in Port of Spain.
    $late = $a('user.disabled', '2026-11-13 03:00:00');

    $ids = fn (array $q) => collect(auditViewerRows(caseUser(Role::SystemAdmin), ['actor' => (string) $actor->id, ...$q]))->pluck('id')->sort()->values()->all();

    expect($ids(['category' => 'auth']))->toBe([$failed, $login])
        ->and($ids(['action' => 'access.denied']))->toBe([$denied])
        ->and($ids(['outcome' => 'denied']))->toBe([$failed, $denied])
        ->and($ids(['outcome' => 'allowed']))->toBe([$login, $late])
        ->and($ids(['from' => '2026-11-11', 'to' => '2026-11-12']))->toBe([$login, $denied, $late])
        ->and($ids(['to' => '2026-11-10']))->toBe([$failed]);
});

it('filters by case reference, whatever the case status', function () {
    $draft = makeCase(['org_id' => vendorOrg()->id, 'status' => CaseStatus::SUBMITTED, 'submitted_at' => now(), 'reference_no' => 'NR-AUD-'.Str::upper(Str::random(6))]);
    $row = auditRow(['action' => 'case.transitioned', 'case_id' => $draft->id]);

    expect(collect(auditViewerRows(caseUser(Role::Cmo), ['case' => $draft->reference_no], 'audit.index'))->pluck('id')->all())->toBe([$row])
        ->and(auditViewerRows(caseUser(Role::Cmo), ['case' => 'NR-NOPE-'.Str::random(6)], 'audit.index'))->toBe([]);
});

it('reports a bad filter instead of ignoring it', function (array $query, string $key) {
    $this->actingAs(caseUser(Role::SystemAdmin))->get(route('admin.audit.index', $query))
        ->assertRedirect(route('admin.audit.index'))
        ->assertSessionHasErrors($key);
})->with([
    'unknown action' => [['action' => 'nope.nope'], 'action'],
    'unknown category' => [['category' => 'nope'], 'category'],
    'bad date' => [['from' => '22/11/2026'], 'from'],
    'end before start' => [['from' => '2026-11-12', 'to' => '2026-11-10'], 'to'],
    'unknown outcome' => [['outcome' => 'maybe'], 'outcome'],
]);

it('pages with a cursor, newest first, without repeating or skipping rows', function () {
    config(['admin.audit.per_page' => 3]);
    $actor = caseUser(Role::Cmo);
    $base = CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC');
    $ids = collect(range(1, 7))->map(fn ($i) => auditRow(['actor_user_id' => $actor->id, 'occurred_at' => $base->addMinutes($i)]))->reverse()->values();
    $admin = caseUser(Role::SystemAdmin);

    $seen = [];
    $url = route('admin.audit.index', ['actor' => (string) $actor->id]);
    while ($url !== null) {
        $props = pageProps($this->actingAs($admin)->get($url)->assertOk());
        $seen = [...$seen, ...collect($props['rows'])->pluck('id')->all()];
        $url = $props['next_page_url'];
    }

    expect($seen)->toBe($ids->all());
});

it('shows one row\'s detail with sensitive keys redacted', function () {
    $id = auditRow([
        'action' => 'user.created',
        'metadata' => [
            'user_id' => 5,
            'password' => 'hunter2',
            'reset_token' => 'abc',
            'nested' => ['two_factor_secret' => 'xyz', 'recovery_codes' => ['a', 'b'], 'kept' => 'visible'],
            'link' => 'https://example.test/activate/1?token=abc&signature=def',
            'signed_url' => 'anything',
            'mfa_code' => '123456',
            'reason_id' => '01JABC',
            'reason_length' => 12,
        ],
    ]);

    $detail = $this->actingAs(caseUser(Role::SystemAdmin))->getJson(route('admin.audit.show', $id))->assertOk()->json();

    expect($detail['metadata'])->toBe([
        'user_id' => 5,
        'password' => '[redacted]',
        'reset_token' => '[redacted]',
        'nested' => ['two_factor_secret' => '[redacted]', 'recovery_codes' => '[redacted]', 'kept' => 'visible'],
        'link' => '[redacted]',
        'signed_url' => '[redacted]',
        'mfa_code' => '[redacted]',
        'reason_id' => '01JABC',
        'reason_length' => 12,
    ])->and(json_encode($detail))->not->toContain('hunter2')->not->toContain('signature=def');

    $this->getJson(route('admin.audit.show', 999999999))->assertNotFound();
});

it('has no endpoint that writes to the audit log: GET only on every audit route', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => str_contains($r->uri(), 'audit') || str_contains((string) $r->getName(), 'audit'));

    expect($routes->count())->toBe(4);
    foreach ($routes as $route) {
        expect(array_values(array_diff($route->methods(), ['GET', 'HEAD'])))->toBe([], $route->uri());
    }

    $id = auditRow(['action' => 'user.created']);
    foreach (['put', 'patch', 'delete', 'post'] as $method) {
        $status = $this->actingAs(caseUser(Role::SystemAdmin))->{$method}(route('admin.audit.show', $id))->status();
        expect($status)->toBe(405);
    }
    expect(DB::table('audit_logs')->where('id', $id)->value('action'))->toBe('user.created');
});

it('refuses to write audit rows through the model, before any SQL is sent', function () {
    $id = auditRow(['action' => 'user.created']);
    $row = AuditLog::findOrFail($id);

    expect(fn () => $row->forceFill(['action' => 'user.deleted'])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and(fn () => (new AuditLog)->forceFill(['action' => 'x'])->save())->toThrow(LogicException::class);

    expect(DB::table('audit_logs')->where('id', $id)->value('action'))->toBe('user.created');
});

it('logs log.viewed once per filter change, not per page', function () {
    config(['admin.audit.per_page' => 1]);
    $actor = caseUser(Role::Cmo);
    auditRow(['actor_user_id' => $actor->id]);
    auditRow(['actor_user_id' => $actor->id, 'occurred_at' => CarbonImmutable::now('UTC')->addSecond()]);
    $viewer = caseUser(Role::SystemAdmin);

    $first = pageProps($this->actingAs($viewer)->get(route('admin.audit.index', ['actor' => (string) $actor->id]))->assertOk());
    $this->get($first['next_page_url'])->assertOk(); // paging: not logged again
    $this->get(route('admin.audit.index', ['actor' => (string) $actor->id]))->assertOk(); // same filters: not logged again
    $this->get(route('admin.audit.index', ['actor' => $actor->email, 'outcome' => 'allowed']))->assertOk(); // new filters

    $rows = newViewedRows();
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->actor_user_id)->toBe($viewer->id)
        ->and(json_decode($rows[0]->metadata, true))->toBe(['via' => 'admin', 'filters' => ['actor_user_id' => $actor->id]])
        // The typed email is never stored: it is resolved to the user id.
        ->and($rows[1]->metadata)->not->toContain($actor->email)
        ->and(json_decode($rows[1]->metadata, true)['filters'])->toBe(['actor_user_id' => $actor->id, 'outcome' => 'allowed']);
});

it('caps log.viewed per user', function () {
    config(['admin.audit.viewed_log_max' => 3]);
    $viewer = caseUser(Role::Cmo);

    foreach (range(1, 6) as $i) {
        $this->actingAs($viewer)->get(route('audit.index', ['from' => "2026-11-0{$i}"]))->assertOk();
    }

    expect(newViewedRows())->toHaveCount(3);
});

it('audits a 403 as access.denied with the route name, and caps it', function () {
    config(['admin.audit.denied_log_per_minute' => 2]);
    $technocrat = caseUser(Role::Technocrat);

    foreach (range(1, 4) as $i) {
        $this->actingAs($technocrat)->get(route('analytics'))->assertForbidden();
    }

    $rows = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'access.denied')->get();
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->actor_user_id)->toBe($technocrat->id)
        ->and(json_decode($rows[0]->metadata, true))->toBe(['route' => 'analytics', 'method' => 'GET', 'status' => 403, 'reason' => 'forbidden'])
        ->and($rows[0]->request_id)->not->toBeNull();
});

it('carries the request id of the response on every audit row of the request', function () {
    $admin = caseUser(Role::SystemAdmin);
    confirmAdminPassword();
    $target = caseUser(Role::Technocrat);

    $response = $this->actingAs($admin)->post(route('admin.users.disable', $target), ['reason' => 'Request id.']);

    $row = latestAuditFor('user.disabled', $target->id);
    expect($row->request_id)->toBe($response->headers->get('X-Request-Id'));
});

it('writes system rows with no request id from the console', function () {
    Audit::record('organisation.created', null, null, [], Audit::ACTOR_SYSTEM);

    // Inside a test the app has a request bound, but no request id was assigned to it.
    $row = DB::table('audit_logs')->where('id', '>', $this->auditStart)->where('action', 'organisation.created')->sole();
    expect($row->request_id)->toBeNull();
});
