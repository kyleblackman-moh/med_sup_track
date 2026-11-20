<?php

use App\Enums\CaseStatus;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Support\RequestGroups;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// UI-4: the vendor Requests page's count cards (RequestGroups), their filter,
// and the live search's LIKE escaping. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A request of $vendor's organisation in $status, with or without the hardcopy received. */
function groupedRequest(User $vendor, CaseStatus $status, bool $hardcopy = false, string $item = 'Group item'): ProcurementCase
{
    $case = makeCase([
        'org_id' => $vendor->org_id,
        'created_by' => $vendor->id,
        'status' => $status,
        'status_changed_at' => CarbonImmutable::now('UTC'),
        'reference_no' => $status === CaseStatus::DRAFT ? null : 'NR-UI4-'.strtoupper(bin2hex(random_bytes(4))),
        'submitted_at' => $status === CaseStatus::DRAFT ? null : now()->subDay(),
        // The three hardcopy columns are set together (ck_cases_hardcopy_all_or_none).
        ...($hardcopy ? ['hardcopy_received_on' => now()->toDateString(), 'hardcopy_received_at' => now(), 'hardcopy_received_by' => caseUser()->id] : []),
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => $item])->save();

    return $case;
}

/** The Requests page props for $vendor with $query. */
function requestsPage($test, User $vendor, array $query = []): array
{
    return pageProps($test->actingAs($vendor)->get(route('requests.index', $query))->assertOk());
}

function groupCounts(array $props): array
{
    return collect($props['groups'])->pluck('count', 'key')->all();
}

// 1. The mapping --------------------------------------------------------------------

it('puts each status in its group, the hardcopy deciding between Hardcopy due and In progress', function (CaseStatus $status, ?string $without, ?string $with) {
    expect(RequestGroups::of($status, false))->toBe($without)
        ->and(RequestGroups::of($status, true))->toBe($with);
})->with([
    [CaseStatus::DRAFT, 'drafts', 'drafts'],
    [CaseStatus::SUBMITTED, 'hardcopy_due', 'in_progress'],
    [CaseStatus::CMO_INTAKE, 'hardcopy_due', 'in_progress'],
    [CaseStatus::IN_REVIEW, 'hardcopy_due', 'in_progress'],
    [CaseStatus::READY_FOR_FILE, 'hardcopy_due', 'in_progress'],
    [CaseStatus::FILE_PREPARED, 'hardcopy_due', 'in_progress'],
    [CaseStatus::WITH_LEGAL, 'hardcopy_due', 'in_progress'],
    [CaseStatus::WITH_PS, 'hardcopy_due', 'in_progress'],
    [CaseStatus::RETURNED_TO_CMO, 'hardcopy_due', 'in_progress'],
    [CaseStatus::NON_OBJECTION_GRANTED, 'hardcopy_due', 'in_progress'], // the vendor still sees "Under review"
    [CaseStatus::LETTER_ISSUED, 'granted', 'granted'],
    [CaseStatus::CLOSED, 'granted', 'granted'],
    [CaseStatus::WITHDRAWN, null, null],
    [CaseStatus::REJECTED_FINAL, null, null],
]);

it('covers every status exactly once in the mapping', function () {
    foreach (CaseStatus::cases() as $status) {
        $listed = (int) ($status === CaseStatus::DRAFT)
            + (int) in_array($status, RequestGroups::OPEN, true)
            + (int) in_array($status, RequestGroups::GRANTED_STATUSES, true);
        expect($listed)->toBeLessThanOrEqual(1, $status->value);
    }
});

// 2. Counts and the card filter ------------------------------------------------------

it('counts the four groups for the vendor\'s own organisation only, in card order', function () {
    $vendor = vendorUser(vendorOrg());
    groupedRequest($vendor, CaseStatus::DRAFT);
    groupedRequest($vendor, CaseStatus::DRAFT);
    groupedRequest($vendor, CaseStatus::SUBMITTED);                  // hardcopy due
    groupedRequest($vendor, CaseStatus::IN_REVIEW, hardcopy: true);  // in progress
    groupedRequest($vendor, CaseStatus::WITH_PS, hardcopy: true);    // in progress
    groupedRequest($vendor, CaseStatus::LETTER_ISSUED, hardcopy: true);
    groupedRequest($vendor, CaseStatus::CLOSED, hardcopy: true);
    groupedRequest($vendor, CaseStatus::WITHDRAWN);                  // no card

    // Another organisation's requests are never counted.
    $other = vendorUser(vendorOrg());
    foreach ([CaseStatus::DRAFT, CaseStatus::SUBMITTED, CaseStatus::LETTER_ISSUED] as $status) {
        groupedRequest($other, $status);
    }

    $props = requestsPage($this, $vendor);

    expect($props['groups'])->toBe([
        ['key' => 'drafts', 'label' => 'Drafts', 'count' => 2],
        ['key' => 'in_progress', 'label' => 'In Progress', 'count' => 2],
        ['key' => 'hardcopy_due', 'label' => 'Hardcopy Due', 'count' => 1],
        ['key' => 'granted', 'label' => 'Granted', 'count' => 2],
    ]);
});

it('keeps the counts the same whatever the search text or card filter', function () {
    $vendor = vendorUser(vendorOrg());
    groupedRequest($vendor, CaseStatus::DRAFT, item: 'Feeding tube');
    groupedRequest($vendor, CaseStatus::SUBMITTED, item: 'Gauze roll');

    $all = groupCounts(requestsPage($this, $vendor));

    expect(groupCounts(requestsPage($this, $vendor, ['q' => 'gauze'])))->toBe($all)
        ->and(groupCounts(requestsPage($this, $vendor, ['group' => 'drafts'])))->toBe($all)
        ->and($all)->toBe(['drafts' => 1, 'in_progress' => 0, 'hardcopy_due' => 1, 'granted' => 0]);
});

it('filters the list by a card, with the search, and ignores an unknown group', function () {
    $vendor = vendorUser(vendorOrg());
    $draft = groupedRequest($vendor, CaseStatus::DRAFT, item: 'Feeding tube');
    $due = groupedRequest($vendor, CaseStatus::SUBMITTED, item: 'Feeding bottle');
    $granted = groupedRequest($vendor, CaseStatus::LETTER_ISSUED, hardcopy: true, item: 'Gauze roll');

    $ids = fn (array $query) => collect(requestsPage($this, $vendor, $query)['requests']['data'])->pluck('id')->sort()->values()->all();

    expect($ids(['group' => 'drafts']))->toBe([$draft->id])
        ->and($ids(['group' => 'hardcopy_due']))->toBe([$due->id])
        ->and($ids(['group' => 'granted']))->toBe([$granted->id])
        ->and($ids(['group' => 'in_progress']))->toBe([])
        ->and($ids(['group' => 'hardcopy_due', 'q' => 'feeding']))->toBe([$due->id])
        ->and($ids(['group' => 'drafts', 'q' => 'gauze']))->toBe([])
        ->and($ids(['group' => 'nonsense']))->toBe(collect([$draft->id, $due->id, $granted->id])->sort()->values()->all())
        ->and(requestsPage($this, $vendor, ['group' => 'nonsense'])['filters'])->toBe(['q' => '', 'group' => '']);
});

it('skips the count query on a partial reload that does not ask for it', function () {
    $vendor = vendorUser(vendorOrg());
    groupedRequest($vendor, CaseStatus::DRAFT);
    $version = $this->actingAs($vendor)->get(route('requests.index'))->viewData('page')['version'];

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->actingAs($vendor)->get(route('requests.index', ['q' => 'group']), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'requests/index',
        'X-Inertia-Partial-Data' => 'requests,filters',
    ])->assertOk()->assertJsonMissingPath('props.groups');

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'request_group'))->all())->toBe([]);
});

// 3. Live search: SQL Server LIKE escaping ---------------------------------------------

it('treats %, _ and [ in the search as literal characters', function (string $term, array $matches) {
    $vendor = vendorUser(vendorOrg());
    $rows = [
        'plain' => groupedRequest($vendor, CaseStatus::DRAFT, item: 'Saline bag'),
        'percent' => groupedRequest($vendor, CaseStatus::DRAFT, item: 'Saline 0.9% bag'),
        'underscore' => groupedRequest($vendor, CaseStatus::DRAFT, item: 'Saline_bag'),
        'bracket' => groupedRequest($vendor, CaseStatus::DRAFT, item: 'Saline [large] bag'),
    ];

    $found = collect(requestsPage($this, $vendor, ['q' => $term])['requests']['data'])->pluck('id')->sort()->values()->all();

    expect($found)->toBe(collect($matches)->map(fn ($key) => $rows[$key]->id)->sort()->values()->all());
})->with([
    'a lone % does not match every row' => ['%', ['percent']],
    'a lone _ is not a wildcard' => ['_', ['underscore']],
    'a lone [ does not error' => ['[', ['bracket']],
    'a bracket pair is literal' => ['[large]', ['bracket']],
    'contains, from the first character' => ['s', ['plain', 'percent', 'underscore', 'bracket']],
]);
