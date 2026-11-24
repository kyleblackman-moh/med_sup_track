<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Http\Resources\Dashboard\DashboardView;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// The dashboard (NEXT part 3): the server-built header; the stat tiles now on
// the Analytics page (ANALYTICS). The tiles come from one grouped COUNT under the viewer's visibility scope. The
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

/** The Analytics page's props for $user. */
function analyticsProps($test, User $user): array
{
    return pageProps($test->actingAs($user)->get(route('analytics'))->assertOk());
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

it('gives a case.view_all holder the three tiles on the Analytics page, counted from the cases it can see', function () {
    $cmo = caseUser(Role::Cmo);
    $before = tileValues(analyticsProps($this, $cmo)['stats']);

    statsCase(CaseStatus::SUBMITTED);                     // open, awaiting hardcopy
    statsCase(CaseStatus::IN_REVIEW, statsHardcopy());    // open, hardcopy in
    statsCase(CaseStatus::READY_FOR_FILE, statsHardcopy()); // open, ready for file
    statsCase(CaseStatus::CLOSED);                        // terminal: not open
    statsCase(CaseStatus::WITHDRAWN);                     // terminal: not open
    makeCase(['status' => CaseStatus::DRAFT]);            // staff never see drafts

    $after = tileValues(analyticsProps($this, $cmo)['stats']);

    expect(array_keys($after))->toBe(['open', 'awaiting_hardcopy', 'ready_for_file'])
        ->and($after['open'] - $before['open'])->toBe(3)
        ->and($after['awaiting_hardcopy'] - $before['awaiting_hardcopy'])->toBe(1)
        ->and($after['ready_for_file'] - $before['ready_for_file'])->toBe(1);
});

it('no longer sends tiles, the pipeline or the gate tracker with the dashboard', function (string $role) {
    $props = dashboardProps($this, caseUser($role));

    expect($props)->not->toHaveKey('stats')
        ->and($props)->not->toHaveKey('pipeline')
        ->and($props)->not->toHaveKey('gateTracker')
        ->and($props)->toHaveKeys(['workQueue', 'summary', 'requestTypes', 'queue', 'tracking'])
        // Kyle, 2026-11-24: the greeting moved to the top bar; the hardcopy box and the drafts card left.
        ->and($props)->not->toHaveKeys(['header', 'drafts', 'hardcopyLookup']);
})->with([Role::Cmo->value, Role::PsOffice->value, Role::Technocrat->value]);

it('never counts cases a role without case.view_all cannot see', function () {
    $technocrat = caseUser(Role::Technocrat);
    $before = tileValues(DashboardView::stats($technocrat));

    $mine = statsCase(CaseStatus::IN_REVIEW);
    assign($mine, Role::Technocrat, $technocrat);
    statsCase(CaseStatus::IN_REVIEW);                               // not assigned
    assign(statsCase(CaseStatus::IN_REVIEW), Role::Technocrat, caseUser(Role::Technocrat)); // another technocrat's
    statsCase(CaseStatus::SUBMITTED);                               // awaiting hardcopy, not theirs
    statsCase(CaseStatus::READY_FOR_FILE, statsHardcopy());         // ready for file, not theirs

    $after = tileValues(DashboardView::stats($technocrat));

    expect(array_keys($after))->toBe(['open'])
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

    expect(DashboardView::stats($vendor))->toBeNull();
    // Kyle, 2026-11-24: their dashboard is their Requests list.
    $this->actingAs($vendor)->get(route('dashboard'))->assertRedirect(route('requests.index'));
});

it('gives staff without the case list no tiles', function () {
    expect(DashboardView::stats(caseUser(Role::Auditor)))->toBeNull()
        ->and(DashboardView::stats(caseUser(Role::SystemAdmin)))->toBeNull();
});

it('links a tile only where the case list has a matching filter', function () {
    $stats = collect(analyticsProps($this, caseUser(Role::Cmo))['stats'])->keyBy('key');

    expect($stats['ready_for_file']['href'])->toBe('/cases?status=READY_FOR_FILE')
        ->and($stats['open']['href'])->toBeNull()
        ->and($stats['awaiting_hardcopy']['href'])->toBeNull();

    $this->actingAs(caseUser(Role::Cmo))->get($stats['ready_for_file']['href'])->assertOk();
});

it('sends no SLA values with the tiles', function () {
    $stats = analyticsProps($this, caseUser(Role::Cmo))['stats'];

    foreach ($stats as $tile) {
        expect(array_keys($tile))->toBe(['key', 'label', 'value', 'description', 'href'])
            ->and($tile['value'])->toMatch('/^[\d,]+$/');
    }
});

it('sends each tile its Title Case label and short description from the server (DASH-1)', function () {
    $stats = collect(analyticsProps($this, caseUser(Role::Cmo))['stats'])->keyBy('key');

    expect($stats->map(fn ($t) => [$t['label'], $t['description']])->all())->toBe([
        'open' => ['Open Cases', 'Cases that are not yet closed'],
        'awaiting_hardcopy' => ['Awaiting Hardcopy', 'Submitted, signed copy not yet received'],
        'ready_for_file' => ['Ready for File', 'All three conditions are met'],
    ]);

    // A role without case.view_all gets only the open tile, with the same description.
    $technocrat = collect(DashboardView::stats(caseUser(Role::Technocrat)))->pluck('description', 'key')->all();
    expect($technocrat)->toBe(['open' => 'Cases that are not yet closed']);
});

it('sums up the items waiting on the user under the title', function () {
    expect(DashboardView::summary(['items' => [['id' => 'a'], ['id' => 'b']]]))->toBe('2 items waiting on you')
        ->and(DashboardView::summary(['items' => [['id' => 'a']]]))->toBe('1 item waiting on you')
        ->and(DashboardView::summary(['items' => []]))->toBe('No items waiting on you')
        ->and(DashboardView::summary(null))->toBeNull();
});

it('sends the request type of each queue row', function () {
    $cmo = caseUser(Role::Cmo);
    $case = statsCase(CaseStatus::READY_FOR_FILE, statsHardcopy());

    $props = dashboardProps($this, $cmo);

    expect($props['requestTypes'][$case->id])->toBe(testProcurementType()->name);
});
