<?php

use App\Enums\CaseStatus;
use App\Enums\QueryStatus;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\ProcurementCase;
use App\Workflow\ActionOwner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-26: the status pill names who acts next (App\Workflow\ActionOwner).
// Staff see the party; the vendor sees "Under review" until a query to it is
// open, then "Vendor to view". DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function aoCase(CaseStatus $status, array $attributes = []): ProcurementCase
{
    $org = vendorOrg();
    $org->forceFill(['short_name' => 'NIPDEC'])->save();

    return ProcurementCase::withoutGlobalScopes()->findOrFail(makeCase([
        'org_id' => $org->id, 'status' => $status, 'reference_no' => 'NR-T-'.Str::random(6), 'submitted_at' => now(), ...$attributes,
    ])->id);
}

function aoQuery(ProcurementCase $case, QueryStatus $status = QueryStatus::OPEN, string $to = 'vendor_applicant'): void
{
    DB::table('queries')->insert([
        'id' => (string) Str::ulid(), 'case_id' => $case->id, 'type' => 'TECHNICAL', 'subject' => 'Query',
        'raised_by_role' => 'technocrat', 'raised_by_user_id' => caseUser(Role::Technocrat)->id,
        'raised_to_role' => $to, 'via_cmo' => false, 'status' => $status->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('names the party who acts next for staff', function (CaseStatus $status, array $tracks, string $label, string $tone) {
    expect(ActionOwner::staff(aoCase($status, $tracks)))->toBe(['label' => $label, 'tone' => $tone]);
})->with([
    'submitted' => [CaseStatus::SUBMITTED, [], 'CMO to Action', 'indigo'],
    'intake' => [CaseStatus::CMO_INTAKE, [], 'CMO to Action', 'indigo'],
    'review, both desks' => [CaseStatus::IN_REVIEW, ['technical_track' => TrackStatus::PENDING, 'funding_track' => TrackStatus::PENDING], 'Technocrats and CAB to Action', 'violet'],
    'review, technical' => [CaseStatus::IN_REVIEW, ['technical_track' => TrackStatus::PENDING, 'funding_track' => TrackStatus::CONFIRMED], 'Technocrats to Action', 'violet'],
    'review, funding' => [CaseStatus::IN_REVIEW, ['technical_track' => TrackStatus::FAVOURABLE, 'funding_track' => TrackStatus::PENDING], 'CAB to Action', 'teal'],
    'review, desks done' => [CaseStatus::IN_REVIEW, ['technical_track' => TrackStatus::FAVOURABLE, 'funding_track' => TrackStatus::CONFIRMED], 'CMO to Action', 'indigo'],
    'review, negative' => [CaseStatus::IN_REVIEW, ['technical_track' => TrackStatus::NOT_FAVOURABLE, 'funding_track' => TrackStatus::CONFIRMED], 'CMO to Action', 'indigo'],
    'ready for file' => [CaseStatus::READY_FOR_FILE, [], 'CMO to Action', 'indigo'],
    'file prepared' => [CaseStatus::FILE_PREPARED, [], 'CMO to Action', 'indigo'],
    'legal' => [CaseStatus::WITH_LEGAL, [], 'Legal to Action', 'purple'],
    'ps' => [CaseStatus::WITH_PS, [], 'PS to Action / Non-Objection', 'fuchsia'],
    'returned' => [CaseStatus::RETURNED_TO_CMO, [], 'CMO to Action', 'indigo'],
    'granted' => [CaseStatus::NON_OBJECTION_GRANTED, [], 'CMO to Action', 'indigo'],
    'letter issued' => [CaseStatus::LETTER_ISSUED, [], 'Letter issued', 'lime'],
    'closed' => [CaseStatus::CLOSED, [], 'Closed', 'slate'],
    'withdrawn' => [CaseStatus::WITHDRAWN, [], 'Withdrawn', 'neutral'],
    'rejected' => [CaseStatus::REJECTED_FINAL, [], 'Rejected (final)', 'danger'],
]);

it('names the vendor while a query to it is open', function () {
    $case = aoCase(CaseStatus::IN_REVIEW, ['technical_track' => TrackStatus::QUERY_OPEN, 'funding_track' => TrackStatus::CONFIRMED]);
    aoQuery($case);

    expect(ActionOwner::staff($case))->toBe(['label' => 'NIPDEC to Action', 'tone' => 'warning'])
        ->and(ActionOwner::vendor($case))->toBe(['label' => 'Vendor to view', 'tone' => 'warning']);
});

it('keeps the vendor on "Under review" otherwise', function () {
    $case = aoCase(CaseStatus::WITH_LEGAL);
    aoQuery($case, QueryStatus::ANSWERED);
    aoQuery($case, QueryStatus::OPEN, 'legal_officer');

    expect(ActionOwner::vendor($case))->toBe(['label' => 'Under review', 'tone' => 'violet'])
        ->and(ActionOwner::staff($case)['label'])->toBe('Legal to Action');
});

it('reads the flag a list loads, without a query per row', function () {
    $open = aoCase(CaseStatus::WITH_PS);
    aoQuery($open);
    $quiet = aoCase(CaseStatus::WITH_PS);

    $rows = ActionOwner::withOpenVendorQuery(ProcurementCase::withoutGlobalScopes())->with('organisation')
        ->whereIn('id', [$open->id, $quiet->id])->get()->keyBy('id');

    DB::enableQueryLog();
    expect(ActionOwner::staff($rows[$open->id])['label'])->toBe('NIPDEC to Action')
        ->and(ActionOwner::staff($rows[$quiet->id])['label'])->toBe('PS to Action / Non-Objection')
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('keeps an outcome on its stage label even with a stale open query', function () {
    $case = aoCase(CaseStatus::WITHDRAWN);
    aoQuery($case);

    expect(ActionOwner::staff($case)['label'])->toBe('Withdrawn')
        ->and(ActionOwner::vendor($case)['label'])->toBe('Withdrawn');
});
