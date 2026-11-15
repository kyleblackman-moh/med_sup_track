<?php

use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\ForwardForReview;
use App\Workflow\Actions\RecordFundingConfirmation;
use App\Workflow\Actions\RecordTechnicalReview;
use App\Workflow\TrackCalculator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Count only outcomes from each reviewer's current assignment. DatabaseTransactions only.
// Time travel keeps an old event and a new assignment from ever sharing a timestamp.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 14:00:00', 'UTC'));
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function techRowRound(ProcurementCase $case, User $user): CaseAssignment
{
    $row = new CaseAssignment;
    $row->forceFill([
        'case_id' => $case->id,
        'role' => ReviewDesk::Technical->assignmentRole(),
        'purpose' => ReviewDesk::Technical->value,
        'user_id' => $user->id,
        'assigned_at' => CarbonImmutable::now('UTC'),
    ])->save();

    return $row;
}

/** A new review round, as T11 will start it: close the active technical rows, then add new ones. */
function newRound(ProcurementCase $case, User ...$reviewers): void
{
    test()->travel(1)->days();
    DB::table('case_assignments')->where('case_id', $case->id)->where('purpose', 'technical')
        ->whereNull('completed_at')->whereNull('cancelled_at')
        ->update(['completed_at' => CarbonImmutable::now('UTC')]);
    test()->travel(1)->minutes();
    foreach ($reviewers as $reviewer) {
        techRowRound($case, $reviewer);
    }
    test()->travel(1)->minutes();
}

function roundCase(): ProcurementCase
{
    return makeCase([
        'status' => CaseStatus::IN_REVIEW,
        'reference_no' => 'NR-RND-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(3),
        'technical_track' => TrackStatus::PENDING,
        'funding_track' => TrackStatus::CONFIRMED,
    ]);
}

function recordTech(ProcurementCase $case, User $user, string $outcome): ProcurementCase
{
    test()->travel(1)->minutes();

    return app(RecordTechnicalReview::class)->handle(
        ProcurementCase::withoutGlobalScopes()->findOrFail($case->id),
        $user,
        $outcome,
        $outcome === 'favourable' ? null : 'Round outcome reason text',
    );
}

function techTrack(ProcurementCase $case): string
{
    return (string) DB::table('cases')->where('id', $case->id)->value('technical_track');
}

it('ignores a stale FAVOURABLE from an earlier round', function () {
    $case = roundCase();
    $a = caseUser(Role::Technocrat);
    $b = caseUser(Role::Technocrat);
    techRowRound($case, $a);
    techRowRound($case, $b);
    recordTech($case, $a, 'favourable');
    recordTech($case, $b, 'favourable');
    expect(techTrack($case))->toBe('FAVOURABLE');

    newRound($case, $a, $b);
    recordTech($case, $b, 'favourable');

    // A's round-one FAVOURABLE no longer counts, so the track waits for A.
    expect(techTrack($case))->toBe('PENDING');

    recordTech($case, $a, 'favourable');
    expect(techTrack($case))->toBe('FAVOURABLE');
});

it('starts a re-chosen reviewer blank', function () {
    $case = roundCase();
    $a = caseUser(Role::Technocrat);
    techRowRound($case, $a);
    recordTech($case, $a, 'favourable');

    newRound($case, $a);

    $reviewers = TrackCalculator::reviewers(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), ReviewDesk::Technical);
    expect($reviewers->keys()->all())->toBe([(int) $a->id])
        ->and($reviewers[(int) $a->id]['latest'])->toBeNull()
        ->and($reviewers[(int) $a->id]['assignment']->completed_at)->toBeNull();
});

it('does not count a NOT_FAVOURABLE from round one in round two', function () {
    $case = roundCase();
    $a = caseUser(Role::Technocrat);
    techRowRound($case, $a);
    recordTech($case, $a, 'not_favourable');
    expect(techTrack($case))->toBe('NOT_FAVOURABLE');

    $b = caseUser(Role::Technocrat);
    newRound($case, $a, $b);
    recordTech($case, $b, 'favourable');
    expect(techTrack($case))->toBe('PENDING');

    recordTech($case, $a, 'favourable');
    expect(techTrack($case))->toBe('FAVOURABLE');
});

it('ignores the outcomes of a reviewer who is not in the new round', function () {
    $case = roundCase();
    $a = caseUser(Role::Technocrat);
    $b = caseUser(Role::Technocrat);
    techRowRound($case, $a);
    recordTech($case, $a, 'not_favourable');

    newRound($case, $b);
    recordTech($case, $b, 'favourable');

    $reviewers = TrackCalculator::reviewers(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), ReviewDesk::Technical);
    expect(techTrack($case))->toBe('FAVOURABLE')
        ->and($reviewers->keys()->all())->toBe([(int) $b->id]);
});

it('uses the latest active row when a user has two', function () {
    $case = roundCase();
    $a = caseUser(Role::Technocrat);
    $first = techRowRound($case, $a);
    recordTech($case, $a, 'favourable');
    test()->travel(1)->hours();
    $second = techRowRound($case, $a);

    $reviewers = TrackCalculator::reviewers(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), ReviewDesk::Technical);
    expect($reviewers)->toHaveCount(1)
        ->and((int) $reviewers[(int) $a->id]['assignment']->id)->toBe((int) $second->id)
        ->and((int) $reviewers[(int) $a->id]['assignment']->id)->not->toBe((int) $first->id)
        ->and($reviewers[(int) $a->id]['latest'])->toBeNull();
});

it('leaves the T3 flow unchanged: forward, record, both tracks complete', function () {
    $case = makeCase([
        'status' => CaseStatus::CMO_INTAKE,
        'reference_no' => 'NR-RND-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(2),
        'current_holder_role' => 'cmo',
    ]);
    $tech = caseUser(Role::Technocrat);
    $cab = caseUser(Role::CabOfficer);

    app(ForwardForReview::class)->handle($case, caseUser(Role::Cmo), [(int) $tech->id], [(int) $cab->id]);
    expect(techTrack($case))->toBe('PENDING');

    recordTech($case, $tech, 'favourable');
    test()->travel(1)->minutes();
    app(RecordFundingConfirmation::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $cab, 'confirmed');

    $row = DB::table('cases')->where('id', $case->id)->first();
    expect($row->technical_track)->toBe('FAVOURABLE')
        ->and($row->funding_track)->toBe('CONFIRMED');
});

it('counts an outcome recorded in the same instant as the assignment', function () {
    $case = roundCase();
    $a = caseUser(Role::Technocrat);
    techRowRound($case, $a);

    app(RecordTechnicalReview::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $a, 'favourable');

    expect(techTrack($case))->toBe('FAVOURABLE');
});
