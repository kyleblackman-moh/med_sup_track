<?php

use App\Enums\CaseStatus as S;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Http\Resources\Dashboard\DashboardView;
use App\Models\CaseAssignment;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\NextStep;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// OVERNIGHT-2 (C1, C2): the Pipeline and Gate Tracker panels, on the Analytics page (ANALYTICS).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);

    $this->cmo = caseUser(Role::Cmo);
    $this->ps = caseUser(Role::PsOffice);
    $this->technocrat = caseUser(Role::Technocrat, ['name' => 'Gate Tech '.Str::upper(Str::random(4))]);
    $this->cab = caseUser(Role::CabOfficer, ['name' => 'Gate CAB '.Str::upper(Str::random(4))]);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function pipelineCase(S $status, array $attributes = []): ProcurementCase
{
    return makeCase([
        'status' => $status,
        'reference_no' => 'NR-PL-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(3),
        ...$attributes,
    ]);
}

function gateAssign(ProcurementCase $case, ReviewDesk $desk, User $user): void
{
    (new CaseAssignment)->forceFill([
        'case_id' => $case->id, 'role' => $desk->assignmentRole(), 'purpose' => $desk->value,
        'user_id' => $user->id, 'assigned_at' => CarbonImmutable::now('UTC')->subDay(),
    ])->save();
}

/** Status => count from the pipeline, for the given user. */
function pipelineCounts(User $user): array
{
    return collect(DashboardView::pipeline($user))->pluck('count', 'status')->all();
}

it('counts every visible case per stage, in state-machine order, with a filtered link', function () {
    $before = pipelineCounts($this->cmo);
    pipelineCase(S::SUBMITTED);
    pipelineCase(S::SUBMITTED);
    pipelineCase(S::WITH_LEGAL);
    pipelineCase(S::DRAFT);

    $pipeline = DashboardView::pipeline($this->cmo);
    $after = collect($pipeline)->pluck('count', 'status')->all();

    expect(array_column($pipeline, 'status'))->toBe(array_values(array_map(fn (S $s) => $s->value, array_filter(S::cases(), fn (S $s) => $s !== S::DRAFT))))
        ->and($after['SUBMITTED'] - $before['SUBMITTED'])->toBe(2)
        ->and($after['WITH_LEGAL'] - $before['WITH_LEGAL'])->toBe(1)
        ->and($after)->not->toHaveKey('DRAFT')
        ->and($pipeline[0])->toBe(['status' => 'SUBMITTED', 'label' => 'Submitted', 'count' => $after['SUBMITTED'], 'href' => '/cases?status=SUBMITTED', 'mine' => true])
        // The CMO's desk: intake is theirs, the Legal review is not.
        ->and(collect($pipeline)->firstWhere('status', 'WITH_LEGAL')['mine'])->toBeFalse();
});

it('gives the CMO and PS every case, and others only their own', function () {
    $total = fn (User $u) => array_sum(pipelineCounts($u));
    $cmoBefore = $total($this->cmo);
    $psBefore = $total($this->ps);
    $techBefore = $total($this->technocrat);

    $mine = pipelineCase(S::IN_REVIEW);
    gateAssign($mine, ReviewDesk::Technical, $this->technocrat);
    pipelineCase(S::IN_REVIEW); // not assigned to the Technocrat

    expect($total($this->cmo) - $cmoBefore)->toBe(2)
        ->and($total($this->ps) - $psBefore)->toBe(2)
        ->and($total($this->technocrat) - $techBefore)->toBe(1);
});

it('gives no pipeline or gate tracker to users without the case list', function (Role $role) {
    $user = caseUser($role);

    expect(DashboardView::pipeline($user))->toBeNull()
        ->and(DashboardView::gateTracker($user))->toBeNull();
})->with([Role::SystemAdmin, Role::Auditor]);

it('gives a vendor neither panel, and no Analytics page', function () {
    $vendor = vendorUser(vendorOrg());

    expect(DashboardView::pipeline($vendor))->toBeNull()
        ->and(DashboardView::gateTracker($vendor))->toBeNull();

    $this->actingAs($vendor)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('pipeline')->missing('gateTracker'));
    $this->actingAs($vendor)->get(route('analytics'))->assertForbidden();
});

it('shows the gate tracker to case.view_all holders only', function () {
    expect(DashboardView::gateTracker($this->cmo))->not->toBeNull()
        ->and(DashboardView::gateTracker($this->technocrat))->toBeNull();
});

it('ticks Technical, Funding and Hardcopy from the gate, and names who holds an open item', function () {
    $open = pipelineCase(S::IN_REVIEW, ['technical_track' => TrackStatus::PENDING, 'funding_track' => TrackStatus::CONFIRMED]);
    gateAssign($open, ReviewDesk::Technical, $this->technocrat);
    gateAssign($open, ReviewDesk::Funding, $this->cab);

    $clear = pipelineCase(S::IN_REVIEW, [
        'technical_track' => TrackStatus::FAVOURABLE, 'funding_track' => TrackStatus::CONFIRMED,
        'hardcopy_received_on' => CarbonImmutable::now('America/Port_of_Spain')->subDay()->toDateString(),
        'hardcopy_received_at' => CarbonImmutable::now('UTC')->subDay(),
        'hardcopy_received_by' => $this->cmo->id,
    ]);
    gateAssign($clear, ReviewDesk::Technical, $this->technocrat);
    gateAssign($clear, ReviewDesk::Funding, $this->cab);

    pipelineCase(S::WITH_LEGAL); // never a row

    $rows = collect(DashboardView::gateTracker($this->cmo)['rows'])->keyBy('id');
    $items = fn (ProcurementCase $c) => collect($rows[$c->id]['items'])->keyBy('key');

    expect($rows->keys()->all())->toContain($open->id, $clear->id)
        ->and($rows->pluck('items')->flatten(1)->pluck('label')->unique()->values()->all())->toBe(['Technical', 'Funding', 'Hardcopy'])
        ->and($items($open)['technical']['done'])->toBeFalse()
        ->and($items($open)['technical']['held_by'])->toBe('Technocrats: '.$this->technocrat->name)
        ->and($items($open)['funding']['done'])->toBeTrue()
        ->and($items($open)['funding']['held_by'])->toBeNull()
        ->and($items($open)['hardcopy']['done'])->toBeFalse()
        ->and($items($open)['hardcopy']['held_by'])->toBe("CMO's Office")
        ->and($items($clear)->every(fn ($i) => $i['done'] === true))->toBeTrue()
        ->and($rows[$open->id]['href'])->toBe('/cases/'.$open->id);
});

it('agrees with the Next Step card for every row', function () {
    $case = pipelineCase(S::IN_REVIEW, ['technical_track' => TrackStatus::FAVOURABLE, 'funding_track' => TrackStatus::PENDING]);
    gateAssign($case, ReviewDesk::Technical, $this->technocrat);
    gateAssign($case, ReviewDesk::Funding, $this->cab);

    $row = collect(DashboardView::gateTracker($this->cmo)['rows'])->firstWhere('id', $case->id);
    $card = collect(NextStep::for(ProcurementCase::withoutGlobalScopes()->find($case->id), $this->cmo)['checklist'])->keyBy('key');

    foreach ($row['items'] as $item) {
        expect($item['done'])->toBe($card[$item['key']]['done']);
    }
});

it('sends both panels to the CMO and PS on the Analytics page, not the dashboard', function (string $who) {
    $user = $this->{$who};

    $this->actingAs($user)->get(route('analytics'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pipeline', count(S::cases()) - 1)
            ->has('gateTracker.rows'));

    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('pipeline')->missing('gateTracker'));
})->with(['cmo', 'ps']);

it('filters the case list by a pipeline stage', function () {
    $case = pipelineCase(S::FILE_PREPARED);
    pipelineCase(S::WITH_PS);

    $this->actingAs($this->cmo)->get('/cases?status=FILE_PREPARED')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('cases.data', fn ($rows) => collect($rows)->every(fn ($r) => $r['status'] === 'FILE_PREPARED')
            && collect($rows)->pluck('id')->contains($case->id)));
})->skip(fn () => DB::table('cases')->where('status', 'FILE_PREPARED')->count() > 20, 'Too many FILE_PREPARED cases in the shared database to see the new one on page 1.');
