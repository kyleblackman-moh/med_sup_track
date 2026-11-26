<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Http\Resources\Dashboard\DashboardView;
use App\Models\CaseAssignment;
use App\Models\CaseRequisition;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\QueryRoute;
use App\Models\User;
use App\Queries\QueryService;
use App\Support\SqlLike;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
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

it('gives the CMO "All" and the five filters, each with WorkQueue\'s own count', function () {
    $cmo = caseUser(Role::Cmo);
    $queue = queueProp($this, $cmo);
    $workQueue = pageProps($this->actingAs($cmo)->get(route('dashboard')))['workQueue'];
    $groups = array_column($workQueue['groups'], 'count', 'key');

    expect(array_column($queue['filters'], 'label', 'key'))->toBe([
        'all' => 'All', 'intake' => 'New Submissions', 'hardcopy' => 'Hardcopy Pending',
        'returned' => 'Returned to CMO', 'letter' => 'Letters to Draft', 'queries' => 'Queries',
    ])
        ->and(chipCounts($queue))->toBe([
            'all' => count($workQueue['items']), 'intake' => $groups['intake'], 'hardcopy' => $groups['hardcopy'],
            'returned' => $groups['returned'], 'letter' => $groups['letter'],
            // FIX-2 (2): the CMO's desk always has Query to Forward; Query to Answer only when due.
            'queries' => $groups['query_forward'] + ($groups['query_answer'] ?? 0),
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

it('FIX-2: counts Forward Query and Answer Query rows in the Queries chip, and filters to them', function () {
    $this->seed(QueryRouteSeeder::class);
    $cmo = caseUser(Role::Cmo);
    $technocrat = caseUser(Role::Technocrat);
    $before = chipCounts(queueProp($this, $cmo));

    // A direct Technocrat question to the CMO: an Answer Query row for the CMO.
    $case = dashCase(CaseStatus::IN_REVIEW, 'Dash item', dashHardcopy());
    reviewAssign($case, $technocrat);
    $route = QueryRoute::query()->where('from_role', 'technocrat')->where('to_role', 'cmo')->firstOrFail();
    $this->actingAs($technocrat);
    app(QueryService::class)->raise($case, $technocrat, (int) $route->id, 'A dashboard question');

    $queue = queueProp($this, $cmo, ['filter' => 'queries']);
    $after = chipCounts($queue);
    $groups = array_column(pageProps($this->actingAs($cmo)->get(route('dashboard')))['workQueue']['groups'], 'count', 'key');

    expect($after['queries'] - $before['queries'])->toBe(1)
        ->and($after['queries'])->toBe($groups['query_forward'] + ($groups['query_answer'] ?? 0))
        ->and($queue['filter'])->toBe('queries')
        ->and(count($queue['items']))->toBe($after['queries'])
        ->and(array_values(array_diff(array_unique(array_column($queue['items'], 'step')), ['query_forward', 'query_answer'])))->toBe([])
        ->and(collect($queue['items'])->where('case_id', $case->id)->pluck('action')->all())->toBe(['Answer Query'])
        ->and(collect($queue['filters'])->firstWhere('key', 'queries')['href'])->toBe('/dashboard?filter=queries');

    // The Technocrat holds no query work now: no Queries chip.
    expect(array_keys(chipCounts(queueProp($this, $technocrat))))->not->toContain('queries');
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

it('keeps the queue total equal to the queue\'s item count whatever the filter or search', function () {
    $cmo = caseUser(Role::Cmo);
    dashCase(CaseStatus::SUBMITTED);

    $props = pageProps($this->actingAs($cmo)->get(route('dashboard', ['filter' => 'intake', 'q' => 'zzz'])));

    expect($props['queue']['total'])->toBe(count($props['workQueue']['items']));
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

// Backlog, 2026-11-25: every staff user gets the card; without queue permissions it is empty.
it('sends an empty queue card to staff without queue permissions', function (string $role) {
    $queue = queueProp($this, caseUser($role));

    expect($queue['items'])->toBe([])
        ->and($queue['total'])->toBe(0)
        ->and(array_column($queue['filters'], 'key'))->toBe(['all']);
})->with([Role::Auditor->value, Role::SystemAdmin->value]);

// 3. Rows, tags and empty states ----------------------------------------------------------------

/** The rows of $case in $user's queue card. */
function queueRowsOf($test, User $user, ProcurementCase $case): array
{
    $queue = queueProp($test, $user, ['q' => $case->reference_no]);

    return array_values(array_filter($queue['items'], fn (array $row) => $row['case_id'] === $case->id));
}

it('builds each row on the server: link, type, plain waiting text and the action label', function () {
    $cmo = caseUser(Role::Cmo);
    $case = dashCase(CaseStatus::NON_OBJECTION_GRANTED, 'Dash row item');

    [$row] = queueRowsOf($this, $cmo, $case);

    expect($row)->toMatchArray([
        'href' => "/cases/{$case->id}",
        'type_label' => testProcurementType()->name,
        'action' => 'Issue Letter',
        'item_description' => 'Dash row item',
        'status_label' => 'CMO to Action',
        'status_tone' => 'indigo',
        'tags' => [],
    ])->and($row['waiting'])->toMatch('/^[\d,]+ days$/');
});

it('words the waiting time as plain text', function (?int $days, string $text) {
    expect(DashboardView::waiting($days))->toBe($text);
})->with([[null, '—'], [0, 'Today'], [1, '1 day'], [12, '12 days'], [1500, '1,500 days']]);

it('tags a returned case, naming who returned it when the return says so', function (?string $from, string $label) {
    $cmo = caseUser(Role::Cmo);
    $case = dashCase(CaseStatus::RETURNED_TO_CMO, 'Dash returned', dashHardcopy());
    if ($from !== null) {
        DB::table('case_transitions')->insert([
            'case_id' => $case->id, 'from_status' => $from, 'to_status' => 'RETURNED_TO_CMO',
            'actor_id' => $cmo->id, 'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    [$row] = queueRowsOf($this, $cmo, $case);

    expect($row['tags'])->toBe([['key' => 'returned', 'label' => $label, 'tone' => 'warning', 'icon' => 'undo']]);
})->with([
    'by Legal' => ['WITH_LEGAL', 'Returned by Legal'],
    'by the PS office' => ['WITH_PS', 'Returned by PS Office'],
    'source unknown' => [null, 'Returned'],
]);

it('tags a case still waiting for its hardcopy, and an Emergency request by its type', function () {
    $this->seed(ProcurementTypeSeeder::class);
    $cmo = caseUser(Role::Cmo);
    $emergency = ProcurementType::where('code', 'AD_HOC_EMERGENCY')->firstOrFail();
    $case = dashCase(CaseStatus::SUBMITTED, 'Dash tags', ['procurement_type_id' => $emergency->id]);

    $rows = collect(queueRowsOf($this, $cmo, $case))->keyBy('step');

    expect(array_column($rows['intake']['tags'], 'label'))->toBe(['Hardcopy Pending', 'Emergency'])
        ->and($rows['intake']['type_label'])->toBe('Ad Hoc - Emergency')
        ->and(collect($rows['intake']['tags'])->pluck('tone')->unique()->all())->toBe(['neutral']);

    // Once the hardcopy is in, the tag goes.
    DB::table('cases')->where('id', $case->id)->update(['hardcopy_received_on' => now()->toDateString(), 'hardcopy_received_at' => now(), 'hardcopy_received_by' => $cmo->id]);
    expect(array_column(queueRowsOf($this, $cmo, $case)[0]['tags'], 'label'))->toBe(['Emergency']);
});

it('says "No task to complete." when the queue is empty', function () {
    // A new technocrat has no assignments, so nothing is theirs.
    $queue = queueProp($this, caseUser(Role::Technocrat));

    expect($queue['total'])->toBe(0)
        ->and($queue['items'])->toBe([])
        ->and($queue['sections'])->toBe([])
        ->and($queue['empty'])->toBe(['message' => 'No task to complete.', 'clear' => false]);
});

it('says "No tasks match your search." with a clear link when nothing matches', function () {
    $cmo = caseUser(Role::Cmo);
    dashCase(CaseStatus::SUBMITTED);

    $queue = queueProp($this, $cmo, ['q' => 'no-such-text-'.Str::random(12)]);

    expect($queue['items'])->toBe([])
        ->and($queue['empty'])->toBe(['message' => 'No tasks match your search.', 'clear' => true])
        ->and($queue['clear_href'])->toBe('/dashboard');
});

// 4. Static guards ---------------------------------------------------------------------------------

/** The dashboard's own sources. */
function dashboardSources(): array
{
    return [
        app_path('Http/Resources/Dashboard/DashboardView.php'),
        app_path('Http/Controllers/DashboardController.php'),
        resource_path('js/pages/dashboard.tsx'),
        resource_path('js/components/dashboard/work-queue.tsx'),
    ];
}

/** Every string literal and JSX text in $source (what a user can see), comments removed. */
function visibleText(string $source): array
{
    $code = preg_replace(['#/\*.*?\*/#s', '#(?<![:"\'])//[^\n]*#'], '', $source);
    preg_match_all('/\'(?:[^\'\\\n]|\\.)*\'|"(?:[^"\\\n]|\\.)*"|`[^`]*`|>([^<>{}]+)</', $code, $matches);

    return $matches[0];
}

it('shows no SLA wording anywhere on the dashboard: no Overdue, Urgent, Late or target', function () {
    foreach (dashboardSources() as $file) {
        foreach (visibleText(file_get_contents($file)) as $text) {
            expect($text)->not->toMatch('/\b(overdue|urgent|late|targets?)\b/i', basename($file).': '.$text);
        }
    }
});

it('sends no SLA values with the rows', function () {
    $cmo = caseUser(Role::Cmo);
    dashCase(CaseStatus::SUBMITTED);

    foreach (queueProp($this, $cmo)['items'] as $row) {
        expect(array_keys($row))->not->toContain('target', 'overdue', 'is_late', 'tone_waiting', 'urgent')
            ->and($row['waiting'])->toMatch('/^(—|Today|1 day|[\d,]+ days)$/');
    }
});

it('builds the queue table from real table cells, with a stretched row link and the same link on the action', function () {
    $source = file_get_contents(resource_path('js/components/dashboard/work-queue.tsx'));

    // OVERNIGHT-3 (B2): the shared DataTable (scope="col", --subtle header, overflow-x-auto).
    // Kyle, 2026-11-26: the desk headings replace the filter chips.
    expect($source)->toContain('<DataTableHeaderCell')
        ->toContain("const HEADERS = ['Reference', 'Request', 'Status', 'Waiting', 'Action'] as const;")
        ->toContain('<DataTableRow key={item.id} linked>')
        ->toContain('<DataTable')
        ->toContain('h-14')
        ->toContain('cn(dataTableRefClass, rowLink)')
        ->toContain('relative z-10')
        ->toContain('queue.sections.map')
        ->not->toContain('<FilterChips')
        ->toContain('min-w-[52rem] lg:min-w-0')
        ->not->toContain('fetch(')->not->toContain('axios')->not->toContain('localStorage')
        ->not->toContain('item.days_waiting'); // the waiting text comes from the server
});
