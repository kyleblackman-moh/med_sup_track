<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Http\Resources\Dashboard\DashboardView;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// The dashboard (NEXT part 3): the server-built header and stat tiles. The
// tiles come from one grouped COUNT under the viewer's visibility scope. The
// dev database holds other rows, so counts are compared before and after this
// test's cases are made (DatabaseTransactions only).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** The dashboard's props for $user. */
function dashboardProps($test, User $user): array
{
    return pageProps($test->actingAs($user)->get(route('dashboard'))->assertOk());
}

/** Tile key => integer value (the server formats "1,234"). @return array<string, int> */
function tileValues(?array $stats): array
{
    return collect($stats ?? [])->mapWithKeys(fn (array $t) => [$t['key'] => (int) str_replace(',', '', $t['value'])])->all();
}

function statsCase(CaseStatus $status, array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'status' => $status,
        'submitted_at' => now()->subDays(3),
        'reference_no' => 'NR-ST-'.Str::upper(Str::random(8)),
        'current_holder_role' => 'cmo',
        ...$attributes,
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Stats item'])->save();

    return $case;
}

function statsHardcopy(): array
{
    return ['hardcopy_received_on' => now()->toDateString(), 'hardcopy_received_at' => now(), 'hardcopy_received_by' => caseUser()->id];
}

it('gives a case.view_all holder the four tiles, counted from the cases it can see', function () {
    $cmo = caseUser(Role::Cmo);
    $before = tileValues(dashboardProps($this, $cmo)['stats']);

    statsCase(CaseStatus::SUBMITTED);                     // open, awaiting hardcopy
    statsCase(CaseStatus::IN_REVIEW, statsHardcopy());    // open, hardcopy in
    statsCase(CaseStatus::READY_FOR_FILE, statsHardcopy()); // open, ready for file
    statsCase(CaseStatus::CLOSED);                        // terminal: not open
    statsCase(CaseStatus::WITHDRAWN);                     // terminal: not open
    makeCase(['status' => CaseStatus::DRAFT]);            // staff never see drafts

    $props = dashboardProps($this, $cmo);
    $after = tileValues($props['stats']);

    expect(array_keys($after))->toBe(['needs_action', 'open', 'awaiting_hardcopy', 'ready_for_file'])
        ->and($after['open'] - $before['open'])->toBe(3)
        ->and($after['awaiting_hardcopy'] - $before['awaiting_hardcopy'])->toBe(1)
        ->and($after['ready_for_file'] - $before['ready_for_file'])->toBe(1);
});

it('counts "Needs My Action" from the queue itself', function (string $role) {
    $user = caseUser($role);
    statsCase(CaseStatus::SUBMITTED);
    statsCase(CaseStatus::READY_FOR_FILE, statsHardcopy());

    $props = dashboardProps($this, $user);

    expect(tileValues($props['stats'])['needs_action'])->toBe(count($props['workQueue']['items']));
})->with([Role::Cmo->value, Role::CmoRegistry->value, Role::PsOffice->value]);

it('never counts cases a role without case.view_all cannot see', function () {
    $technocrat = caseUser(Role::Technocrat);
    $before = tileValues(dashboardProps($this, $technocrat)['stats']);

    $mine = statsCase(CaseStatus::IN_REVIEW);
    assign($mine, Role::Technocrat, $technocrat);
    statsCase(CaseStatus::IN_REVIEW);                               // not assigned
    assign(statsCase(CaseStatus::IN_REVIEW), Role::Technocrat, caseUser(Role::Technocrat)); // another technocrat's
    statsCase(CaseStatus::SUBMITTED);                               // awaiting hardcopy, not theirs
    statsCase(CaseStatus::READY_FOR_FILE, statsHardcopy());         // ready for file, not theirs

    $after = tileValues(dashboardProps($this, $technocrat)['stats']);

    expect(array_keys($after))->toBe(['needs_action', 'open'])
        ->and($after['open'] - $before['open'])->toBe(1);
});

it('counts the same cases the staff case list shows the viewer', function () {
    $legal = caseUser(Role::LegalOfficer);
    $case = statsCase(CaseStatus::WITH_LEGAL);
    assign($case, Role::LegalOfficer, $legal);

    $this->actingAs($legal);
    $visibleOpen = ProcurementCase::query()->whereNotIn('cases.status', [CaseStatus::CLOSED->value, CaseStatus::WITHDRAWN->value, CaseStatus::REJECTED_FINAL->value])->count();

    expect(DashboardView::counts($legal)['open'])->toBe($visibleOpen);
});

it('gives vendors no tiles, so another organisation is never counted for them', function () {
    $vendor = vendorUser(vendorOrg());
    statsCase(CaseStatus::SUBMITTED); // another organisation's case

    $props = dashboardProps($this, $vendor);

    expect($props['stats'])->toBeNull()
        ->and($props['workQueue'])->toBeNull();
});

it('gives staff with neither a queue nor the case list no tiles', function () {
    expect(dashboardProps($this, caseUser(Role::Auditor))['stats'])->toBeNull()
        ->and(dashboardProps($this, caseUser(Role::SystemAdmin))['stats'])->toBeNull();
});

it('links a tile only where the case list has a matching filter', function () {
    $stats = collect(dashboardProps($this, caseUser(Role::Cmo))['stats'])->keyBy('key');

    expect($stats['ready_for_file']['href'])->toBe('/cases?status=READY_FOR_FILE')
        ->and($stats['needs_action']['href'])->toBeNull()
        ->and($stats['open']['href'])->toBeNull()
        ->and($stats['awaiting_hardcopy']['href'])->toBeNull();

    $this->actingAs(caseUser(Role::Cmo))->get($stats['ready_for_file']['href'])->assertOk();
});

it('sends no SLA values with the tiles', function () {
    $stats = dashboardProps($this, caseUser(Role::Cmo))['stats'];

    foreach ($stats as $tile) {
        expect(array_keys($tile))->toBe(['key', 'label', 'value', 'description', 'href'])
            ->and($tile['value'])->toMatch('/^[\d,]+$/');
    }
});

it('sends each tile its Title Case label and short description from the server (DASH-1)', function () {
    $stats = collect(dashboardProps($this, caseUser(Role::Cmo))['stats'])->keyBy('key');

    expect($stats->map(fn ($t) => [$t['label'], $t['description']])->all())->toBe([
        'needs_action' => ['Needs My Action', 'Cases waiting for you to act'],
        'open' => ['Open Cases', 'Cases that are not yet closed'],
        'awaiting_hardcopy' => ['Awaiting Hardcopy', 'Submitted, signed copy not yet received'],
        'ready_for_file' => ['Ready for File', 'All three conditions are met'],
    ]);

    // A role without case.view_all gets only its two tiles, with the same descriptions.
    $technocrat = collect(dashboardProps($this, caseUser(Role::Technocrat))['stats'])->pluck('description', 'key')->all();
    expect($technocrat)->toBe(['needs_action' => 'Cases waiting for you to act', 'open' => 'Cases that are not yet closed']);
});

it('builds the header on the server in local time', function () {
    $user = caseUser(Role::Cmo, ['name' => 'Avery Example']);
    // 11:30 UTC is 07:30 in Port of Spain.
    $header = DashboardView::header($user, ['items' => [['id' => 'a'], ['id' => 'b']]], CarbonImmutable::parse('2026-11-18 11:30:00', 'UTC'));

    expect($header)->toBe([
        'title' => 'Good morning, Avery',
        'subtitle' => 'Wednesday 18 November 2026 · 2 items waiting on you',
    ])->and(DashboardView::header($user, null, CarbonImmutable::parse('2026-11-18 23:00:00', 'UTC'))['subtitle'])
        ->toBe('Wednesday 18 November 2026');
});

it('sends the request type of each queue row', function () {
    $cmo = caseUser(Role::Cmo);
    $case = statsCase(CaseStatus::READY_FOR_FILE, statsHardcopy());

    $props = dashboardProps($this, $cmo);

    expect($props['requestTypes'][$case->id])->toBe(testProcurementType()->name);
});
