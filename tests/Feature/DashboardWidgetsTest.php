<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseRequisition;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// The one dashboard template (App\Support\Dashboard): widgets chosen by
// permission, counts scoped by case visibility, vendor widgets vendor-safe.
// The dev database holds other rows, so assertions look at shapes, keys and
// this test's cases only, never at absolute totals.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function widgetCase(array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'status' => CaseStatus::SUBMITTED,
        'submitted_at' => now()->subDays(3),
        'reference_no' => 'NR-DW-'.Str::upper(Str::random(8)),
        ...$attributes,
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Widget item'])->save();

    return $case;
}

/** @return array<string, mixed> */
function widgetsFor(User $user): array
{
    return pageProps(test()->actingAs($user)->get(route('dashboard'))->assertOk())['widgets'];
}

/** @return list<string> */
function kpiKeys(array $widgets): array
{
    return array_column($widgets['kpis'], 'key');
}

it('gives every staff dashboard the same widget set', function (Role $role) {
    $widgets = widgetsFor(caseUser($role));

    expect($widgets['kind'])->toBe('staff')
        ->and($widgets)->toHaveKeys(['kpis', 'stages', 'gate', 'activity'])
        ->and(array_slice(kpiKeys($widgets), 0, 2))->toBe(['waiting', 'oldest'])
        ->and(array_column($widgets['stages']['items'], 'key'))->toContain(CaseStatus::IN_REVIEW->value)
        ->and(array_column($widgets['stages']['items'], 'key'))->not->toContain(CaseStatus::DRAFT->value);
})->with([Role::Cmo, Role::Technocrat, Role::CabOfficer, Role::LegalOfficer, Role::PsOffice]);

it('chooses the desk tiles by permission', function (Role $role, array $tail) {
    expect(array_slice(kpiKeys(widgetsFor(caseUser($role))), 2))->toBe($tail);
})->with([
    'CMO' => [Role::Cmo, ['in_review', 'letters']],
    'Technocrat' => [Role::Technocrat, ['assigned', 'recorded']],
    'CAB' => [Role::CabOfficer, ['assigned', 'recorded']],
    'Legal' => [Role::LegalOfficer, ['with_you', 'returned']],
    'PS' => [Role::PsOffice, ['granted', 'returned']],
]);

it('shows the gate tracker only to the file-preparing desk', function () {
    $case = widgetCase([
        'status' => CaseStatus::IN_REVIEW,
        'submitted_at' => now()->subYears(20),
        'technical_track' => TrackStatus::FAVOURABLE,
        'funding_track' => TrackStatus::PENDING,
    ]);

    $gate = widgetsFor(caseUser(Role::Cmo))['gate'];
    $row = collect($gate['items'])->firstWhere('case_id', $case->id);

    expect($row)->not->toBeNull()
        ->and($row['technical'])->toBeTrue()
        ->and($row['funding'])->toBeFalse()
        ->and($row['hardcopy'])->toBeFalse()
        ->and($row['waiting_on'])->toBe(['CAB', 'Registry'])
        ->and(widgetsFor(caseUser(Role::Technocrat))['gate'])->toBeNull();
});

it('counts a reviewer\'s stages from their assigned cases only', function () {
    $reviewer = caseUser(Role::Technocrat);
    $mine = widgetCase(['status' => CaseStatus::IN_REVIEW]);
    assign($mine, Role::Technocrat, $reviewer)->forceFill(['purpose' => 'technical'])->save();
    widgetCase(['status' => CaseStatus::IN_REVIEW]); // not assigned: invisible to them

    $widgets = widgetsFor($reviewer);
    $inReview = collect($widgets['stages']['items'])->firstWhere('key', CaseStatus::IN_REVIEW->value);

    expect($widgets['stages']['scope'])->toBe('assigned')
        ->and($inReview['count'])->toBe(1)
        ->and($inReview['mine'])->toBeTrue()
        ->and(collect($widgets['kpis'])->firstWhere('key', 'assigned')['value'])->toBe(1)
        ->and(widgetsFor(caseUser(Role::Cmo))['stages']['scope'])->toBe('all');
});

it('lists activity without reasons or other typed text', function () {
    $case = widgetCase(['status' => CaseStatus::RETURNED_TO_CMO]);
    (new CaseTransition)->forceFill([
        'case_id' => $case->id,
        'from_status' => CaseStatus::WITH_LEGAL,
        'to_status' => CaseStatus::RETURNED_TO_CMO,
        'actor_id' => caseUser(Role::LegalOfficer)->id,
        'reason' => 'Secret typed reason',
        'created_at' => now()->addMinute(),
    ])->save();

    $row = collect(widgetsFor(caseUser(Role::Cmo))['activity'])->firstWhere('case_id', $case->id);

    expect($row)->not->toBeNull()
        ->and($row)->not->toHaveKey('reason')
        ->and($row['to_label'])->toBe(CaseStatus::RETURNED_TO_CMO->label())
        ->and(json_encode($row))->not->toContain('Secret typed reason');
});

it('counts a Legal officer\'s returns this month from their own transitions', function () {
    $legal = caseUser(Role::LegalOfficer);
    $case = widgetCase(['status' => CaseStatus::RETURNED_TO_CMO]);
    (new CaseTransition)->forceFill([
        'case_id' => $case->id,
        'from_status' => CaseStatus::WITH_LEGAL,
        'to_status' => CaseStatus::RETURNED_TO_CMO,
        'actor_id' => $legal->id,
        'created_at' => now(),
    ])->save();

    expect(collect(widgetsFor($legal)['kpis'])->firstWhere('key', 'returned')['value'])->toBe(1);
});

it('gives vendors their own drafts and requests with vendor-safe stages only', function () {
    $org = vendorOrg();
    $vendor = vendorUser($org);
    $draft = widgetCase(['org_id' => $org->id, 'status' => CaseStatus::DRAFT, 'reference_no' => null, 'submitted_at' => null]);
    $submitted = widgetCase(['org_id' => $org->id, 'status' => CaseStatus::WITH_LEGAL]);

    $widgets = widgetsFor($vendor);

    expect($widgets['kind'])->toBe('vendor')
        ->and($widgets)->not->toHaveKeys(['gate', 'activity'])
        ->and(collect($widgets['drafts']['items'])->pluck('id'))->toContain($draft->id)
        ->and(collect($widgets['requests']['items'])->pluck('id'))->toContain($submitted->id)
        ->and(collect($widgets['requests']['items'])->firstWhere('id', $submitted->id)['status_label'])->toBe('Under review')
        ->and(array_column($widgets['stages']['items'], 'key'))->not->toContain(CaseStatus::WITH_LEGAL->value);

    expectVendorSafe($widgets);
});
