<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\CaseRequisition;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\CaseHolders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-24: Current Holder = the active assignments for the current
// stage's work (both desks in parallel review), else the stage owner role.
// DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function chCase(Organisation $org, array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'org_id' => $org->id,
        'status' => CaseStatus::IN_REVIEW,
        'submitted_at' => now(),
        'reference_no' => 'NR-CH-'.Str::upper(Str::random(8)),
        'current_holder_role' => null,
        'technical_track' => TrackStatus::PENDING,
        'funding_track' => TrackStatus::PENDING,
        ...$attributes,
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Item'])->save();

    return $case;
}

/** An assignment with its purpose, as the workflow actions write it. */
function chAssign(ProcurementCase $case, Role $role, ?User $user, string $purpose, bool $completed = false): CaseAssignment
{
    $a = new CaseAssignment;
    $a->forceFill([
        'case_id' => $case->id, 'role' => $role->value, 'user_id' => $user?->id, 'purpose' => $purpose,
        'assigned_at' => now(), 'completed_at' => $completed ? now() : null,
    ])->save();

    return $a;
}

it('lists both desks of a case in parallel review, with names', function () {
    $case = chCase(vendorOrg());
    $tech = caseUser(Role::Technocrat, ['name' => 'Tess Technocrat']);
    $cab = caseUser(Role::CabOfficer, ['name' => 'Carl Cab']);
    chAssign($case, Role::CmoRegistry, caseUser(Role::CmoRegistry), 'intake'); // still active after intake
    chAssign($case, Role::Technocrat, $tech, 'technical');
    chAssign($case, Role::CabOfficer, $cab, 'funding');

    expect(CaseHolders::for($case->fresh()))->toBe([
        ['role' => 'technocrat', 'label' => 'Technocrat', 'name' => 'Tess Technocrat'],
        ['role' => 'cab_officer', 'label' => 'Cost and Budgeting', 'name' => 'Carl Cab'],
    ])->and(CaseHolders::label($case->fresh()))->toBe('Technocrat (Tess Technocrat), Cost and Budgeting (Carl Cab)');
});

it('does not list completed assignments', function () {
    $case = chCase(vendorOrg());
    chAssign($case, Role::Technocrat, caseUser(Role::Technocrat, ['name' => 'Old Tech']), 'technical', completed: true);
    chAssign($case, Role::CabOfficer, caseUser(Role::CabOfficer, ['name' => 'Carl Cab']), 'funding');

    expect(collect(CaseHolders::for($case->fresh()))->pluck('name')->all())->toBe(['Carl Cab']);
});

it('drops a desk once its track is decided', function () {
    $case = chCase(vendorOrg(), ['technical_track' => TrackStatus::FAVOURABLE]);
    chAssign($case, Role::Technocrat, caseUser(Role::Technocrat), 'technical');
    chAssign($case, Role::CabOfficer, caseUser(Role::CabOfficer, ['name' => 'Carl Cab']), 'funding');

    expect(collect(CaseHolders::for($case->fresh()))->pluck('role')->all())->toBe(['cab_officer']);
});

it('shows the stage owner role when no assignment holds the case', function (array $attributes, ?string $label) {
    $case = chCase(vendorOrg(), $attributes);

    expect(CaseHolders::label($case->fresh()))->toBe($label);
})->with([
    'with the CMO (file prepared)' => [['status' => CaseStatus::FILE_PREPARED, 'current_holder_role' => 'cmo'], 'CMO'],
    'with the PS' => [['status' => CaseStatus::WITH_PS, 'current_holder_role' => 'ps_office'], 'PS Office'],
    'in review, both tracks decided' => [['technical_track' => TrackStatus::FAVOURABLE, 'funding_track' => TrackStatus::CONFIRMED], 'CMO'],
    'closed: no holder' => [['status' => CaseStatus::CLOSED], null],
]);

it('shows the holders on the staff case list and case page', function () {
    $org = vendorOrg();
    $case = chCase($org);
    chAssign($case, Role::Technocrat, caseUser(Role::Technocrat, ['name' => 'Tess Technocrat']), 'technical');
    chAssign($case, Role::CabOfficer, caseUser(Role::CabOfficer, ['name' => 'Carl Cab']), 'funding');
    $cmo = caseUser(Role::Cmo);

    $row = collect(pageProps($this->actingAs($cmo)->get(route('cases.index', ['org' => $org->id]))->assertOk())['cases']['data'])->firstWhere('id', $case->id);
    $page = pageProps($this->actingAs($cmo)->get(route('cases.show', $case))->assertOk());

    expect($row['holder_label'])->toBe('Technocrat (Tess Technocrat), Cost and Budgeting (Carl Cab)')
        ->and($row['holders'])->toHaveCount(2)
        ->and($page['detail']['holder_label'] ?? $page['case']['holder_label'])->toBe('Technocrat (Tess Technocrat), Cost and Budgeting (Carl Cab)');
});
