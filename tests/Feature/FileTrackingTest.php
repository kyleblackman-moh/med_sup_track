<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Models\CaseAssignment;
use App\Models\CaseRequisition;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\FileTracking;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-24: File Tracking (list and per-case stage timeline), server
// side. Plain elapsed time; Port of Spain display. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    CarbonImmutable::setTestNow();
});

function ftAt(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

/** @param  list<array{0: S, 1: S, 2: string}>  $steps  from, to, UTC time */
function ftCase(S $status, array $steps, array $attributes = []): ProcurementCase
{
    $actor = caseUser(Role::Cmo);
    $case = makeCase([
        'status' => $status,
        'reference_no' => 'NR-FT-'.Str::upper(Str::random(8)),
        'submitted_at' => ftAt($steps[0][2]),
        ...$attributes,
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Feeding tube'])->save();
    foreach ($steps as [$from, $to, $at]) {
        (new CaseTransition)->forceFill([
            'case_id' => $case->id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor->id, 'created_at' => ftAt($at),
        ])->save();
    }

    return $case;
}

function ftAssign(ProcurementCase $case, Role $role, User $user, string $purpose, string $from, ?string $to = null): void
{
    (new CaseAssignment)->forceFill([
        'case_id' => $case->id, 'role' => $role->value, 'user_id' => $user->id, 'purpose' => $purpose,
        'assigned_at' => ftAt($from), 'completed_at' => $to === null ? null : ftAt($to),
    ])->save();
}

/** Submitted 1 Nov, to Legal 6 Nov, returned 8 Nov, back with Legal 9 Nov (open). */
function ftLoop(): array
{
    $case = ftCase(S::WITH_LEGAL, [
        [S::DRAFT, S::SUBMITTED, '2026-11-01 12:00'],
        [S::SUBMITTED, S::CMO_INTAKE, '2026-11-02 12:00'],
        [S::CMO_INTAKE, S::IN_REVIEW, '2026-11-03 12:00'],
        [S::IN_REVIEW, S::READY_FOR_FILE, '2026-11-05 12:00'],
        [S::READY_FOR_FILE, S::FILE_PREPARED, '2026-11-05 18:00'],
        [S::FILE_PREPARED, S::WITH_LEGAL, '2026-11-06 12:00'],
        [S::WITH_LEGAL, S::RETURNED_TO_CMO, '2026-11-08 12:00'],
        [S::RETURNED_TO_CMO, S::WITH_LEGAL, '2026-11-09 00:00'],
    ], ['current_holder_role' => 'legal_officer']);
    $first = caseUser(Role::LegalOfficer, ['name' => 'Lena First']);
    $second = caseUser(Role::LegalOfficer, ['name' => 'Leo Second']);
    ftAssign($case, Role::LegalOfficer, $first, 'legal', '2026-11-06 12:00', '2026-11-09 00:00');
    ftAssign($case, Role::LegalOfficer, $second, 'legal', '2026-11-09 00:00');

    return [$case, $first, $second];
}

it('times every stage of a case that loops Legal, CMO, Legal, the open stage up to now', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();

    $timeline = $this->actingAs(caseUser(Role::Cmo))->getJson(route('tracking.show', $case))->assertOk()->json('timeline');

    expect(array_map(fn ($s) => [$s['label'], $s['duration_seconds'], $s['open']], $timeline))->toBe([
        ['Submitted', 86400, false],
        ['CMO intake', 86400, false],
        ['In review', 2 * 86400, false],
        ['Ready for file', 6 * 3600, false],
        ['File prepared', 18 * 3600, false],
        ['With Legal', 2 * 86400, false],
        ['Returned to CMO', 12 * 3600, false],
        ['With Legal (2nd time)', 86400 + 12 * 3600, true], // up to now
    ])
        ->and($timeline[5]['holders'])->toBe([['role' => 'legal_officer', 'label' => 'Legal Unit', 'name' => 'Lena First']])
        ->and($timeline[7]['holders'])->toBe([['role' => 'legal_officer', 'label' => 'Legal Unit', 'name' => 'Leo Second']])
        ->and($timeline[7]['duration'])->toBe('1 d 12 h')
        ->and($timeline[7]['left_at'])->toBeNull()
        // Port of Spain: 9 Nov 00:00 UTC is 8 Nov 8:00 pm.
        ->and($timeline[7]['entered_at'])->toBe('8 Nov 2026, 8:00 pm');
});

it('lists the case with its stage, holders, times and returns', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();

    $row = collect($this->actingAs(caseUser(Role::Cmo))->getJson(route('tracking.index'))->assertOk()->json('tracking'))->firstWhere('id', $case->id);

    expect($row)->toMatchArray([
        'reference' => $case->reference_no,
        'item' => 'Feeding tube',
        'stage' => 'With Legal',
        'holder_label' => 'Legal Unit (Leo Second)',
        'at_stage_seconds' => 86400 + 12 * 3600,
        'at_stage' => '1 d 12 h',
        'since_submission_seconds' => 9 * 86400,
        'since_submission' => '9 d 0 h',
        'times_returned' => 1,
    ]);
});

it('sorts by time at the current stage, longest first', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    $recent = ftCase(S::SUBMITTED, [[S::DRAFT, S::SUBMITTED, '2026-11-10 06:00']], ['current_holder_role' => 'cmo_registry']);
    $old = ftCase(S::SUBMITTED, [[S::DRAFT, S::SUBMITTED, '2026-11-02 06:00']], ['current_holder_role' => 'cmo_registry']);

    $ids = collect($this->actingAs(caseUser(Role::Cmo))->getJson(route('tracking.index'))->json('tracking'))->pluck('id');

    expect($ids->search($old->id))->toBeLessThan($ids->search($recent->id));
});

it('shows only the cases the user can see', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();
    $draft = makeCase(['status' => S::DRAFT]);
    $technocrat = caseUser(Role::Technocrat); // not assigned

    $cmoIds = collect($this->actingAs(caseUser(Role::Cmo))->getJson(route('tracking.index'))->json('tracking'))->pluck('id');
    $techIds = collect($this->actingAs($technocrat)->getJson(route('tracking.index'))->json('tracking'))->pluck('id');

    expect($cmoIds)->toContain($case->id)->not->toContain($draft->id)
        ->and($techIds)->not->toContain($case->id);
    $this->actingAs($technocrat)->getJson(route('tracking.show', $case))->assertNotFound();
    $this->actingAs(caseUser(Role::Cmo))->getJson(route('tracking.show', $draft))->assertNotFound();
    $this->actingAs(vendorUser(vendorOrg()))->getJson(route('tracking.index'))->assertForbidden();
});

// Kyle, 2026-11-24: where the file is, when it last arrived there, and the days at each unit.

it('adds up the time at each unit, returns included, and finds where the file is now', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();

    $tracking = FileTracking::units(CaseTransition::query()->where('case_id', $case->id)->orderBy('created_at')->get());

    expect(array_map(fn ($u) => [$u['key'], $u['seconds'], $u['days'], $u['visited'], $u['current']], $tracking['units']))->toBe([
        ['cmo', 3 * 86400 + 12 * 3600, '3 days', true, false],   // submitted, intake, ready, prepared, returned
        ['technocrats', 2 * 86400, '2 days', true, false],       // in review
        ['legal', 3 * 86400 + 12 * 3600, '3 days', true, true],  // both visits, the open one to now
        ['ps', 0, '—', false, false],
    ])
        ->and($tracking['current'])->toMatchArray([
            'key' => 'legal',
            'label' => 'Legal Unit',
            // Back from the CMO at 9 Nov 00:00 UTC: 8 Nov in Port of Spain.
            'received_at' => '8 Nov 2026',
            'seconds' => 86400 + 12 * 3600,
            'days' => '1 day',
        ]);
});

it('gives every timeline stage its unit', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();

    expect(array_column(FileTracking::timeline($case), 'unit'))->toBe(['cmo', 'cmo', 'technocrats', 'cmo', 'cmo', 'legal', 'cmo', 'legal'])
        ->and(FileTracking::unitOf(S::CLOSED))->toBeNull()
        ->and(FileTracking::days(null))->toBe('—')
        ->and(FileTracking::days(3600))->toBe('Under 1 day')
        ->and(FileTracking::days(86400))->toBe('1 day');
});

it('puts the File Tracking board on the dashboard for the cases the user can see', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();

    $board = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('dashboard'))->assertOk())['tracking'];
    $row = collect($board['rows'])->firstWhere('id', $case->id);

    expect($board['units'])->toBe(FileTracking::UNITS)
        ->and($row)->toMatchArray([
            'reference' => $case->reference_no,
            'item' => 'Feeding tube',
            'din' => null,
            'href' => '/cases/'.$case->id,
            'stage' => 'With Legal',
        ])
        ->and($row['current']['key'])->toBe('legal')
        ->and(array_column($row['units'], 'days', 'key'))->toBe(['cmo' => '3 days', 'technocrats' => '2 days', 'legal' => '3 days', 'ps' => '—']);

    // Not theirs: an unassigned technocrat's board leaves it out.
    $techRows = pageProps($this->actingAs(caseUser(Role::Technocrat))->get(route('dashboard'))->assertOk())['tracking']['rows'];
    expect(array_column($techRows, 'id'))->not->toContain($case->id);
});

it('sends the File Tracking tab with the case page', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();

    $tracking = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk())['tracking'];

    expect($tracking['current']['key'])->toBe('legal')
        ->and(count($tracking['stages']))->toBe(8)
        ->and($tracking['stages'][7]['open'])->toBeTrue();
});

it('hides the Dashboard item from vendors, whose home is Requests', function () {
    $nav = fn ($user) => pageProps($this->actingAs($user)->get(route('profile.edit'))->assertOk())['nav'];

    expect($nav(vendorUser(vendorOrg()))['dashboard'])->toBeFalse()
        ->and($nav(caseUser(Role::Cmo))['dashboard'])->toBeTrue();
});

// Backlog, 2026-11-25: the File Tracking page ---------------------------------------------

/** A partial reload of the File Tracking page asking for just the timeline. */
function ftTimeline($test, User $user, array $query): mixed
{
    $version = $test->actingAs($user)->get(route('tracking.index'))->viewData('page')['version'];

    return $test->actingAs($user)->get(route('tracking.index', $query), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'tracking/index',
        'X-Inertia-Partial-Data' => 'timeline',
    ])->assertOk()->json('props.timeline');
}

it('renders the File Tracking page with the rows and no timeline until one is opened', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('tracking.index'))->assertOk());
    $row = collect($props['rows'])->firstWhere('id', $case->id);

    expect($row['stage'])->toBe('With Legal')
        ->and($row['gate'])->toBeNull() // not in review
        ->and($props)->not->toHaveKey('timeline');
});

it('gives a row in review the gate ticks, one per desk plus the hardcopy', function () {
    $case = ftCase(S::IN_REVIEW, [[S::DRAFT, S::SUBMITTED, '2026-11-01 12:00'], [S::SUBMITTED, S::IN_REVIEW, '2026-11-02 12:00']]);

    $row = collect(pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('tracking.index')))['rows'])->firstWhere('id', $case->id);

    expect(collect($row['gate'])->pluck('key')->last())->toBe('hardcopy')
        ->and(collect($row['gate'])->pluck('done')->unique()->all())->toBe([false]);
});

it('sends the opened row\'s timeline on the partial reload, and null for a case the user cannot see', function () {
    CarbonImmutable::setTestNow(ftAt('2026-11-10 12:00'));
    [$case] = ftLoop();

    $open = ftTimeline($this, caseUser(Role::Cmo), ['open' => $case->id]);
    expect($open['id'])->toBe((string) $case->id)
        ->and(count($open['stages']))->toBe(8);

    expect(ftTimeline($this, caseUser(Role::Technocrat), ['open' => $case->id]))->toBeNull()
        ->and(ftTimeline($this, caseUser(Role::Cmo), ['open' => 'not-a-ulid']))->toBeNull();
});
