<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Http\Resources\Requests\RequestProgress;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// OVERNIGHT-2 (B1): the vendor's Progress timeline, in vendor-facing groups only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);

    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org);
    $this->actor = caseUser(Role::Cmo, ['name' => 'Hidden Actor '.Str::upper(Str::random(5))]);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A case with these transitions, each [to, days ago]; reasons and actors set so a leak would show. */
function progressCase(User $vendor, array $steps, array $attributes = []): ProcurementCase
{
    $last = end($steps)[0];
    $case = makeCase([
        'org_id' => $vendor->org_id,
        'created_by' => $vendor->id,
        'status' => $last,
        'reference_no' => 'NR-PG-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays($steps[0][1]),
        ...$attributes,
    ]);

    $from = S::DRAFT;
    foreach ($steps as $i => [$to, $daysAgo]) {
        (new CaseTransition)->forceFill([
            'case_id' => $case->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => test()->actor->id,
            'reason' => 'Secret internal reason '.$i,
            'created_at' => CarbonImmutable::now('UTC')->subDays($daysAgo)->addMinutes($i),
        ])->save();
        $from = $to;
    }

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

function progressLabels(ProcurementCase $case): array
{
    return array_column(RequestProgress::for($case), 'label');
}

it('collapses every internal stage into one Under Review', function () {
    $case = progressCase($this->vendor, [
        [S::SUBMITTED, 20], [S::CMO_INTAKE, 19], [S::IN_REVIEW, 18], [S::READY_FOR_FILE, 15],
        [S::FILE_PREPARED, 14], [S::WITH_LEGAL, 13], [S::RETURNED_TO_CMO, 12], [S::IN_REVIEW, 11],
        [S::READY_FOR_FILE, 10], [S::FILE_PREPARED, 9], [S::WITH_LEGAL, 8], [S::WITH_PS, 6],
        [S::NON_OBJECTION_GRANTED, 4], [S::LETTER_ISSUED, 3], [S::CLOSED, 1],
    ]);

    expect(progressLabels($case))->toBe(['Submitted', 'Under Review', 'Letter Issued', 'Closed']);
});

it('places Hardcopy Received by its date', function () {
    $received = CarbonImmutable::now('America/Port_of_Spain')->subDays(17)->toDateString();
    $case = progressCase($this->vendor, [[S::SUBMITTED, 20], [S::CMO_INTAKE, 19], [S::IN_REVIEW, 10]], [
        'hardcopy_received_on' => $received,
        'hardcopy_received_at' => CarbonImmutable::now('UTC')->subDays(17),
        'hardcopy_received_by' => $this->actor->id,
    ]);

    $progress = RequestProgress::for($case);

    expect(array_column($progress, 'label'))->toBe(['Submitted', 'Under Review', 'Hardcopy Received'])
        ->and($progress[2]['date'])->toBe($received);
});

it('dates each milestone in Port of Spain, date only', function () {
    $case = progressCase($this->vendor, [[S::SUBMITTED, 5]]);
    $at = CaseTransition::where('case_id', $case->id)->value('created_at');

    expect(RequestProgress::for($case))->toBe([[
        'key' => 'submitted',
        'label' => 'Submitted',
        'date' => CarbonImmutable::parse($at, 'UTC')->setTimezone('America/Port_of_Spain')->format('Y-m-d'),
        'date_label' => CarbonImmutable::parse($at, 'UTC')->setTimezone('America/Port_of_Spain')->format('j M Y'),
    ]]);
});

it('shows Withdrawn and Not Approved as vendor labels', function () {
    $withdrawn = progressCase($this->vendor, [[S::SUBMITTED, 5], [S::WITHDRAWN, 2]]);
    $rejected = progressCase($this->vendor, [[S::SUBMITTED, 5], [S::CMO_INTAKE, 4], [S::WITH_PS, 3], [S::REJECTED_FINAL, 2]]);

    expect(progressLabels($withdrawn))->toBe(['Submitted', 'Withdrawn'])
        ->and(progressLabels($rejected))->toBe(['Submitted', 'Under Review', 'Not Approved']);
});

it('gives a draft no timeline', function () {
    expect(RequestProgress::for(makeCase(['org_id' => $this->org->id, 'created_by' => $this->vendor->id])))->toBe([]);
});

it('sends the timeline on the vendor request page', function () {
    $case = progressCase($this->vendor, [[S::SUBMITTED, 5], [S::CMO_INTAKE, 4], [S::WITH_LEGAL, 2]]);

    $this->actingAs($this->vendor)->get(route('requests.show', $case))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('requests/show')
            ->where('progress.0.label', 'Submitted')
            ->where('progress.1.label', 'Under Review')
            ->count('progress', 2));
});

it('never leaks an internal status, actor or reason to the vendor', function (S $status) {
    $case = progressCase($this->vendor, [[S::SUBMITTED, 9], [S::CMO_INTAKE, 8], [S::IN_REVIEW, 7], [S::WITH_LEGAL, 5], [S::RETURNED_TO_CMO, 4], [$status, 2]]);

    $response = $this->actingAs($this->vendor)->get(route('requests.show', $case))->assertOk();
    $progress = json_encode($response->viewData('page')['props']['progress']);
    $page = $response->getContent();

    foreach (S::cases() as $internal) {
        if ($internal->vendorGroup() === 'UNDER_REVIEW') {
            expect($progress)->not->toContain($internal->value)->not->toContain($internal->label());
        }
    }
    expect($page)->not->toContain('Secret internal reason')
        ->and($page)->not->toContain($this->actor->name)
        ->and($progress)->not->toContain('actor')
        ->and($progress)->not->toContain('reason');
})->with([S::IN_REVIEW, S::WITH_PS, S::NON_OBJECTION_GRANTED]);

it('returns 404 to a vendor from another organisation', function () {
    $case = progressCase($this->vendor, [[S::SUBMITTED, 5]]);
    $other = vendorUser(vendorOrg());

    $this->actingAs($other)->get(route('requests.show', $case))->assertNotFound();
});
