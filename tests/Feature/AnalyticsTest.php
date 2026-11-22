<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Http\Resources\Dashboard\AnalyticsCharts;
use App\Http\Resources\Dashboard\DashboardDrafts;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// ANALYTICS: charts and tracking on their own page, for the CMO's office and
// the PS office (case.view_all) only; the dashboard keeps the work queue and,
// for users who submit requests, their own drafts. The dev database holds
// other rows, so counts are compared before and after (DatabaseTransactions only).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function analyticsCase(S $status, array $attributes = []): ProcurementCase
{
    return makeCase([
        'status' => $status,
        'reference_no' => 'NR-AN-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subMinute(),
        ...$attributes,
    ]);
}

function analyticsTransition(ProcurementCase $case, S $from, S $to, ?CarbonImmutable $at = null): void
{
    (new CaseTransition)->forceFill([
        'case_id' => $case->id, 'from_status' => $from, 'to_status' => $to,
        'created_at' => $at ?? CarbonImmutable::now('UTC')->subMinute(),
    ])->save();
}

/** Each series of the charts summed over its buckets. @return array<string, int> */
function chartTotals(User $user): array
{
    $charts = AnalyticsCharts::for($user);

    return [
        'received' => array_sum($charts['weekly']['received']),
        'weekly_granted' => array_sum($charts['weekly']['granted']),
        'granted' => array_sum($charts['outcomes']['granted']),
        'rejected' => array_sum($charts['outcomes']['rejected']),
        'withdrawn' => array_sum($charts['outcomes']['withdrawn']),
    ];
}

// Access ----------------------------------------------------------------------------------------

it('opens the Analytics page for the CMO and the PS office with every prop', function (Role $role) {
    $response = $this->actingAs(caseUser($role))->get(route('analytics'))->assertOk();
    $props = pageProps($response);

    expect($response->viewData('page')['component'])->toBe('analytics')
        ->and($props)->toHaveKeys(['stats', 'pipeline', 'gateTracker', 'charts'])
        ->and($props['charts']['weekly'])->toHaveKeys(['labels', 'received', 'granted'])
        ->and($props['charts']['weekly']['labels'])->toHaveCount(12)
        ->and($props['charts']['weekly']['received'])->toHaveCount(12)
        ->and($props['charts']['weekly']['granted'])->toHaveCount(12)
        ->and($props['charts']['outcomes'])->toHaveKeys(['labels', 'granted', 'rejected', 'withdrawn'])
        ->and($props['charts']['outcomes']['labels'])->toHaveCount(6)
        ->and($props['charts']['outcomes']['granted'])->toHaveCount(6)
        ->and($props['charts']['outcomes']['rejected'])->toHaveCount(6)
        ->and($props['charts']['outcomes']['withdrawn'])->toHaveCount(6);
})->with([Role::Cmo, Role::PsOffice]);

it('refuses the Analytics page with 403 to everyone without case.view_all', function (Role $role) {
    $user = $role === Role::VendorApplicant ? vendorUser(vendorOrg()) : caseUser($role);

    $this->actingAs($user)->get(route('analytics'))->assertForbidden();
})->with([Role::Technocrat, Role::CabOfficer, Role::LegalOfficer, Role::CmoRegistry, Role::VendorApplicant]);

// IT-ADMIN: accounts with no case work at all get 404 on the whole case area (EnsureCaseAreaAccess).
it('answers 404 on the Analytics page to accounts with no case work', function (Role $role) {
    $this->actingAs(caseUser($role))->get(route('analytics'))->assertNotFound();
})->with([Role::SystemAdmin, Role::Auditor]);

it('sends guests to sign in', function () {
    $this->get(route('analytics'))->assertRedirect(route('login'));
});

// Charts ----------------------------------------------------------------------------------------

it('labels 12 Monday weeks and 6 months in Port of Spain time, oldest first', function () {
    // Friday 20 November 2026, 02:00 UTC is Thursday 19 November, 22:00 in Port of Spain.
    $charts = AnalyticsCharts::for(caseUser(Role::Cmo), CarbonImmutable::parse('2026-11-20 02:00:00', 'UTC'));

    expect($charts['weekly']['labels'])->toBe([
        '31 Aug', '7 Sep', '14 Sep', '21 Sep', '28 Sep', '5 Oct',
        '12 Oct', '19 Oct', '26 Oct', '2 Nov', '9 Nov', '16 Nov',
    ])->and($charts['outcomes']['labels'])->toBe(['Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026', 'Nov 2026']);
});

it('counts a submitted case as received, never a draft', function () {
    $cmo = caseUser(Role::Cmo);
    $before = chartTotals($cmo);

    analyticsCase(S::SUBMITTED);
    analyticsCase(S::DRAFT, ['submitted_at' => null]);
    analyticsCase(S::SUBMITTED, ['submitted_at' => CarbonImmutable::now('UTC')->subWeeks(20)]); // out of range

    expect(chartTotals($cmo)['received'] - $before['received'])->toBe(1);

    $props = pageProps($this->actingAs($cmo)->get(route('analytics'))->assertOk());
    expect(array_sum($props['charts']['weekly']['received']))->toBe(chartTotals($cmo)['received']);
});

it('counts granted, rejected and withdrawn outcomes from case_transitions', function () {
    $cmo = caseUser(Role::Cmo);
    $before = chartTotals($cmo);

    analyticsTransition(analyticsCase(S::NON_OBJECTION_GRANTED), S::WITH_PS, S::NON_OBJECTION_GRANTED);
    analyticsTransition(analyticsCase(S::REJECTED_FINAL), S::WITH_PS, S::REJECTED_FINAL);
    analyticsTransition(analyticsCase(S::WITHDRAWN), S::SUBMITTED, S::WITHDRAWN);
    analyticsTransition(analyticsCase(S::WITH_LEGAL), S::READY_FOR_FILE, S::WITH_LEGAL); // not an outcome
    analyticsTransition(analyticsCase(S::NON_OBJECTION_GRANTED), S::WITH_PS, S::NON_OBJECTION_GRANTED,
        CarbonImmutable::now('UTC')->subMonths(8)); // out of range

    $after = chartTotals($cmo);

    expect($after['granted'] - $before['granted'])->toBe(1)
        ->and($after['weekly_granted'] - $before['weekly_granted'])->toBe(1)
        ->and($after['rejected'] - $before['rejected'])->toBe(1)
        ->and($after['withdrawn'] - $before['withdrawn'])->toBe(1);
});

it('counts only the cases the user can see', function () {
    $technocrat = caseUser(Role::Technocrat);
    $before = chartTotals($technocrat);

    $mine = analyticsCase(S::IN_REVIEW);
    assign($mine, Role::Technocrat, $technocrat);
    analyticsCase(S::IN_REVIEW); // not assigned
    analyticsTransition(analyticsCase(S::WITHDRAWN), S::SUBMITTED, S::WITHDRAWN); // not assigned

    $after = chartTotals($technocrat);

    expect($after['received'] - $before['received'])->toBe(1)
        ->and($after['withdrawn'] - $before['withdrawn'])->toBe(0);
});

// Dashboard drafts ------------------------------------------------------------------------------

it('gives a vendor its own organisation\'s drafts on the dashboard, and nothing else', function () {
    [$draft, $vendor] = newDraft('TENDER_EXCESS');
    [$otherDraft] = newDraft('TENDER_EXCESS'); // another organisation's draft
    $submitted = analyticsCase(S::SUBMITTED, ['org_id' => $vendor->org_id, 'created_by' => $vendor->id]);

    $props = pageProps($this->actingAs($vendor)->get(route('dashboard'))->assertOk());
    $drafts = $props['drafts'];
    $ids = array_column($drafts['items'], 'id');

    expect($ids)->toContain($draft->id)
        ->and($ids)->not->toContain($otherDraft->id)
        ->and($ids)->not->toContain($submitted->id)
        ->and($drafts['total'])->toBe(1)
        ->and($drafts['requests_href'])->toBe('/requests?group=drafts')
        ->and($props)->not->toHaveKey('stats')
        ->and($props)->not->toHaveKey('pipeline')
        ->and($props)->not->toHaveKey('gateTracker');

    $item = collect($drafts['items'])->firstWhere('id', $draft->id);
    expect(array_keys($item))->toBe(['id', 'reference', 'title', 'type', 'updated', 'edit_href'])
        ->and($item['title'])->toBe($item['type'] ?? DashboardDrafts::UNTITLED)
        ->and($item['edit_href'])->toBe('/requests/'.$draft->id.'/edit')
        ->and($item['updated'])->toMatch('/^\d{1,2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} (am|pm)$/');

    expectVendorSafe($drafts);
});

it('gives staff no drafts card', function (Role $role) {
    $props = pageProps($this->actingAs(caseUser($role))->get(route('dashboard'))->assertOk());

    expect($props['drafts'])->toBeNull();
})->with([Role::Cmo, Role::PsOffice, Role::Technocrat, Role::CabOfficer, Role::LegalOfficer]);

// Navigation ------------------------------------------------------------------------------------

it('shares nav.analytics only with case.view_all holders, never under can', function (Role $role, bool $expected) {
    $user = $role === Role::VendorApplicant ? vendorUser(vendorOrg()) : caseUser($role);
    $props = pageProps($this->actingAs($user)->get(route('dashboard'))->assertOk());

    expect($props['nav']['analytics'])->toBe($expected)
        ->and($props['can'])->not->toHaveKey('analytics');
})->with([
    [Role::Cmo, true],
    [Role::PsOffice, true],
    [Role::Technocrat, false],
    [Role::CabOfficer, false],
    [Role::LegalOfficer, false],
    [Role::CmoRegistry, false],
    [Role::VendorApplicant, false],
    [Role::SystemAdmin, false],
]);
