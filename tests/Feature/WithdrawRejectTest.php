<?php

use App\Enums\CaseStatus as S;
use App\Enums\QueryStatus;
use App\Enums\Role;
use App\Models\CaseQuery;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Notifications\Cases\CaseWithdrawn;
use App\Notifications\Vendor\VendorApplicationRejected;
use App\Workflow\Actions\EndCase;
use App\Workflow\CaseTransitionService;
use App\Workflow\Exceptions\InvalidTransition;
use App\Workflow\TerminalCloseOut;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// TERMINAL-1: withdraw (T15 staff, T15V vendor) and final reject (T16).
// DatabaseTransactions only; helpers in tests/Support/CaseHelpers.php.

const WR_REASON = 'Internal reason Quokka-4471 that must never reach the vendor';
const WR_MESSAGE = 'Please contact the Ministry about a new application.';

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Mail::fake();

    $this->org = vendorOrg();
    $this->vendor = vendorUser($this->org);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function wrCase($t, S $status, array $attributes = []): ProcurementCase
{
    $case = makeCase([
        'org_id' => $t->org->id,
        'created_by' => $t->vendor->id,
        'status' => $status,
        'reference_no' => $status === S::DRAFT ? null : 'NR-WR-'.Str::upper(Str::random(8)),
        'submitted_at' => $status === S::DRAFT ? null : now()->subDays(2),
        ...$attributes,
    ]);

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

/** A user of $role who could see the case: the owning vendor, or staff with an active assignment. */
function wrUser($t, Role $role, ProcurementCase $case): User
{
    if ($role === Role::VendorApplicant) {
        return vendorUser($t->org);
    }

    $user = caseUser($role);
    assign($case, $role, $user);

    return $user;
}

function wrQuery(ProcurementCase $case, QueryStatus $status): string
{
    $id = (string) Str::ulid();
    DB::table('queries')->insert([
        'id' => $id, 'case_id' => $case->id, 'type' => 'TECHNICAL', 'subject' => 'Query',
        'raised_by_role' => 'technocrat', 'raised_by_user_id' => caseUser(Role::Technocrat)->id,
        'raised_to_role' => 'vendor_applicant', 'via_cmo' => false, 'status' => $status->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

// 1. Policy table: every role x every status x {withdraw, reject_final} ----------------

it('allows withdraw and final reject exactly as the rules say, for every role and status', function () {
    $withdraw = [
        Role::VendorApplicant->value => [S::DRAFT, S::SUBMITTED],
        Role::Cmo->value => [S::SUBMITTED, S::CMO_INTAKE, S::IN_REVIEW],
    ];
    $reject = [Role::Cmo->value => [S::WITH_PS, S::RETURNED_TO_CMO]];
    $wrong = [];

    foreach (Role::cases() as $role) {
        foreach (S::cases() as $status) {
            $case = wrCase($this, $status);
            $user = wrUser($this, $role, $case);

            $cells = [
                'withdraw' => [EndCase::canWithdraw($user, $case), in_array($status, $withdraw[$role->value] ?? [], true)],
                'reject_final' => [EndCase::canRejectFinal($user, $case), in_array($status, $reject[$role->value] ?? [], true)],
            ];
            foreach ($cells as $action => [$actual, $expected]) {
                if ($actual !== $expected) {
                    $wrong[] = "{$role->value} {$status->value} {$action}: ".($actual ? 'allowed' : 'denied');
                }
            }
        }
    }

    expect($wrong)->toBe([]);
});

it('enforces the same table in the service, not just the flags', function (Role $role, S $status, S $to) {
    $case = wrCase($this, $status);
    $user = wrUser($this, $role, $case);

    expect(fn () => app(CaseTransitionService::class)->transition($case, $to, $user, 'Reason',
        onPsInstruction: $to === S::REJECTED_FINAL ? true : null))->toThrow(Exception::class);
    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe($status->value);
})->with([
    'vendor after intake' => [Role::VendorApplicant, S::CMO_INTAKE, S::WITHDRAWN],
    'vendor in review' => [Role::VendorApplicant, S::IN_REVIEW, S::WITHDRAWN],
    'registry' => [Role::CmoRegistry, S::SUBMITTED, S::WITHDRAWN],
    'ps office reject' => [Role::PsOffice, S::WITH_PS, S::REJECTED_FINAL],
    'cmo withdraw with legal' => [Role::Cmo, S::WITH_LEGAL, S::WITHDRAWN],
    'cmo reject in review' => [Role::Cmo, S::IN_REVIEW, S::REJECTED_FINAL],
]);

it('does not let a vendor withdraw another organisation\'s case (404, nothing written)', function () {
    $case = wrCase($this, S::SUBMITTED);
    $outsider = vendorUser(vendorOrg());

    $this->actingAs($outsider)->post(route('requests.withdraw', $case), ['reason' => 'Not ours'])->assertNotFound();

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('SUBMITTED')
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(0);
});

// 2. End to end over HTTP ---------------------------------------------------------------

it('lets the vendor withdraw its own submitted request', function () {
    $case = wrCase($this, S::SUBMITTED);

    $this->actingAs($this->vendor)->post(route('requests.withdraw', $case), ['reason' => 'No longer needed'])
        ->assertRedirect(route('requests.show', $case))->assertSessionHas('success');

    $row = DB::table('case_transitions')->where('case_id', $case->id)->sole();
    expect($row->to_status)->toBe('WITHDRAWN')->and($row->reason)->toBe('No longer needed')
        ->and($row->external_message)->toBeNull()->and($row->on_ps_instruction)->toBeNull();
});

it('lets the CMO withdraw and record a final rejection, with the message to the vendor on the history row', function () {
    $cmo = caseUser(Role::Cmo);
    $a = wrCase($this, S::CMO_INTAKE);
    $b = wrCase($this, S::RETURNED_TO_CMO);

    $this->actingAs($cmo)->post(route('cases.withdraw', $a), ['reason' => WR_REASON, 'vendor_message' => WR_MESSAGE])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Case withdrawn.');
    $this->actingAs($cmo)->post(route('cases.reject-final', $b), ['reason' => WR_REASON, 'vendor_message' => WR_MESSAGE, 'on_ps_instruction' => '1'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Case rejected (final).');

    $rows = DB::table('case_transitions')->whereIn('case_id', [$a->id, $b->id])->orderBy('id')->get();
    expect($rows->pluck('to_status')->all())->toBe(['WITHDRAWN', 'REJECTED_FINAL'])
        ->and($rows->pluck('external_message')->unique()->all())->toBe([WR_MESSAGE])
        ->and((bool) $rows[1]->on_ps_instruction)->toBeTrue()
        ->and($rows[0]->on_ps_instruction)->toBeNull();

    // A repeat is answered with who did it and changes nothing.
    $this->actingAs($cmo)->post(route('cases.withdraw', $a), ['reason' => 'Again'])->assertSessionHas('info');
    expect(DB::table('case_transitions')->where('case_id', $a->id)->count())->toBe(1);
});

it('requires a reason, and the PS-instruction box on a final rejection', function () {
    $cmo = caseUser(Role::Cmo);
    $case = wrCase($this, S::WITH_PS);

    $this->actingAs($cmo)->post(route('cases.reject-final', $case), ['reason' => '  ', 'on_ps_instruction' => '1'])
        ->assertSessionHasErrors(['reason' => 'Reason is required.']);
    $this->actingAs($cmo)->post(route('cases.reject-final', $case), ['reason' => 'Instructed'])
        ->assertSessionHasErrors('on_ps_instruction');
    $this->actingAs($cmo)->post(route('cases.withdraw', wrCase($this, S::IN_REVIEW)), [])
        ->assertSessionHasErrors(['reason' => 'Reason is required.']);
    $this->actingAs($this->vendor)->post(route('requests.withdraw', wrCase($this, S::SUBMITTED)), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('WITH_PS');
});

it('refuses the vendor after intake with 403', function () {
    $case = wrCase($this, S::CMO_INTAKE);

    $this->actingAs($this->vendor)->post(route('requests.withdraw', $case), ['reason' => 'Too late'])->assertForbidden();
});

// 3. Terminal ----------------------------------------------------------------------------

it('lets nothing leave WITHDRAWN or REJECTED_FINAL, by any user or route', function (S $terminal) {
    $case = wrCase($this, $terminal);
    $cmo = caseUser(Role::Cmo);

    foreach (S::cases() as $to) {
        expect(fn () => app(CaseTransitionService::class)->transition($case, $to, $cmo, 'Reason', onPsInstruction: $to === S::REJECTED_FINAL ? true : null))
            ->toThrow(InvalidTransition::class);
    }

    expect(EndCase::canWithdraw($cmo, $case))->toBeFalse()
        ->and(EndCase::canRejectFinal($cmo, $case))->toBeFalse();

    // The page actions are refused too (403: a terminal status).
    $this->actingAs($cmo)->post(route('cases.intake', $case))->assertForbidden();
    // Withdrawing again is answered (already withdrawn, or not possible after a rejection); nothing is written.
    $this->actingAs($cmo)->post(route('cases.withdraw', $case), ['reason' => 'Again?'])->assertRedirect();
    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe($terminal->value)
        ->and(DB::table('case_transitions')->where('case_id', $case->id)->count())->toBe(0);
})->with([S::WITHDRAWN, S::REJECTED_FINAL]);

// 4. Side effects --------------------------------------------------------------------------

it('cancels open queries and closes active assignments in the same transaction, with audit rows', function (S $from, string $route, array $extra, string $reason) {
    $cmo = caseUser(Role::Cmo);
    $case = wrCase($this, $from);
    $open = wrQuery($case, QueryStatus::OPEN);
    $held = wrQuery($case, QueryStatus::AWAITING_FORWARD);
    $answered = wrQuery($case, QueryStatus::ANSWERED);
    $closed = wrQuery($case, QueryStatus::CLOSED);
    $active = assign($case, Role::Technocrat, caseUser(Role::Technocrat));
    $done = assign($case, Role::CabOfficer, caseUser(Role::CabOfficer), completed: true);

    $this->actingAs($cmo)->post(route($route, $case), ['reason' => WR_REASON, ...$extra])->assertSessionHasNoErrors();

    $queries = CaseQuery::withoutGlobalScopes()->where('case_id', $case->id)->get()->keyBy('id');
    foreach ([$open, $held, $answered] as $id) {
        expect($queries[$id]->status)->toBe(QueryStatus::CANCELLED)
            ->and($queries[$id]->cancel_reason)->toBe($reason)
            ->and((int) $queries[$id]->cancelled_by)->toBe($cmo->id)
            ->and($queries[$id]->cancelled_at)->not->toBeNull();
    }
    expect($queries[$closed]->status)->toBe(QueryStatus::CLOSED)
        ->and($queries[$closed]->cancelled_at)->toBeNull()
        ->and($active->fresh()->cancelled_at)->not->toBeNull()
        ->and($done->fresh()->cancelled_at)->toBeNull()
        ->and(DB::table('case_assignments')->where('case_id', $case->id)->whereNull('completed_at')->whereNull('cancelled_at')->count())->toBe(0);

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->get()->groupBy('action');
    expect($audit['query.cancelled'] ?? collect())->toHaveCount(3)
        ->and($audit['case.assignment_cancelled'] ?? collect())->toHaveCount(1)
        ->and($audit['case.transitioned'])->toHaveCount(1)
        ->and(json_decode($audit['case.transitioned'][0]->metadata, true))->toMatchArray([
            'has_reason' => true, 'reason_length' => mb_strlen(WR_REASON),
            'has_external_message' => true, 'external_message_length' => mb_strlen(WR_MESSAGE),
        ]);

    // No reason or message text in any audit row.
    foreach (DB::table('audit_logs')->where('case_id', $case->id)->pluck('metadata') as $metadata) {
        expect((string) $metadata)->not->toContain('Quokka')->and((string) $metadata)->not->toContain('contact the Ministry');
    }
})->with([
    'withdraw' => [S::IN_REVIEW, 'cases.withdraw', ['vendor_message' => WR_MESSAGE], TerminalCloseOut::REASON_WITHDRAWN],
    'reject' => [S::WITH_PS, 'cases.reject-final', ['vendor_message' => WR_MESSAGE, 'on_ps_instruction' => '1'], TerminalCloseOut::REASON_REJECTED],
]);

it('rolls the close-out back with the transition', function () {
    $cmo = caseUser(Role::Cmo);
    $case = wrCase($this, S::IN_REVIEW);
    $open = wrQuery($case, QueryStatus::OPEN);
    $active = assign($case, Role::Technocrat, caseUser(Role::Technocrat));

    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.transitioned', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(CaseTransitionService::class)->transition($case, S::WITHDRAWN, $cmo, 'Reason'))
        ->toThrow(RuntimeException::class);

    expect(DB::table('queries')->where('id', $open)->value('status'))->toBe('OPEN')
        ->and($active->fresh()->cancelled_at)->toBeNull()
        ->and(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('IN_REVIEW');
});

// 5. Notifications -------------------------------------------------------------------------

it('tells the submitter only of a final rejection, and the CMO of a vendor withdrawal', function () {
    Notification::fake();
    $cmo = caseUser(Role::Cmo);
    $colleague = vendorUser($this->org);
    $rejected = wrCase($this, S::WITH_PS);
    $withdrawn = wrCase($this, S::SUBMITTED);

    $this->actingAs($cmo)->post(route('cases.reject-final', $rejected), ['reason' => WR_REASON, 'on_ps_instruction' => '1'])->assertSessionHasNoErrors();
    $this->actingAs($this->vendor)->post(route('requests.withdraw', $withdrawn), ['reason' => 'Changed plans'])->assertSessionHasNoErrors();

    Notification::assertSentTo($this->vendor, VendorApplicationRejected::class, fn ($n) => $n->caseId === $rejected->id);
    Notification::assertNotSentTo($colleague, VendorApplicationRejected::class);
    Notification::assertSentTo($cmo, CaseWithdrawn::class, fn ($n) => $n->caseId === $withdrawn->id);
    Notification::assertNotSentTo($this->vendor, CaseWithdrawn::class);
});

it('does not tell the CMO when the CMO withdrew the case', function () {
    Notification::fake();
    $cmo = caseUser(Role::Cmo);

    $this->actingAs($cmo)->post(route('cases.withdraw', wrCase($this, S::IN_REVIEW)), ['reason' => WR_REASON])->assertSessionHasNoErrors();

    Notification::assertNotSentTo($cmo, CaseWithdrawn::class);
});

// 6. Leak -----------------------------------------------------------------------------------

it('gives the vendor the message to the vendor, never the internal reason', function () {
    $cmo = caseUser(Role::Cmo);
    $case = wrCase($this, S::WITH_PS);
    $this->actingAs($cmo)->post(route('cases.reject-final', $case), ['reason' => WR_REASON, 'vendor_message' => WR_MESSAGE, 'on_ps_instruction' => '1'])
        ->assertSessionHasNoErrors();

    $props = pageProps($this->actingAs($this->vendor)->get(route('requests.show', $case))->assertOk());
    $json = json_encode($props);
    expect($props['ended'])->toBe(['label' => 'Not approved', 'message' => WR_MESSAGE])
        ->and($props['can']['withdraw'])->toBeFalse()
        ->and($json)->not->toContain('Quokka');
    expectVendorSafe($props);

    $mail = (string) VendorApplicationRejected::for($case)->toMail($this->vendor)->render();
    $row = VendorApplicationRejected::for($case)->toDatabase($this->vendor);
    expect($mail)->toContain(WR_MESSAGE)->and($mail)->not->toContain('Quokka')
        ->and(json_encode($row))->not->toContain('Quokka')->and(json_encode($row))->not->toContain(WR_MESSAGE);

    // Staff see the reason and the message they sent.
    $staff = pageProps($this->actingAs($cmo)->get(route('cases.show', $case))->assertOk());
    expect($staff['ended'])->toMatchArray(['reason' => WR_REASON, 'vendor_message' => WR_MESSAGE, 'on_ps_instruction' => true]);
});

it('tells the vendor "No further details provided." when the Ministry left no message', function () {
    $case = wrCase($this, S::IN_REVIEW);
    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.withdraw', $case), ['reason' => WR_REASON])->assertSessionHasNoErrors();

    $props = pageProps($this->actingAs($this->vendor)->get(route('requests.show', $case))->assertOk());

    expect($props['ended'])->toBe(['label' => 'Withdrawn', 'message' => EndCase::NO_DETAILS])
        ->and(json_encode($props))->not->toContain('Quokka');
});

// 7. Flag ---------------------------------------------------------------------------------

it('hides and blocks T16 when workflow.final_reject_enabled is false', function () {
    $cmo = caseUser(Role::Cmo);
    $case = wrCase($this, S::WITH_PS);
    expect(EndCase::canRejectFinal($cmo, $case))->toBeTrue();

    config(['workflow.final_reject_enabled' => false]);

    expect(EndCase::canRejectFinal($cmo, $case))->toBeFalse()
        ->and(pageProps($this->actingAs($cmo)->get(route('cases.show', $case)))['can']['reject_final'])->toBeFalse();
    $this->actingAs($cmo)->post(route('cases.reject-final', $case), ['reason' => WR_REASON, 'on_ps_instruction' => '1'])->assertForbidden();
    expect(fn () => app(CaseTransitionService::class)->transition($case, S::REJECTED_FINAL, $cmo, WR_REASON, onPsInstruction: true))
        ->toThrow(InvalidTransition::class);
    expect(DB::table('cases')->where('id', $case->id)->value('status'))->toBe('WITH_PS');
});
