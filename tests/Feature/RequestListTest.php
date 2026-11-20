<?php

use App\Enums\CaseStatus;
use App\Http\Controllers\RequestController;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// UI-3: the vendor Requests list (title, columns, live search). DatabaseTransactions.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A request of $code for $vendor with these requisition fields, updated $minutesAgo minutes ago. */
function listedRequest(User $vendor, string $code, array $fields = [], ?string $reference = null, int $minutesAgo = 0): ProcurementCase
{
    [$case] = draftOf($code, $vendor);
    DB::table('case_requisitions')->where('case_id', $case->id)->update($fields);
    DB::table('cases')->where('id', $case->id)->update([
        'reference_no' => $reference,
        'status' => $reference === null ? CaseStatus::DRAFT->value : CaseStatus::SUBMITTED->value,
        'updated_at' => CarbonImmutable::now('UTC')->subMinutes($minutesAgo),
    ]);

    return $case;
}

function listIds($test, User $vendor, ?string $q = null): array
{
    $response = $test->actingAs($vendor)->get(route('requests.index', $q === null ? [] : ['q' => $q]))->assertOk();

    return array_column(pageProps($response)['requests']['data'], 'id');
}

function searchFixture(): array
{
    $vendor = vendorUser(vendorOrg());
    $tube = listedRequest($vendor, 'AD_HOC_TENDER_UNABLE', ['item_description' => 'Infant feeding tube', 'strength' => '6Fr', 'din' => '02345678'], 'NOR-2026-0142', 30);
    $insulin = listedRequest($vendor, 'TENDER_EXCESS', ['item_description' => 'Insulin glargine', 'strength' => '100 units/mL', 'din' => '09998887'], 'NOR-2026-0007', 20);
    $draft = listedRequest($vendor, 'AD_HOC_TENDER_UNABLE', ['item_description' => 'Gauze 100% cotton_roll [large]'], null, 10);

    return [$vendor, $tube, $insulin, $draft];
}

// 1. Search ----------------------------------------------------------------------

it('finds a request by its reference, in part and in any case', function (string $q) {
    [$vendor, $tube] = searchFixture();

    expect(listIds($this, $vendor, $q))->toBe([$tube->id]);
})->with(['NOR-2026-0142', '0142', 'nor-2026-014']);

it('finds requests by partial item text, strength, DIN and type label', function (string $q, string $which) {
    [$vendor, $tube, $insulin, $draft] = searchFixture();
    $cases = compact('tube', 'insulin', 'draft');

    expect(listIds($this, $vendor, $q))->toBe([$cases[$which]->id]);
})->with([
    'item, partial' => ['feeding', 'tube'],
    'item, other case' => ['GLARGINE', 'insulin'],
    'strength' => ['units/ml', 'insulin'],
    'din' => ['0999888', 'insulin'],
    'type label' => ['tender - exc', 'insulin'],
]);

it('matches the type label for every request of that type', function () {
    [$vendor, $tube, , $draft] = searchFixture();

    expect(listIds($this, $vendor, 'ad hoc'))->toBe([$draft->id, $tube->id]);
});

it('returns an empty list when nothing matches', function () {
    [$vendor] = searchFixture();

    $props = pageProps($this->actingAs($vendor)->get(route('requests.index', ['q' => 'no such thing']))->assertOk());

    expect($props['requests']['data'])->toBe([])
        ->and($props['requests']['total'])->toBe(0)
        ->and($props['filters'])->toBe(['q' => 'no such thing', 'group' => '']); // UI-4: the card filter, none here
});

it('treats LIKE wildcard characters literally', function (string $q, bool $matchesDraft) {
    [$vendor, , , $draft] = searchFixture();

    expect(listIds($this, $vendor, $q))->toBe($matchesDraft ? [$draft->id] : []);
})->with([
    'percent present' => ['100% cotton', true],
    'percent as wildcard' => ['Gauze%roll', false],
    'underscore present' => ['cotton_roll', true],
    'underscore as wildcard' => ['cotton_r_ll', false],
    'bracket present' => ['[large]', true],
    'bracket as class' => ['[a-z]arge', false],
    'lone percent' => ['%', true],
    'lone underscore' => ['_', true],
]);

it('trims the search and cuts it at 100 characters', function () {
    [$vendor, $tube] = searchFixture();

    expect(listIds($this, $vendor, '   feeding   '))->toBe([$tube->id]);

    $props = pageProps($this->actingAs($vendor)->get(route('requests.index', ['q' => str_repeat('x', 150)]))->assertOk());
    expect(mb_strlen($props['filters']['q']))->toBe(RequestController::SEARCH_MAX)
        ->and(RequestController::SEARCH_MAX)->toBe(100);
});

it('never lists another organisation\'s requests, whatever the search', function (?string $q) {
    [$vendor] = searchFixture();
    $other = vendorUser(vendorOrg());
    $theirs = listedRequest($other, 'AD_HOC_TENDER_UNABLE', ['item_description' => 'Infant feeding tube', 'din' => '02345678'], 'NOR-2026-0999');

    expect(listIds($this, $vendor, $q))->not->toContain($theirs->id)
        ->and(listIds($this, $other, $q))->toBe($q === null || $q !== 'Insulin' ? [$theirs->id] : []);
})->with([null, 'feeding', '02345678', 'NOR-2026', 'Insulin']);

it('keeps the newest-updated-first order, with and without a search', function () {
    [$vendor, $tube, $insulin, $draft] = searchFixture();

    expect(listIds($this, $vendor))->toBe([$draft->id, $insulin->id, $tube->id])
        ->and(listIds($this, $vendor, 'NOR-2026'))->toBe([$insulin->id, $tube->id]);
});

it('keeps the search in the paging links', function () {
    $vendor = vendorUser(vendorOrg());
    foreach (range(1, 21) as $i) {
        listedRequest($vendor, 'AD_HOC_TENDER_UNABLE', ['item_description' => "Feeding tube {$i}"], null, $i);
    }

    $props = pageProps($this->actingAs($vendor)->get(route('requests.index', ['q' => 'feeding']))->assertOk());

    expect($props['requests']['last_page'])->toBe(2)
        ->and($props['requests']['next_page_url'])->toContain('q=feeding');
});

// 2. Columns and title ---------------------------------------------------------------

it('sends each row with exactly the list columns', function () {
    [$vendor] = searchFixture();

    $rows = pageProps($this->actingAs($vendor)->get(route('requests.index'))->assertOk())['requests']['data'];

    expect(array_keys($rows[0]))->toBe([
        'id', 'reference_no', 'status_label', 'status_group', 'status_tone', 'procurement_type',
        'item_description', 'vendor_reference', 'updated_at', 'can',
    ])
        ->and(collect($rows)->firstWhere('reference_no', null))->not->toBeNull(); // drafts: "Not assigned" on screen
});

it('titles the list with the organisation short name, else its name', function (?string $shortName, string $title) {
    $org = Organisation::create(['name' => 'Caribbean Medical Supplies Ltd', 'short_name' => $shortName, 'type' => 'VENDOR', 'active' => true]);
    $vendor = vendorUser($org);

    $props = pageProps($this->actingAs($vendor)->get(route('requests.index'))->assertOk());

    expect($props['title'])->toBe($title)
        ->and(RequestController::LIST_TITLE)->toBe('{organisation} - Requests');
})->with([
    'short name' => ['CMS', 'CMS - Requests'],
    'no short name' => [null, 'Caribbean Medical Supplies Ltd - Requests'],
    'blank short name' => ['  ', 'Caribbean Medical Supplies Ltd - Requests'],
]);
