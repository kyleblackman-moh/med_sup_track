<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Events\CaseTransitioned;
use App\Events\DocumentQuarantined;
use App\Events\HardcopyRecorded;
use App\Models\CaseAssignment;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Notifications\Cases\CaseAssigned;
use App\Notifications\Cases\CaseSubmitted;
use App\Notifications\Cases\CaseWithdrawn;
use App\Notifications\Cases\FileSentToLegal;
use App\Notifications\Cases\FileSentToPs;
use App\Notifications\Cases\FundingReviewRecorded;
use App\Notifications\Cases\HardcopyReceived;
use App\Notifications\Cases\LegalConfirmed;
use App\Notifications\Cases\NonObjectionGranted;
use App\Notifications\Cases\ReadyToPrepareFile;
use App\Notifications\Cases\ReturnedToCmo;
use App\Notifications\Cases\TechnicalReviewRecorded;
use App\Notifications\Channels\CaseDatabaseChannel;
use App\Notifications\InfectedUpload;
use App\Notifications\PasswordChanged;
use App\Notifications\Vendor\VendorApplicationApproved;
use App\Notifications\Vendor\VendorApplicationRejected;
use App\Submissions\SubmitCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// NOTIF-1 (C6): each event reaches the right people and nobody else, once;
// vendor emails carry nothing internal; disabled users get nothing; a mail
// failure never breaks the action. The dev database's own accounts are
// disabled inside each test's transaction, so only this test's users count.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
    DB::table('users')->update(['status' => User::STATUS_DISABLED]);

    $this->registry = caseUser(Role::CmoRegistry);
    $this->cmo = caseUser(Role::Cmo);
    $this->ps = caseUser(Role::PsOffice);
    $this->technocrat = caseUser(Role::Technocrat);
    $this->admin = caseUser(Role::SystemAdmin);
    [$this->case, $this->vendor] = newDraft();
    $this->colleague = vendorUser($this->vendor->organisation); // same organisation, not the applicant
    $this->case->forceFill(['reference_no' => 'NR-N1-'.Str::upper(Str::random(6))])->saveQuietly();

    Notification::fake();
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Who received $class, as user id => how many times. */
function receivedBy(string $class): array
{
    $counts = [];
    foreach (Notification::getFacadeRoot()->sentNotifications() as $byId) {
        foreach ($byId as $id => $byClass) {
            if (isset($byClass[$class])) {
                $counts[(int) $id] = count($byClass[$class]);
            }
        }
    }
    ksort($counts);

    return $counts;
}

function onceEach(User ...$users): array
{
    $ids = array_fill_keys(array_map(fn (User $u) => (int) $u->id, $users), 1);
    ksort($ids);

    return $ids;
}

function transition(ProcurementCase $case, CaseStatus $to): void
{
    CaseTransitioned::dispatch($case, CaseStatus::DRAFT, $to, caseUser(Role::Cmo, ['status' => User::STATUS_DISABLED]));
}

it('sends Case Submitted to intake and the CMO, once each, and not to the PS office', function () {
    transition($this->case, CaseStatus::SUBMITTED);

    expect(receivedBy(CaseSubmitted::class))->toBe(onceEach($this->registry, $this->cmo))
        ->and(receivedBy(FileSentToPs::class))->toBe([]);
});

it('sends each transition email to its own people only', function (CaseStatus $to, string $class, string $who) {
    transition($this->case, $to);

    expect(receivedBy($class))->toBe(onceEach($this->{$who}));
})->with([
    'returned to CMO' => [CaseStatus::RETURNED_TO_CMO, ReturnedToCmo::class, 'cmo'],
    'file sent to PS' => [CaseStatus::WITH_PS, FileSentToPs::class, 'ps'],
    'non-objection granted' => [CaseStatus::NON_OBJECTION_GRANTED, NonObjectionGranted::class, 'cmo'],
]);

// NOTIF-2: the vendor side is the user who submitted the case (replaces FIX-2's
// every case.submit user of the organisation), and only for the four vendor events.

it('sends Approved to the submitter only, not to colleagues in the same organisation', function () {
    transition($this->case, CaseStatus::NON_OBJECTION_GRANTED);

    expect(receivedBy(VendorApplicationApproved::class))->toBe(onceEach($this->vendor));
});

it('sends a vendor nothing when the submitter is inactive, and never falls back to a colleague', function () {
    $this->vendor->forceFill(['status' => User::STATUS_DISABLED])->save();

    transition($this->case, CaseStatus::NON_OBJECTION_GRANTED);

    expect(receivedBy(VendorApplicationApproved::class))->toBe([]);
});

it("never sends a case's vendor notification to another organisation's users, or to a vendor user without case.submit", function () {
    $outsider = vendorUser(vendorOrg());
    $noSubmit = caseUser(null, ['user_type' => 'vendor', 'org_id' => $this->vendor->org_id]);

    transition($this->case, CaseStatus::NON_OBJECTION_GRANTED);
    transition($this->case, CaseStatus::REJECTED_FINAL);

    foreach ([VendorApplicationApproved::class, VendorApplicationRejected::class] as $class) {
        expect(receivedBy($class))->not->toHaveKey($outsider->id)->not->toHaveKey($noSubmit->id);
    }
});

it('records notification.no_recipient with the case and event code only, and logs a warning, when no vendor user is eligible', function () {
    Log::spy();
    $this->vendor->forceFill(['status' => User::STATUS_DISABLED])->save();

    transition($this->case, CaseStatus::NON_OBJECTION_GRANTED);

    $rows = DB::table('audit_logs')->where('action', 'notification.no_recipient')->where('case_id', $this->case->id)->get();
    expect(receivedBy(VendorApplicationApproved::class))->toBe([])
        ->and($rows)->toHaveCount(1)
        ->and(json_decode($rows[0]->metadata, true))->toBe(['event' => 'vendor_approved'])
        ->and($rows[0]->actor_type)->toBe('system')
        ->and((string) $rows[0]->metadata)->not->toContain('@');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $c === ['case_id' => $this->case->id, 'event' => 'vendor_approved'])->once();
});

it('sends nothing for transitions without a notification', function (CaseStatus $to) {
    transition($this->case, $to);

    expect(Notification::getFacadeRoot()->sentNotifications())->toBe([]);
})->with([CaseStatus::CMO_INTAKE, CaseStatus::IN_REVIEW, CaseStatus::READY_FOR_FILE, CaseStatus::FILE_PREPARED, CaseStatus::LETTER_ISSUED, CaseStatus::CLOSED]);

it('tells a named review assignee, and nobody for a pool row or another purpose', function () {
    (new CaseAssignment)->forceFill(['case_id' => $this->case->id, 'role' => 'technocrat', 'purpose' => 'technical', 'user_id' => $this->technocrat->id, 'assigned_at' => now()])->save();
    (new CaseAssignment)->forceFill(['case_id' => $this->case->id, 'role' => 'cab_officer', 'purpose' => 'funding', 'user_id' => null, 'assigned_at' => now()])->save();
    (new CaseAssignment)->forceFill(['case_id' => $this->case->id, 'role' => 'cmo_registry', 'purpose' => 'intake', 'user_id' => $this->registry->id, 'assigned_at' => now()])->save();

    expect(receivedBy(CaseAssigned::class))->toBe(onceEach($this->technocrat));
});

it('sends Hardcopy Received to the CMO only (info), never to the vendor', function () {
    HardcopyRecorded::dispatch($this->case->id);

    expect(receivedBy(HardcopyReceived::class))->toBe(onceEach($this->cmo))
        ->and(HardcopyReceived::for($this->case)->via($this->cmo))->toBe([CaseDatabaseChannel::class]);
});

it('tells the admins about an infected upload', function () {
    $document = makeDocument($this->case, 'bids', ['scan_status' => ScanStatus::INFECTED]);
    DocumentQuarantined::dispatch($document->id);

    expect(receivedBy(InfectedUpload::class))->toBe(onceEach($this->admin));
});

it('tells a user when their password is reset or changed', function () {
    event(new PasswordReset($this->technocrat));
    expect(receivedBy(PasswordChanged::class))->toBe(onceEach($this->technocrat));

    $user = caseUser(Role::Cmo, ['password' => 'Old-Password-123']);
    $this->actingAs($user)->put(route('password.update'), [
        'current_password' => 'Old-Password-123', 'password' => 'New-Password-456', 'password_confirmation' => 'New-Password-456',
    ])->assertSessionHasNoErrors();
    expect(receivedBy(PasswordChanged::class))->toBe(onceEach($this->technocrat, $user));
});

it('sends nothing to disabled users', function () {
    $this->cmo->forceFill(['status' => User::STATUS_DISABLED])->save();
    $this->vendor->forceFill(['status' => User::STATUS_DISABLED])->save();
    $this->colleague->forceFill(['status' => User::STATUS_DISABLED])->save();

    transition($this->case, CaseStatus::SUBMITTED);
    transition($this->case, CaseStatus::NON_OBJECTION_GRANTED);

    expect(receivedBy(CaseSubmitted::class))->toBe(onceEach($this->registry))
        ->and(receivedBy(VendorApplicationApproved::class))->toBe([]);
});

it('gives a vendor nothing internal: reference, one sentence and a link', function () {
    CaseRequisition::withoutGlobalScopes()->where('case_id', $this->case->id)->update(['item_description' => 'Secretamol 500mg']);
    $this->case->forceFill(['funding_track' => 'CONFIRMED'])->saveQuietly();

    $mail = VendorApplicationApproved::for($this->case)->toMail($this->vendor);
    $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines, $mail->actionText, $mail->actionUrl]);

    expect($text)->toContain($this->case->reference_no)
        ->and($mail->actionUrl)->toBe(route('requests.show', $this->case->id))
        ->and($mail->introLines)->toHaveCount(1);
    foreach (['Secretamol', 'funding', 'Funding', 'CONFIRMED', 'Request type', 'Status', $this->technocrat->name, '/cases/'] as $internal) {
        expect($text)->not->toContain($internal);
    }
});

it('gives staff the reference, request type, status and the case link', function () {
    $mail = CaseSubmitted::for($this->case)->toMail($this->cmo);

    expect($mail->subject)->toBe('Request '.$this->case->reference_no.' Submitted')
        ->and($mail->introLines)->toContain('Reference: '.$this->case->reference_no)
        ->and(collect($mail->introLines)->first(fn ($l) => str_starts_with($l, 'Request type: ')))->not->toBeNull()
        ->and(collect($mail->introLines)->first(fn ($l) => str_starts_with($l, 'Status: ')))->not->toBeNull()
        ->and($mail->actionUrl)->toBe(route('cases.show', $this->case->id));
});

// FIX-1 (A3): staff emails never carry the title, supplier, item, organisation or file name.

it('gives the admins only the reference and one sentence for an infected upload', function () {
    $mail = (new InfectedUpload('doc-1', $this->case->id, $this->case->reference_no))->toMail($this->admin);
    $text = implode('
', [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

    expect($mail->introLines)->toHaveCount(1)
        ->and($mail->outroLines)->toBe([])
        ->and($text)->toContain($this->case->reference_no);
    foreach (['Request type', 'Status', 'Document', 'doc-1', $this->vendor->organisation->name, 'Secretamol'] as $field) {
        expect($text)->not->toContain($field);
    }
});

it('keeps every staff case email to the reference, request type, status and link', function (string $class) {
    CaseRequisition::withoutGlobalScopes()->where('case_id', $this->case->id)->update(['item_description' => 'Secretamol 500mg']);
    $this->case->forceFill(['title' => 'Private Title Words'])->saveQuietly();

    $mail = $class::for($this->case)->toMail($this->cmo);
    $text = implode('
', [$mail->subject, ...$mail->introLines, ...$mail->outroLines, $mail->actionText]);

    expect($mail->actionUrl)->toBe(route('cases.show', $this->case->id));
    foreach (['Secretamol', $this->vendor->organisation->name, 'Private Title Words'] as $field) {
        expect($text)->not->toContain($field);
    }
    foreach ($mail->introLines as $line) {
        expect(str_starts_with($line, 'Reference: ') || str_starts_with($line, 'Request type: ') || str_starts_with($line, 'Status: ') || ! str_contains($line, ': '))->toBeTrue();
    }
})->with([
    CaseSubmitted::class, CaseAssigned::class, ReturnedToCmo::class, FileSentToLegal::class, FileSentToPs::class,
    NonObjectionGranted::class, HardcopyReceived::class, ReadyToPrepareFile::class, LegalConfirmed::class,
    CaseWithdrawn::class, TechnicalReviewRecorded::class, FundingReviewRecorded::class,
]);

it('queues after commit with three tries and backoff, to the bell and email for an action', function () {
    $n = CaseSubmitted::for($this->case);

    expect($n)->toBeInstanceOf(ShouldQueue::class)
        ->and($n->afterCommit)->toBeTrue()
        ->and($n->tries)->toBe(3)
        ->and($n->backoff)->toBe([60, 300])
        ->and($n->kind())->toBe('action')
        ->and($n->via($this->cmo))->toBe([CaseDatabaseChannel::class, 'mail']);
});

it('hooks in through the real submit, once', function () {
    [$case, $vendor] = readyDraft();

    app(SubmitCase::class)->handle($case, $vendor);

    expect(receivedBy(CaseSubmitted::class))->toBe(onceEach($this->registry, $this->cmo));
});

it('never lets a mail failure break or roll back the submit', function () {
    [$case, $vendor] = readyDraft();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp down'));

    app(SubmitCase::class)->handle($case, $vendor);

    expect(ProcurementCase::withoutGlobalScopes()->find($case->id)->status)->toBe(CaseStatus::SUBMITTED);
});

it('logs a failed job with the case and class, and no address', function () {
    Log::spy();

    CaseSubmitted::for($this->case)->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
        return $message === 'Notification failed'
            && $context['case_id'] === $this->case->id
            && $context['notification'] === CaseSubmitted::class
            && ! str_contains(json_encode($context), '@');
    })->once();
});
