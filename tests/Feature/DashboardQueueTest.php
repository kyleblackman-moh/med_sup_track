<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Http\Resources\Dashboard\DashboardView;
use App\Models\CaseAssignment;
use App\Models\CaseRequisition;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\SqlLike;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// DASH-1: the dashboard queue card. The filter chips (WorkQueue steps, with
// WorkQueue's own counts), the server-side search within the user's own queue,
// and the table rows built on the server. The dev database holds other rows, so
// counts are compared before and after, and searches use unique text.
// DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** The "queue" prop for $user with the query $query. */
function queueProp($test, User $user, array $query = []): ?array
{
    return pageProps($test->actingAs($user)->get(route('dashboard', $query))->assertOk())['queue'];
}

/** Filter key => count. */
function chipCounts(array $queue): array
{
    return array_column($queue['filters'], 'count', 'key');
}

/** A queue-ready case, oldest of all (so the queue's cap never drops it), with a unique reference. */
function dashCase(CaseStatus $status, string $item = 'Dash item', array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'status' => $status,
        'submitted_at' => now()->subYears(20),
        'reference_no' => 'NR-DQ-'.Str::upper(Str::random(8)),
        'current_holder_role' => 'cmo',
        ...$attributes,
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => $item])->save();

    return $case;
}

function dashHardcopy(): array
{
    return ['hardcopy_received_on' => now()->toDateString(), 'hardcopy_received_at' => now(), 'hardcopy_received_by' => caseUser()->id];
}

/** An active technical-review assignment, as ForwardForReview makes it. */
function reviewAssign(ProcurementCase $case, User $user): void
{
    (new CaseAssignment)->forceFill([
        'case_id' => $case->id, 'role' => Role::Technocrat->value, 'purpose' => 'technical',
        'user_id' => $user->id, 'assigned_at' => now()->subMinute(),
    ])->save();
}

// 1. Filters --------------------------------------------------------------------------------

it('gives the CMO "All" and the four filters, each a WorkQueue step with WorkQueue\'s own count', function () {
    $cmo = caseUser(Role::Cmo);
    $queue = queueProp($this, $cmo);
    $workQueue = pageProps($this->actingAs($cmo)->get(route('dashboard')))['workQueue'];
    $groups = array_column($workQueue['groups'], 'count', 'key');

    expect(array_column($queue['filters'], 'label', 'key'))->toBe([
        'all' => 'All', 'intake' => 'New Submissions', 'hardcopy' => 'Hardcopy Pending',
        'returned' => 'Returned to CMO', 'letter' => 'Letters to Draft',
    ])
        ->and(chipCounts($queue))->toBe([
            'all' => count($workQueue['items']), 'intake' => $groups['intake'], 'hardcopy' => $groups['hardcopy'],
            'returned' => $groups['returned'], 'letter' => $groups['letter'],
        ])
        ->and($queue['filter'])->toBe('all')
        ->and(collect($queue['filters'])->firstWhere('key', 'all')['active'])->toBeTrue();
});

it('counts a new case in the CMO\'s chips, and shows its filter as a link carrying the search', function () {
    $cmo = caseUser(Role::Cmo);
    $before = chipCounts(queueProp($this, $cmo));
    dashCase(CaseStatus::SUBMITTED);

    $queue = queueProp($this, $cmo, ['q' => 'tube']);
    $after = chipCounts($queue);

    // A SUBMITTED case without a hardcopy is both a new submission and hardcopy pending.
    expect($after['intake'] - $before['intake'])->toBe(1)
        ->and($after['hardcopy'] - $before['hardcopy'])->toBe(1)
        ->and($after['all'] - $before['all'])->toBe(2)
        ->and(collect($queue['filters'])->firstWhere('key', 'intake')['href'])->toBe('/dashboard?filter=intake&q=tube')
        ->and(collect($queue['filters'])->firstWhere('key', 'all')['href'])->toBe('/dashboard?q=tube');
});

it('applies a filter on the server and marks it active', function () {
    $cmo = caseUser(Role::Cmo);
    dashCase(CaseStatus::NON_OBJECTION_GRANTED);

    $queue = queueProp($this, $cmo, ['filter' => 'letter']);

    expect($queue['filter'])->toBe('letter')
        ->and($queue['items'])->not->toBeEmpty()
        ->and(array_unique(array_column($queue['items'], 'step')))->toBe(['letter'])
        ->and(collect($queue['filters'])->where('active', true)->pluck('key')->all())->toBe(['letter']);
});

it('treats an unknown filter, or one the user does not hold, as All', function (string $role, string $filter) {
    $queue = queueProp($this, caseUser($role), ['filter' => $filter]);

    expect($queue['filter'])->toBe('all');
})->with([
    'unknown' => [Role::Cmo->value, 'nope'],
    'another step' => [Role::Cmo->value, 'technical'],
    'not held' => [Role::Technocrat->value, 'intake'],
]);

it('counts only an officer\'s own assigned cases, and shows them only "All"', function () {
    $technocrat = caseUser(Role::Technocrat);
    $before = chipCounts(queueProp($this, $technocrat));

    reviewAssign(dashCase(CaseStatus::IN_REVIEW, 'Dash item', dashHardcopy()), $technocrat);
    reviewAssign(dashCase(CaseStatus::IN_REVIEW, 'Dash item', dashHardcopy()), caseUser(Role::Technocrat)); // another technocrat's
    dashCase(CaseStatus::IN_REVIEW, 'Dash item', dashHardcopy());                                           // nobody's
    dashCase(CaseStatus::SUBMITTED);                                                                         // not theirs

    $after = chipCounts(queueProp($this, $technocrat));

    expect(array_keys($after))->toBe(['all'])
        ->and($after['all'] - $before['all'])->toBe(1);
});

it('never changes the chip counts with the search', function () {
    $cmo = caseUser(Role::Cmo);
    dashCase(CaseStatus::SUBMITTED);

    expect(chipCounts(queueProp($this, $cmo, ['q' => 'no-such-text-'.Str::random(12)])))->toBe(chipCounts(queueProp($this, $cmo)));
});

it('keeps "Needs My Action" equal to the queue\'s item count whatever the filter or search', function () {
    $cmo = caseUser(Role::Cmo);
    dashCase(CaseStatus::SUBMITTED);

    $props = pageProps($this->actingAs($cmo)->get(route('dashboard', ['filter' => 'intake', 'q' => 'zzz'])));
    $tile = collect($props['stats'])->firstWhere('key', 'needs_action');

    expect((int) str_replace(',', '', $tile['value']))->toBe(count($props['workQueue']['items']))
        ->and($props['queue']['total'])->toBe(count($props['workQueue']['items']));
});

// 2. Search -------------------------------------------------------------------------------------

it('finds queue rows by reference, item description and vendor organisation name', function (string $field) {
    $cmo = caseUser(Role::Cmo);
    $tag = Str::upper(Str::random(10));
    $org = Organisation::create(['name' => "Dash Vendor {$tag}", 'type' => 'VENDOR', 'active' => true]);
    $case = dashCase(CaseStatus::NON_OBJECTION_GRANTED, "Dash widget {$tag}", ['org_id' => $org->id, 'reference_no' => "NR-{$tag}"]);
    dashCase(CaseStatus::NON_OBJECTION_GRANTED); // not a match

    $term = match ($field) {
        'reference' => strtolower("nr-{$tag}"),
        'item' => "widget {$tag}",
        'organisation' => "vendor {$tag}",
    };
    $queue = queueProp($this, $cmo, ['q' => $term]);

    expect(array_unique(array_column($queue['items'], 'case_id')))->toBe([$case->id])
        ->and($queue['search'])->toBe($term);
})->with(['reference', 'item', 'organisation']);

it('combines the search with the filter', function () {
    $cmo = caseUser(Role::Cmo);
    $tag = Str::upper(Str::random(10));
    $case = dashCase(CaseStatus::SUBMITTED, "Dash combo {$tag}");

    $intake = queueProp($this, $cmo, ['filter' => 'intake', 'q' => $tag]);
    $letter = queueProp($this, $cmo, ['filter' => 'letter', 'q' => $tag]);

    expect(array_column($intake['items'], 'id'))->toBe(["{$case->id}:intake"])
        ->and($letter['items'])->toBe([]);
});

it('never reveals a case outside the user\'s own queue through the search', function () {
    $technocrat = caseUser(Role::Technocrat);
    $tag = Str::upper(Str::random(10));
    dashCase(CaseStatus::IN_REVIEW, "Dash secret {$tag}");                                         // not assigned
    reviewAssign(dashCase(CaseStatus::IN_REVIEW, "Dash secret {$tag}", dashHardcopy()), caseUser(Role::Technocrat)); // someone else's

    expect(queueProp($this, $technocrat, ['q' => $tag])['items'])->toBe([]);

    // Even given the ids directly, the visibility scope refuses them.
    $ids = ProcurementCase::withoutGlobalScopes()->whereHas('requisition', fn ($q) => $q->where('item_description', "Dash secret {$tag}"))->pluck('id')->all();
    expect(DashboardView::matchingCaseIds($technocrat, $ids, $tag))->toBe([]);
});

it('never matches a DRAFT, even given its id', function () {
    $cmo = caseUser(Role::Cmo);
    $tag = Str::upper(Str::random(10));
    $draft = dashCase(CaseStatus::DRAFT, "Dash draft {$tag}", ['submitted_at' => null, 'reference_no' => null]);

    expect(DashboardView::matchingCaseIds($cmo, [$draft->id], $tag))->toBe([])
        ->and(queueProp($this, $cmo, ['q' => $tag])['items'])->toBe([]);
});

it('treats %, _ and [ in the search as literal characters', function (string $search, string $hit, string $miss) {
    $cmo = caseUser(Role::Cmo);
    $tag = Str::upper(Str::random(8));
    $match = dashCase(CaseStatus::NON_OBJECTION_GRANTED, "Dash {$tag} {$hit}");
    $other = dashCase(CaseStatus::NON_OBJECTION_GRANTED, "Dash {$tag} {$miss}");

    expect(DashboardView::matchingCaseIds($cmo, [$match->id, $other->id], "{$tag} {$search}"))->toBe([$match->id]);
})->with([
    'percent' => ['100%', '100% pure', '1000 pure'],
    'underscore' => ['a_b', 'a_b', 'axb'],
    'bracket' => ['[1]', '[1]', '1'],
]);

it('escapes the same way as the vendor Requests search', function () {
    expect(SqlLike::containsPattern('50%_[x]\\'))->toBe('%50\\%\\_\\[x]\\\\%')
        ->and(SqlLike::contains('cases.reference_no', 'a'))->toBe(["cases.reference_no LIKE ? ESCAPE '\\'", ['%a%']]);
});

it('trims the search and cuts it at 100 characters', function () {
    $queue = queueProp($this, caseUser(Role::Cmo), ['q' => '  '.str_repeat('x', 150).'  ']);

    expect($queue['search'])->toBe(str_repeat('x', DashboardView::SEARCH_MAX));
});

it('sends no queue card to users without a queue', function (string $role) {
    expect(queueProp($this, caseUser($role)))->toBeNull();
})->with([Role::Auditor->value, Role::SystemAdmin->value]);
