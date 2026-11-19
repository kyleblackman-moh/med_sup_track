<?php

use App\Console\Commands\DevSampleCase;
use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Http\Resources\Cases\StaffCaseDetail;
use App\Http\Resources\Dashboard\DashboardView;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\QueryRoute;
use App\Models\User;
use App\Queries\QueryService;
use App\Support\WorkQueue;
use App\Workflow\Actions\RecordFundingConfirmation;
use App\Workflow\Actions\RecordTechnicalReview;
use App\Workflow\TransitionRules;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// The dashboard queue and the case page agree: for every stage dev:sample-case
// can reach (real services, not hand-built rows) and every staff dev user, a
// case is in that user's queue exactly when the matching action flag on the
// staff case detail DTO is true. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('documents');
    config(['documents.scanner' => 'eicar_dev']);

    // One user per desk, so the command finds a user even on an empty database.
    vendorUser(vendorOrg());
    foreach ([Role::CmoRegistry, Role::Cmo, Role::Technocrat, Role::CabOfficer, Role::LegalOfficer, Role::PsOffice] as $role) {
        caseUser($role);
    }
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** The staff dev users the command itself picks (DevSampleCase::findUser). @return array<string, User> */
function parityDevUsers(): array
{
    $find = Closure::bind(fn (string $key) => $this->findUser($key), app(DevSampleCase::class), DevSampleCase::class);

    return collect(['registry', 'cmo', 'technocrat', 'cab', 'legal', 'ps'])
        ->mapWithKeys(fn (string $key) => [$key => $find($key)])
        ->filter()->all();
}

function paritySample(): ProcurementCase
{
    $caseId = DB::table('case_requisitions')->where('item_description', 'like', '[[]'.trim(DevSampleCase::TAG, '[]').']%')
        ->orderByDesc('id')->value('case_id');

    return ProcurementCase::withoutGlobalScopes()->findOrFail($caseId);
}

/**
 * Queue step => is it due, by the case page's action flags.
 *
 * PARITY EXCEPTIONS (documented; each adds a condition on top of the flag):
 *   - hardcopy: the case page also offers a correction once received; the
 *     queue only shows it while not yet received.
 *   - gate: the case page shows "mark ready" with a gate tracker while the
 *     gate is still open; the queue only once nothing is missing.
 *   - technical / funding (review pending): the case page keeps the review form
 *     after the reviewer records an outcome, so it can be changed; the queue
 *     only shows it while the form has no outcome in the current round
 *     (review_forms[].current === null, TrackCalculator's same-round rule).
 *
 * @return list<string>
 */
function stepsByDetailFlags(ProcurementCase $case, array $dto): array
{
    $can = $dto['can'];
    $pendingReview = fn (string $track) => collect($dto['review_forms'])
        ->contains(fn (array $form) => $form['track'] === $track && $form['current'] === null);

    $due = [
        'technical' => $can['record_review'] && $pendingReview('technical'),
        'funding' => $can['record_review'] && $pendingReview('funding'),
        'legal_decision' => $can['record_legal_decision'],
        'ps_decision' => $can['record_ps_decision'],
        'intake' => $can['intake'],
        'hardcopy' => $can['record_hardcopy'] && ! $case->hardcopyReceived(),
        'forward' => $can['forward_for_review'],
        'gate' => $can['mark_ready_for_file'] && TransitionRules::gateMissing($case) === [],
        'prepare' => $can['prepare_file'],
        'legal' => $can['send_to_legal'],
        'returned' => $can['route_onward'],
        'letter' => $can['issue_letter'],
        'close' => $can['close_case'],
        // OVERNIGHT-2 (D4): the Queries tab's own flags (QueryAccess::forwardable and needsAnswer).
        'query_forward' => collect($dto['queries']['queries'])->contains(fn (array $q) => $q['needs_my_forward']),
        'query_answer' => collect($dto['queries']['queries'])->contains(fn (array $q) => $q['needs_my_answer']),
    ];

    return collect($due)->filter()->keys()->sort()->values()->all();
}

it('queues a case for a user exactly when the case page offers the matching action', function (string $stage) {
    app()->detectEnvironment(fn () => 'local');
    $this->artisan('dev:sample-case', ['stage' => $stage])->assertSuccessful();
    $case = paritySample();

    $users = parityDevUsers();
    expect($users)->toHaveCount(6);

    foreach ($users as $key => $user) {
        $this->actingAs($user);
        $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);

        $queued = collect(WorkQueue::for($user)['items'] ?? [])
            ->where('case_id', $case->id)->pluck('step')->sort()->values()->all();
        $expected = stepsByDetailFlags($fresh, StaffCaseDetail::for($fresh, $user));

        expect($queued)->toBe($expected, "stage {$stage}, user {$key}");
    }
    assertChipParity($users, "stage {$stage}");
})->with(array_keys(DevSampleCase::STAGES));

/**
 * FIX-2 (2): every chip's count is WorkQueue's own count for the chip's steps,
 * and applying the chip shows exactly that many rows, each of those steps.
 *
 * @param  array<string, User>  $users
 */
function assertChipParity(array $users, string $label = ''): void
{
    foreach ($users as $key => $user) {
        test()->actingAs($user);
        $workQueue = WorkQueue::for($user);
        $all = DashboardView::queue($user, $workQueue, '', '');
        if ($all === null) {
            continue;
        }
        $groups = array_column($workQueue['groups'], 'count', 'key');

        foreach ($all['filters'] as $chip) {
            $steps = $chip['key'] === 'all' ? null : DashboardView::filterSteps($chip['key']);
            $expected = $steps === null ? count($workQueue['items']) : array_sum(array_intersect_key($groups, array_flip($steps)));
            $shown = DashboardView::queue($user, $workQueue, $chip['key'], '');

            expect($chip['count'])->toBe($expected, "{$label} user {$key}, chip {$chip['key']}")
                ->and(count($shown['items']))->toBe($chip['count'], "{$label} user {$key}, rows of chip {$chip['key']}");
            if ($steps !== null) {
                expect(array_diff(array_column($shown['items'], 'step'), $steps))->toBe([], "{$label} user {$key}, steps of chip {$chip['key']}");
            }
        }
    }
}

it('drops a review from the queue once the reviewer records it, while the case page keeps the form (parity exception)', function (string $key, ReviewDesk $desk) {
    app()->detectEnvironment(fn () => 'local');
    $this->artisan('dev:sample-case', ['stage' => 'in_review'])->assertSuccessful();
    $case = paritySample();
    $reviewer = parityDevUsers()[$key];
    $this->actingAs($reviewer);

    $steps = fn () => collect(WorkQueue::for($reviewer)['items'])->where('case_id', $case->id)->pluck('step')->all();
    $form = fn () => collect(StaffCaseDetail::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $reviewer)['review_forms'])
        ->firstWhere('track', $desk->value);

    expect($steps())->toBe([$desk->value])
        ->and($form()['current'])->toBeNull();

    // The real service, as the dev command uses it.
    $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    $desk === ReviewDesk::Technical
        ? app(RecordTechnicalReview::class)->handle($fresh, $reviewer, 'favourable')
        : app(RecordFundingConfirmation::class)->handle($fresh, $reviewer, 'confirmed');

    expect(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->status)->toBe(CaseStatus::IN_REVIEW)
        ->and($steps())->toBe([])
        ->and($form())->not->toBeNull()
        ->and($form()['current'])->not->toBeNull();
})->with([
    'technocrat' => ['technocrat', ReviewDesk::Technical],
    'CAB' => ['cab', ReviewDesk::Funding],
]);

it('queues query work exactly when the Queries tab asks this user to forward or answer', function () {
    app()->detectEnvironment(fn () => 'local');
    $this->seed(QueryRouteSeeder::class);
    $this->artisan('dev:sample-case', ['stage' => 'with_legal'])->assertSuccessful();
    $case = paritySample();
    $users = parityDevUsers();

    // Legal raises a query to the Technocrats (via the CMO): held for the CMO.
    $route = QueryRoute::query()->where('from_role', 'legal_officer')->where('to_role', 'technocrat')->firstOrFail();
    $this->actingAs($users['legal']);
    app(QueryService::class)->raise($case, $users['legal'], (int) $route->id, 'A parity question');

    $check = function () use ($case, $users) {
        foreach ($users as $key => $user) {
            $this->actingAs($user);
            $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
            $queued = collect(WorkQueue::for($user)['items'] ?? [])->where('case_id', $case->id)->pluck('step')->sort()->values()->all();
            $expected = stepsByDetailFlags($fresh, StaffCaseDetail::for($fresh, $user));
            expect($queued)->toBe($expected, "user {$key}");

            // FIX-2 (2): the Queries chip shows this case exactly when the Queries tab asks this user to act.
            $chip = DashboardView::queue($user, WorkQueue::for($user), 'queries', '');
            // Without query work the user has no Queries chip, and the filter falls back to All.
            $expectsQuery = array_intersect($expected, ['query_forward', 'query_answer']) !== [];
            $shown = $chip !== null && $chip['filter'] === 'queries' && collect($chip['items'])->contains('case_id', $case->id);
            expect($shown)->toBe($expectsQuery, "queries chip, user {$key}");
        }
        assertChipParity($users);
    };

    $check();
    expect(collect(WorkQueue::for($users['cmo'])['items'])->where('case_id', $case->id)->pluck('step')->all())->toContain('query_forward');

    // The CMO forwards: now the Technocrat has a query to answer.
    $this->actingAs($users['cmo']);
    $query = CaseQuery::withoutGlobalScopes()->where('case_id', $case->id)->firstOrFail();
    app(QueryService::class)->forward($case, $query, $query->messages()->firstOrFail(), $users['cmo']);

    $check();
    $this->actingAs($users['technocrat']);
    expect(collect(WorkQueue::for($users['technocrat'])['items'])->where('case_id', $case->id)->pluck('step')->all())->toContain('query_answer');
});
