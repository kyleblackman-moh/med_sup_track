<?php

use App\Enums\AuditAction;
use App\Enums\CaseStatus;
use App\Enums\ReviewDesk;
use App\Enums\ReviewOutcome;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Notifications\CaseNotification;
use App\Notifications\Cases\FundingReviewRecorded;
use App\Notifications\Cases\ReadyToPrepareFile;
use App\Workflow\Actions\RecordFundingConfirmation;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-24: the three CAB funding statuses. Availability of Funds and
// Verified by Accounts confirm funding (track complete, gate checked);
// Virement Required does not (track stays open, the CMO is told) and needs an
// internal reason. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** An IN_REVIEW case with one CAB officer assigned; technical favourable and hardcopy in when $gateReady. */
function fsCase(bool $gateReady = false): array
{
    $vendor = vendorUser(vendorOrg());
    $case = makeCase([
        'org_id' => $vendor->org_id,
        'created_by' => $vendor->id,
        'status' => CaseStatus::IN_REVIEW,
        'reference_no' => 'NR-FS-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDay(),
        'technical_track' => $gateReady ? TrackStatus::FAVOURABLE : TrackStatus::PENDING,
        'funding_track' => TrackStatus::PENDING,
    ]);
    if ($gateReady) {
        DB::table('cases')->where('id', $case->id)->update([
            'hardcopy_received_at' => now(), 'hardcopy_received_by' => caseUser(Role::CmoRegistry)->id,
            'hardcopy_received_on' => CarbonImmutable::now('America/Port_of_Spain')->toDateString(),
        ]);
    }
    $cab = caseUser(Role::CabOfficer);
    (new CaseAssignment)->forceFill([
        'case_id' => $case->id, 'role' => 'cab_officer', 'purpose' => 'funding', 'user_id' => $cab->id, 'assigned_at' => now()->subMinute(),
    ])->save();

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $cab, $vendor];
}

function fsRecord(ProcurementCase $case, User $cab, string $outcome, ?string $reason = null): ProcurementCase
{
    return app(RecordFundingConfirmation::class)->handle($case, $cab, $outcome, $reason);
}

it('offers exactly the three funding statuses', function () {
    expect(array_map(fn (ReviewOutcome $o) => $o->label(), ReviewDesk::Funding->outcomes()))
        ->toBe(['Availability of Funds', 'Verified by Accounts', 'Virement Required']);
});

it('confirms funding for Availability of Funds and Verified by Accounts', function (string $outcome) {
    [$case, $cab] = fsCase();

    expect(fsRecord($case, $cab, $outcome)->funding_track)->toBe(TrackStatus::CONFIRMED);
})->with(['availability of funds' => ['confirmed'], 'verified by accounts' => ['verified_by_accounts']]);

it('leaves the track open for Virement Required', function () {
    [$case, $cab] = fsCase();

    expect(fsRecord($case, $cab, 'not_confirmed', 'Funds must be moved from vote 12.')->funding_track)
        ->toBe(TrackStatus::NOT_CONFIRMED)
        ->and(TrackStatus::NOT_CONFIRMED)->not->toBe(ReviewDesk::Funding->positiveTrack());
});

it('requires a reason for Virement Required only', function () {
    [$case, $cab] = fsCase();

    expect(fn () => fsRecord($case, $cab, 'not_confirmed'))->toThrow(ValidationException::class)
        ->and(fn () => fsRecord($case, $cab, 'not_confirmed', 'short'))->toThrow(ValidationException::class)
        ->and(fsRecord($case, $cab, 'verified_by_accounts')->funding_track)->toBe(TrackStatus::CONFIRMED);
});

it('requires the reason through the form too, and accepts the new status', function () {
    [$case, $cab] = fsCase();

    $this->actingAs($cab)->post(route('cases.funding', $case), ['outcome' => 'not_confirmed'])->assertSessionHasErrors('reason');
    $this->actingAs($cab)->post(route('cases.funding', $case), ['outcome' => 'verified_by_accounts'])->assertSessionHasNoErrors();

    expect(DB::table('cases')->where('id', $case->id)->value('funding_track'))->toBe('CONFIRMED');
});

it('checks the gate when funding is confirmed, and not for Virement Required', function (string $outcome, ?string $reason, bool $ready) {
    Notification::fake();
    $cmo = caseUser(Role::Cmo);
    [$case, $cab] = fsCase(gateReady: true);

    fsRecord($case, $cab, $outcome, $reason);

    $ready
        ? Notification::assertSentTo($cmo, ReadyToPrepareFile::class)
        : Notification::assertNotSentTo($cmo, ReadyToPrepareFile::class);
})->with([
    'availability of funds' => ['confirmed', null, true],
    'verified by accounts' => ['verified_by_accounts', null, true],
    'virement required' => ['not_confirmed', 'Funds must be moved from vote 12.', false],
]);

it('tells the CMO (info) when a virement is required, without the reason', function () {
    Notification::fake();
    $cmo = caseUser(Role::Cmo);
    [$case, $cab] = fsCase();

    fsRecord($case, $cab, 'not_confirmed', 'Funds must be moved from vote 12.');

    Notification::assertSentTo($cmo, FundingReviewRecorded::class, fn (FundingReviewRecorded $n) => $n->kind() === CaseNotification::INFO
        && str_contains($n->title(), 'Virement Required')
        && ! str_contains($n->title(), 'vote 12'));
});

it('lets CAB change the status later, auditing old, new and the reason id only', function () {
    [$case, $cab] = fsCase();
    fsRecord($case, $cab, 'confirmed');
    fsRecord($case, $cab, 'not_confirmed', 'Funds must be moved from vote 12.');
    $case = fsRecord($case, $cab, 'verified_by_accounts');

    $audits = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseFundingRecorded->value)
        ->orderBy('id')->pluck('metadata')->map(fn ($m) => json_decode($m, true));
    $events = DB::table('case_track_events')->where('case_id', $case->id)->orderBy('id')->pluck('id')->all();

    expect($case->funding_track)->toBe(TrackStatus::CONFIRMED)
        ->and($audits->map(fn ($m) => [$m['previous_outcome'], $m['outcome'], $m['reason_id']])->all())->toBe([
            [null, 'confirmed', null],
            ['confirmed', 'not_confirmed', (int) $events[1]],
            ['not_confirmed', 'verified_by_accounts', null],
        ])
        ->and($audits->toJson())->not->toContain('vote 12');
});

it('never shows the funding status or reason to the vendor', function () {
    [$case, $cab, $vendor] = fsCase();
    fsRecord($case, $cab, 'not_confirmed', 'Funds must be moved from vote 12.');

    $body = $this->actingAs($vendor)->get("/requests/{$case->id}")->assertOk()->getContent();

    expect($body)->not->toContain('vote 12')
        ->not->toContain('Virement')
        ->not->toContain('not_confirmed');
});

it('shows the three statuses on the CAB officer\'s review form', function () {
    [$case, $cab] = fsCase();

    $forms = pageProps($this->actingAs($cab)->get(route('cases.show', $case))->assertOk())['review_forms'];

    expect(collect($forms)->firstWhere('track', 'funding')['outcomes'])->toBe([
        ['value' => 'confirmed', 'label' => 'Availability of Funds', 'positive' => true],
        ['value' => 'verified_by_accounts', 'label' => 'Verified by Accounts', 'positive' => true],
        ['value' => 'not_confirmed', 'label' => 'Virement Required', 'positive' => false],
    ]);
});
