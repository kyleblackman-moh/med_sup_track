<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Models\CaseAssignment;
use App\Models\CaseRequisition;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-24: the Cases list (server side): every case the user can see,
// with search (reference, item, organisation) and the status filter in the
// query string. Staff never see a vendor DRAFT. DatabaseTransactions only;
// assertions are limited to this test's cases (the dev database holds others).

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function clCase(Organisation $org, CaseStatus $status, string $item, array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'org_id' => $org->id,
        'status' => $status,
        'reference_no' => $status === CaseStatus::DRAFT ? null : 'NR-CL-'.Str::upper(Str::random(8)),
        'submitted_at' => $status === CaseStatus::DRAFT ? null : now(),
        'current_holder_role' => 'cmo_registry',
        ...$attributes,
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => $item])->save();

    return $case;
}

/** Ids on the Cases list for $user with $query. */
function clIds(User $user, array $query = []): array
{
    return collect(pageProps(test()->actingAs($user)->get(route('cases.index', $query))->assertOk())['cases']['data'])->pluck('id')->all();
}

it('has the list columns', function () {
    $org = vendorOrg();
    $case = clCase($org, CaseStatus::SUBMITTED, 'Feeding tube');

    $row = collect(pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.index', ['org' => $org->id]))->assertOk())['cases']['data'])->firstWhere('id', $case->id);

    expect($row)->toHaveKeys(['reference_no', 'item_description', 'organisation', 'procurement_type', 'status_label', 'holder_label', 'submitted_at'])
        ->and($row['organisation'])->toBe($org->name)
        ->and($row['item_description'])->toBe('Feeding tube');
});

it('searches the reference, the item and the organisation', function () {
    $org = vendorOrg();
    $tube = clCase($org, CaseStatus::SUBMITTED, 'Feeding tube 6Fr');
    $glove = clCase($org, CaseStatus::SUBMITTED, 'Nitrile gloves');
    $cmo = caseUser(Role::Cmo);

    expect(clIds($cmo, ['org' => $org->id, 'q' => 'tube']))->toBe([$tube->id])
        ->and(clIds($cmo, ['org' => $org->id, 'q' => substr($glove->reference_no, 3)]))->toBe([$glove->id])
        ->and(clIds($cmo, ['q' => $org->name]))->toContain($tube->id, $glove->id)
        // Wildcards are literal.
        ->and(clIds($cmo, ['org' => $org->id, 'q' => '%%']))->toBe([]);
});

it('filters by status and keeps the filters in the query string', function () {
    $org = vendorOrg();
    $submitted = clCase($org, CaseStatus::SUBMITTED, 'Feeding tube');
    $review = clCase($org, CaseStatus::IN_REVIEW, 'Feeding tube', ['current_holder_role' => null]);

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.index', ['org' => $org->id, 'status' => 'IN_REVIEW', 'q' => 'tube']))->assertOk());

    expect(collect($props['cases']['data'])->pluck('id')->all())->toBe([$review->id])
        ->and($props['filters'])->toMatchArray(['status' => 'IN_REVIEW', 'q' => 'tube'])
        ->and(clIds(caseUser(Role::Cmo), ['org' => $org->id]))->toContain($submitted->id);
});

it('scopes the list per role', function () {
    $org = vendorOrg();
    $case = clCase($org, CaseStatus::IN_REVIEW, 'Feeding tube', ['current_holder_role' => null]);
    $tech = caseUser(Role::Technocrat);
    (new CaseAssignment)->forceFill(['case_id' => $case->id, 'role' => 'technocrat', 'purpose' => 'technical', 'user_id' => $tech->id, 'assigned_at' => now()])->save();

    expect(clIds(caseUser(Role::Cmo), ['org' => $org->id]))->toBe([$case->id])      // case.view_all
        ->and(clIds(caseUser(Role::PsOffice), ['org' => $org->id]))->toBe([$case->id]) // case.view_all
        ->and(clIds($tech, ['org' => $org->id]))->toBe([$case->id])                 // assigned
        ->and(clIds(caseUser(Role::Technocrat), ['org' => $org->id]))->toBe([])      // not assigned
        ->and(clIds(caseUser(Role::CabOfficer), ['org' => $org->id]))->toBe([]);
});

it('never lists a vendor draft for staff, through any filter or the Awaiting Letter shortcut', function () {
    $org = vendorOrg();
    $draft = clCase($org, CaseStatus::DRAFT, 'Draft feeding tube');
    $cmo = caseUser(Role::Cmo);

    expect(clIds($cmo, ['org' => $org->id]))->not->toContain($draft->id)
        ->and(clIds($cmo, ['q' => 'Draft feeding tube']))->not->toContain($draft->id)
        // The sidebar's "Awaiting Letter" item is this saved view: non-objection granted, not drafts.
        ->and(clIds($cmo, ['status' => 'NON_OBJECTION_GRANTED', 'org' => $org->id]))->not->toContain($draft->id);
    $this->actingAs($cmo)->get(route('cases.index', ['status' => 'DRAFT']))->assertRedirect(route('cases.index'));
    $this->actingAs($cmo)->get(route('cases.show', $draft))->assertNotFound();
});

// Backlog, 2026-11-25: status chips in place of the old sidebar shortcuts.
it('sends the status chips with counts under the current search, each a real link', function () {
    $org = vendorOrg();
    $tag = 'Chipcheck '.Str::random(6);
    clCase($org, CaseStatus::IN_REVIEW, "{$tag} gauze");
    clCase($org, CaseStatus::WITH_PS, "{$tag} gloves");
    clCase($org, CaseStatus::NON_OBJECTION_GRANTED, "{$tag} masks");
    clCase($org, CaseStatus::SUBMITTED, 'Something else entirely');
    $cmo = caseUser(Role::Cmo);

    $chips = collect(pageProps($this->actingAs($cmo)->get(route('cases.index', ['q' => $tag, 'status' => 'WITH_PS']))->assertOk())['chips'])->keyBy('key');

    expect($chips->keys()->all())->toBe(['all', 'in_review', 'awaiting_funding', 'with_ps', 'awaiting_letter'])
        ->and($chips['all']['count'])->toBe(3)
        ->and($chips['in_review']['count'])->toBe(1)
        ->and($chips['with_ps']['count'])->toBe(1)
        ->and($chips['awaiting_letter']['count'])->toBe(1)
        ->and($chips['with_ps']['active'])->toBeTrue()
        ->and($chips['all']['active'])->toBeFalse()
        // The search stays; the status changes; no page.
        ->and($chips['in_review']['href'])->toBe(route('cases.index', ['q' => $tag, 'status' => 'IN_REVIEW'], false))
        ->and($chips['all']['href'])->toBe(route('cases.index', ['q' => $tag], false))
        ->and($chips['awaiting_funding']['href'])->toBe(route('cases.index', ['q' => $tag, 'awaiting' => 'funding'], false));
});
