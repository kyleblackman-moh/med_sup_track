<?php

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Http\Resources\Cases\StaffCaseDetail;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\LandingPage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// CMO dashboard work queue (App\Support\WorkQueue), the CAB "awaiting funding"
// view of the case register, and the dashboard landing for queue users.
// The dev database holds other rows, so assertions look at this test's cases only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function queueCase(array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'status' => CaseStatus::SUBMITTED,
        'submitted_at' => now()->subDays(2),
        'reference_no' => 'NR-WQ-'.Str::upper(Str::random(8)),
        'current_holder_role' => 'cmo',
        ...$attributes,
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Queue item'])->save();

    return $case;
}

/** Hardcopy columns set together (ck_cases_hardcopy_all_or_none). */
function withHardcopy(): array
{
    return ['hardcopy_received_on' => now()->toDateString(), 'hardcopy_received_at' => now(), 'hardcopy_received_by' => caseUser()->id];
}

/** Queue steps for $case on $user's dashboard. @return list<string> */
function queueSteps(User $user, ProcurementCase $case): array
{
    $queue = pageProps(test()->actingAs($user)->get(route('dashboard'))->assertOk())['workQueue'];

    return collect($queue['items'] ?? [])->where('case_id', $case->id)->pluck('step')->sort()->values()->all();
}

it('puts each CMO step on the queue for the matching status', function (CaseStatus $status, array $extra, array $steps) {
    $case = queueCase(['status' => $status, ...$extra]);

    expect(queueSteps(caseUser(Role::Cmo), $case))->toBe($steps);
})->with([
    'new submission without hardcopy' => [CaseStatus::SUBMITTED, [], ['hardcopy', 'intake']],
    'new submission with hardcopy' => [CaseStatus::SUBMITTED, fn () => withHardcopy(), ['intake']],
    'intake, to forward' => [CaseStatus::CMO_INTAKE, fn () => withHardcopy(), ['forward']],
    'files to prepare' => [CaseStatus::READY_FOR_FILE, [], ['prepare']],
    'to send to Legal' => [CaseStatus::FILE_PREPARED, [], ['legal']],
    'returned to CMO' => [CaseStatus::RETURNED_TO_CMO, [], ['returned']],
    'letters to draft' => [CaseStatus::NON_OBJECTION_GRANTED, [], ['letter']],
    'to close' => [CaseStatus::LETTER_ISSUED, [], ['close']],
    'with Legal: not the CMO\'s move' => [CaseStatus::WITH_LEGAL, [], []],
    'with PS: not the CMO\'s move' => [CaseStatus::WITH_PS, [], []],
    'closed' => [CaseStatus::CLOSED, [], []],
]);

it('shows the gate step only when all three tracks are complete', function (TrackStatus $technical, TrackStatus $funding, bool $hardcopy, bool $expected) {
    $case = queueCase([
        'status' => CaseStatus::IN_REVIEW,
        'technical_track' => $technical,
        'funding_track' => $funding,
        ...($hardcopy ? withHardcopy() : []),
    ]);

    expect(in_array('gate', queueSteps(caseUser(Role::Cmo), $case), true))->toBe($expected);
})->with([
    'all complete' => [TrackStatus::FAVOURABLE, TrackStatus::CONFIRMED, true, true],
    'technical pending' => [TrackStatus::PENDING, TrackStatus::CONFIRMED, true, false],
    'funding pending' => [TrackStatus::FAVOURABLE, TrackStatus::PENDING, true, false],
    'no hardcopy' => [TrackStatus::FAVOURABLE, TrackStatus::CONFIRMED, false, false],
]);

it('gives the registry only the steps its permissions allow', function () {
    $case = queueCase(['status' => CaseStatus::SUBMITTED]);
    assign($case, Role::CmoRegistry);

    expect(queueSteps(caseUser(Role::CmoRegistry), $case))->toBe(['hardcopy', 'intake']);
});

it('sends no queue to users without queue permissions', function (string $role) {
    queueCase();

    $props = pageProps($this->actingAs(caseUser($role))->get(route('dashboard'))->assertOk());

    expect($props['workQueue'])->toBeNull();
})->with(['technocrat', 'cab_officer', 'legal_officer', 'ps_office', 'auditor']);

it('measures waiting time and orders the oldest first', function () {
    $older = queueCase(['status' => CaseStatus::READY_FOR_FILE, 'submitted_at' => now()->subDays(12)]);
    $newer = queueCase(['status' => CaseStatus::READY_FOR_FILE, 'submitted_at' => now()->subDays(1)]);

    $items = collect(pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('dashboard')))['workQueue']['items'])
        ->whereIn('case_id', [$older->id, $newer->id])->values();

    expect($items->pluck('case_id')->all())->toBe([$older->id, $newer->id])
        ->and($items[0]['days_waiting'])->toBe(12)
        ->and($items[0]['status_tone'])->toBe('neutral')
        ->and(array_keys($items[0]))->toBe([
            'id', 'case_id', 'step', 'action', 'reference_no', 'item_description', 'organisation',
            'status_label', 'status_tone', 'waiting_since', 'days_waiting',
        ]);
});

it('lands queue users on the dashboard and other staff on the case list', function () {
    expect(LandingPage::for(caseUser(Role::Cmo)))->toBe('/dashboard')
        ->and(LandingPage::for(caseUser(Role::CmoRegistry)))->toBe('/dashboard')
        ->and(LandingPage::for(caseUser(Role::Technocrat)))->toBe('/cases');
});

it('shares nav.cmo_office with the CMO on every page, including the case page with its own can prop', function () {
    $cmo = caseUser(Role::Cmo);
    $case = queueCase();

    foreach ([route('dashboard'), route('cases.index'), route('cases.show', $case)] as $url) {
        $props = pageProps($this->actingAs($cmo)->get($url)->assertOk());

        expect($props['nav']['cmo_office'])->toBeTrue("nav.cmo_office missing on {$url}")
            ->and($props['can'])->not->toHaveKey('cmo_office');
    }
});

it('does not share nav.cmo_office with other users', function (string $who) {
    $user = $who === 'vendor' ? vendorUser(vendorOrg()) : caseUser($who);
    $urls = $who === 'vendor' ? [route('dashboard'), route('requests.index')] : [route('dashboard'), route('cases.index')];

    foreach ($urls as $url) {
        expect(pageProps($this->actingAs($user)->get($url)->assertOk())['nav']['cmo_office'])->toBeFalse();
    }
})->with(['vendor', 'technocrat', 'cmo_registry', 'ps_office']);

it('queues a returned case for a user holding only case.route_to_review', function () {
    $user = caseUser();
    $user->givePermissionTo(Permission::CaseRouteToReview->value);
    // Returned by Legal (no PS return): Technocrats (T11) or Legal (T12) are allowed.
    $case = queueCase(['status' => CaseStatus::RETURNED_TO_CMO]);
    assign($case, Role::Cmo, $user);

    expect(queueSteps($user, $case))->toBe(['returned'])
        ->and(StaffCaseDetail::for($case->fresh(), $user)['can']['route_onward'])->toBeTrue();
});

it('shows waiting time without targets, overdue wording or lateness colours', function () {
    $case = queueCase(['status' => CaseStatus::READY_FOR_FILE, 'submitted_at' => now()->subDays(40)]);

    $queue = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('dashboard')))['workQueue'];
    $item = collect($queue['items'])->firstWhere('case_id', $case->id);
    $text = json_encode([$queue['groups'], $item]);

    // No SLA UI until sla_policies holds values (CLAUDE.md).
    expect($item['days_waiting'])->toBe(40)
        ->and(array_keys($item))->not->toContain('target', 'overdue', 'is_late', 'tone_waiting')
        ->and($text)->not->toMatch('/overdue|over target|target|late\b/i');

    foreach (['resources/js/components/dashboard/work-queue.tsx', 'resources/js/components/cases/case-table.tsx'] as $file) {
        expect(file_get_contents(base_path($file)))
            ->not->toMatch('/overdue|over target|THRESHOLD/i', "{$file} mentions a target")
            ->not->toMatch('/text-destructive|status-(warning|danger)/', "{$file} colours waiting time");
    }
});

it('lists only in-review cases with funding not yet confirmed for awaiting=funding', function () {
    $org = vendorOrg();
    $pending = queueCase(['org_id' => $org->id, 'status' => CaseStatus::IN_REVIEW, 'technical_track' => TrackStatus::PENDING, 'funding_track' => TrackStatus::PENDING]);
    $notConfirmed = queueCase(['org_id' => $org->id, 'status' => CaseStatus::IN_REVIEW, 'technical_track' => TrackStatus::PENDING, 'funding_track' => TrackStatus::NOT_CONFIRMED]);
    queueCase(['org_id' => $org->id, 'status' => CaseStatus::IN_REVIEW, 'technical_track' => TrackStatus::PENDING, 'funding_track' => TrackStatus::CONFIRMED]);
    queueCase(['org_id' => $org->id, 'status' => CaseStatus::SUBMITTED]);

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.index', ['org' => $org->id, 'awaiting' => 'funding']))->assertOk());

    expect(collect($props['cases']['data'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$pending->id, $notConfirmed->id])->sort()->values()->all())
        ->and($props['filters']['awaiting'])->toBe('funding');
});

it('rejects an unknown awaiting value', function () {
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.index', ['awaiting' => 'legal']))
        ->assertRedirect(route('cases.index'))
        ->assertSessionHasErrors('awaiting');
});
