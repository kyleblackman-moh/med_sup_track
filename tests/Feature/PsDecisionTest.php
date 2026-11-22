<?php

use App\Enums\CaseStatus;
use App\Enums\ReturnKind;
use App\Enums\Role;
use App\Enums\TrackStatus;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RecordPsDecision;
use App\Workflow\Actions\SendToLegal;
use App\Workflow\CaseTransitionService;
use App\Workflow\TransitionRules;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// Slice 10d: the PS decision (grant, or return to the CMO with a kind and a
// reason), the return_kind column, banners and vendor silence. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A case at $status with a passed gate, a file number and a Legal officer assigned. */
function case10d(CaseStatus $status): ProcurementCase
{
    return makeCase([
        'status' => $status,
        'reference_no' => 'NR-10D-'.Str::upper(Str::random(8)),
        'submitted_at' => CarbonImmutable::now('UTC')->subDays(9),
        'technical_track' => TrackStatus::FAVOURABLE,
        'funding_track' => TrackStatus::CONFIRMED,
        'hardcopy_received_on' => CarbonImmutable::now('America/Port_of_Spain')->subDays(6)->toDateString(),
        'hardcopy_received_at' => CarbonImmutable::now('UTC')->subDays(6),
        'hardcopy_received_by' => caseUser(Role::CmoRegistry)->id,
        'file_no' => 'HE:8/2/50/10D',
    ]);
}

function legalRow10d(ProcurementCase $case, User $user): void
{
    (new CaseAssignment)->forceFill([
        'case_id' => $case->id, 'role' => SendToLegal::assignmentRole(), 'purpose' => SendToLegal::PURPOSE,
        'user_id' => $user->id, 'assigned_at' => now(),
    ])->save();
}

function ps10d(array $attributes = []): User
{
    return caseUser(Role::PsOffice, ['name' => 'PS '.Str::upper(Str::random(6)), ...$attributes]);
}

function row10d(ProcurementCase $case): object
{
    return DB::table('cases')->where('id', $case->id)->first();
}

function transitions10d(ProcurementCase $case): Collection
{
    return DB::table('case_transitions')->where('case_id', $case->id)->orderBy('id')->get();
}

function audits10d(ProcurementCase $case): Collection
{
    return DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.ps_decision')->get();
}

function fresh10d(ProcurementCase $case): ProcurementCase
{
    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

// 1. Policy matrix ---------------------------------------------------------------

const ROLES_10D = ['ps_office', 'cmo', 'cmo_registry', 'technocrat', 'cab_officer', 'legal_assigned', 'legal_unassigned', 'system_admin', 'auditor', 'vendor'];

const STATUSES_10D = ['DRAFT', 'IN_REVIEW', 'WITH_LEGAL', 'WITH_PS', 'RETURNED_TO_CMO', 'NON_OBJECTION_GRANTED', 'CLOSED'];

function matrixActor10d(string $role, ProcurementCase $case): User
{
    return match ($role) {
        'legal_assigned' => tap(caseUser(Role::LegalOfficer), fn (User $u) => legalRow10d($case, $u)),
        'legal_unassigned' => caseUser(Role::LegalOfficer),
        'vendor' => vendorUser($case->organisation()->first()),
        'cmo_registry', 'technocrat', 'cab_officer' => tap(caseUser($role), fn (User $u) => assign($case, $role, $u)),
        'ps_office' => ps10d(),
        default => caseUser($role),
    };
}

/** @return 'ok'|'error'|'forbidden'|'notfound' */
function expected10d(string $role, CaseStatus $status): string
{
    return match (true) {
        $role === 'vendor' => 'forbidden',                                                   // route middleware
        in_array($role, ['system_admin', 'auditor', 'legal_unassigned'], true) => 'notfound',  // cannot see it
        $status === CaseStatus::DRAFT => 'notfound',                                          // staff never see drafts
        $role !== 'ps_office' => 'forbidden',                                                 // no nonobjection.grant
        $status->isTerminal() => 'forbidden',                                                 // act() refuses terminal cases
        $status === CaseStatus::WITH_PS => 'ok',
        default => 'error',
    };
}

it('allows the PS decision exactly as the policy matrix says', function (string $role, string $statusValue, string $decision) {
    $status = CaseStatus::from($statusValue);
    $case = case10d($status);
    $actor = matrixActor10d($role, $case);
    $payload = $decision === 'grant'
        ? ['decision' => 'grant']
        : ['decision' => 'return_to_cmo', 'return_kind' => 'legal', 'reason' => 'Clause 4 needs work here.'];

    $response = $this->actingAs($actor)->post(route('cases.ps.decide', $case), $payload);
    $expected = expected10d($role, $status);

    match ($expected) {
        'ok' => $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success'),
        'error' => $response->assertRedirect()->assertSessionHasErrors('ps_decision'),
        'forbidden' => $response->assertForbidden(),
        'notfound' => $response->assertNotFound(),
    };

    $to = $decision === 'grant' ? 'NON_OBJECTION_GRANTED' : 'RETURNED_TO_CMO';
    expect(row10d($case)->status)->toBe($expected === 'ok' ? $to : $status->value)
        ->and(transitions10d($case))->toHaveCount($expected === 'ok' ? 1 : 0);
})->with(ROLES_10D)->with(STATUSES_10D)->with(['grant', 'return_to_cmo']);

// 2. Grant -----------------------------------------------------------------------

it('grants non-objection with one history row and the note as the reason', function () {
    $case = case10d(CaseStatus::WITH_PS);

    $this->actingAs(ps10d())->post(route('cases.ps.decide', $case), ['decision' => 'grant', 'reason' => 'Secret grant note text'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Non-objection granted.');

    $rows = transitions10d($case);
    $audit = audits10d($case);

    expect(row10d($case)->status)->toBe('NON_OBJECTION_GRANTED')
        ->and(row10d($case)->current_holder_role)->toBe('cmo')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->reason)->toBe('Secret grant note text')
        ->and($rows[0]->return_kind)->toBeNull()
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->metadata)->not->toContain('Secret grant note')
        ->and(json_decode($audit[0]->metadata, true))->toBe(['decision' => 'grant', 'return_kind' => null, 'reason_given' => true]);
});

it('keeps the first PS decision and names it to the second user', function () {
    $case = case10d(CaseStatus::WITH_PS);
    $first = ps10d(['name' => 'First PS Officer']);

    $this->actingAs($first)->post(route('cases.ps.decide', $case), ['decision' => 'grant'])->assertSessionHasNoErrors();
    $this->actingAs(ps10d())->post(route('cases.ps.decide', $case), ['decision' => 'return_to_cmo', 'return_kind' => 'technical', 'reason' => 'I would have returned it.'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('info', fn (string $m) => str_contains($m, 'already recorded (Non-objection granted) by First PS Officer'));

    $result = app(RecordPsDecision::class)->handle(fresh10d($case), ps10d(), 'grant');

    expect($result->already)->toBeTrue()
        ->and(row10d($case)->status)->toBe('NON_OBJECTION_GRANTED')
        ->and(transitions10d($case))->toHaveCount(1)
        ->and(audits10d($case))->toHaveCount(1);
});

it('changes nothing when the PS decision audit fails', function (string $decision) {
    $case = case10d(CaseStatus::WITH_PS);
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'audit_logs') && in_array('case.ps_decision', $query->bindings, true)) {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(RecordPsDecision::class)->handle($case, ps10d(), $decision, 'Clause 4 needs work here.', $decision === 'grant' ? null : 'legal'))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(row10d($case)->status)->toBe('WITH_PS')->and(transitions10d($case))->toHaveCount(0);
})->with(['grant', 'return_to_cmo']);

// 3. Return ----------------------------------------------------------------------

it('validates the decision, query type and reason', function (array $payload, ?string $errorKey) {
    $case = case10d(CaseStatus::WITH_PS);

    $response = $this->actingAs(ps10d())->post(route('cases.ps.decide', $case), $payload);

    if ($errorKey) {
        $response->assertSessionHasErrors($errorKey);
        expect(row10d($case)->status)->toBe('WITH_PS')->and(transitions10d($case))->toHaveCount(0);
    } else {
        $response->assertSessionHasNoErrors();
        expect(transitions10d($case))->toHaveCount(1);
    }
})->with([
    'no decision' => [[], 'decision'],
    'unknown decision' => [['decision' => 'reject'], 'decision'],
    'return, no kind' => [['decision' => 'return_to_cmo', 'reason' => 'Clause 4 needs work here.'], 'return_kind'],
    'return, bad kind' => [['decision' => 'return_to_cmo', 'return_kind' => 'financial', 'reason' => 'Clause 4 needs work here.'], 'return_kind'],
    'return, no reason' => [['decision' => 'return_to_cmo', 'return_kind' => 'legal'], 'reason'],
    'return, short reason' => [['decision' => 'return_to_cmo', 'return_kind' => 'legal', 'reason' => 'too short'], 'reason'],
    'return, blank reason' => [['decision' => 'return_to_cmo', 'return_kind' => 'legal', 'reason' => '            '], 'reason'],
    'return, 501 chars' => [['decision' => 'return_to_cmo', 'return_kind' => 'technical', 'reason' => str_repeat('a', 501)], 'reason'],
    'grant with a kind' => [['decision' => 'grant', 'return_kind' => 'legal'], 'return_kind'],
    'grant note too long' => [['decision' => 'grant', 'reason' => str_repeat('a', 501)], 'reason'],
    'grant, no note' => [['decision' => 'grant'], null],
    'return, technical, good' => [['decision' => 'return_to_cmo', 'return_kind' => 'technical', 'reason' => 'Specification needs checking.'], null],
]);

it('returns to the CMO with the kind and reason on the history row, audit without text', function (string $kind) {
    $case = case10d(CaseStatus::WITH_PS);

    $this->actingAs(ps10d())->post(route('cases.ps.decide', $case), [
        'decision' => 'return_to_cmo', 'return_kind' => $kind, 'reason' => '  Secret PS reason text here.  ',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Case returned to the CMO with a query.');

    $rows = transitions10d($case);
    $audit = audits10d($case);

    expect(row10d($case)->status)->toBe('RETURNED_TO_CMO')
        ->and(row10d($case)->current_holder_role)->toBe('cmo')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->from_status)->toBe('WITH_PS')
        ->and($rows[0]->reason)->toBe('Secret PS reason text here.')
        ->and($rows[0]->return_kind)->toBe($kind)
        ->and($audit[0]->metadata)->not->toContain('Secret PS reason')
        ->and(json_decode($audit[0]->metadata, true))->toBe(['decision' => 'return_to_cmo', 'return_kind' => $kind, 'reason_given' => true]);
})->with(['legal', 'technical']);

// 4. Column, trigger and service --------------------------------------------------

it('rejects any other return_kind value at the database', function () {
    $case = case10d(CaseStatus::WITH_PS);

    expect(fn () => DB::table('case_transitions')->insert([
        'case_id' => $case->id, 'from_status' => 'WITH_PS', 'to_status' => 'RETURNED_TO_CMO',
        'actor_id' => ps10d()->id, 'reason' => 'x', 'return_kind' => 'financial', 'created_at' => now(),
    ]))->toThrow(QueryException::class, 'ck_case_transitions_return_kind');
});

it('still blocks UPDATE and DELETE on case_transitions, the new column included', function () {
    $case = case10d(CaseStatus::WITH_PS);
    app(RecordPsDecision::class)->handle($case, ps10d(), 'return_to_cmo', 'Clause 4 needs work here.', 'legal');
    $id = transitions10d($case)[0]->id;

    // The model refuses first (no SQL is sent), then the trigger refuses the raw
    // UPDATE. A trigger THROW ends the test's transaction on SQL Server, so each
    // raw statement is the last thing its test does.
    expect(fn () => CaseTransition::findOrFail($id)->forceFill(['return_kind' => 'technical'])->save())->toThrow(LogicException::class)
        ->and(transitions10d($case)[0]->return_kind)->toBe('legal')
        ->and(fn () => DB::table('case_transitions')->where('id', $id)->update(['return_kind' => 'technical']))->toThrow(QueryException::class);
});

it('still blocks raw DELETE on case_transitions after the new column', function () {
    $case = case10d(CaseStatus::WITH_PS);
    app(RecordPsDecision::class)->handle($case, ps10d(), 'return_to_cmo', 'Clause 4 needs work here.', 'technical');

    expect(fn () => DB::table('case_transitions')->where('case_id', $case->id)->delete())->toThrow(QueryException::class);
});

it('refuses a return_kind on any transition other than a PS return', function () {
    $case = case10d(CaseStatus::WITH_LEGAL);
    $officer = caseUser(Role::LegalOfficer);
    legalRow10d($case, $officer);

    expect(fn () => app(CaseTransitionService::class)->transition($case, CaseStatus::RETURNED_TO_CMO, $officer, 'Clause 4 needs work here.', ReturnKind::Legal))
        ->toThrow(InvalidArgumentException::class);
    expect(row10d($case)->status)->toBe('WITH_LEGAL');
});

it('keeps return_kind null on every other transition', function () {
    $case = case10d(CaseStatus::WITH_LEGAL);
    $officer = caseUser(Role::LegalOfficer);
    legalRow10d($case, $officer);
    app(RecordLegalDecision::class)->handle($case, $officer, 'return_to_cmo', 'Clause 4 needs the vendor.');

    expect(transitions10d($case)->pluck('return_kind')->all())->toBe([null]);
});

// 5. Banner, grant statement and outcome ---------------------------------------

it('names the PS office and the query kind in the banner', function (string $kind, string $label) {
    $case = case10d(CaseStatus::WITH_PS);
    app(RecordPsDecision::class)->handle($case, ps10d(['name' => 'Banner PS']), 'return_to_cmo', 'Please clarify clause 4.', $kind);

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());

    expect($props['returned'])->toMatchArray([
        'source' => 'PS office', 'kind' => $label, 'reason' => 'Please clarify clause 4.', 'by' => 'Banner PS',
    ])->and($props['granted'])->toBeNull()
        ->and($props['ps_outcome'])->toMatchArray(['outcome' => "Returned to CMO ({$label})", 'by' => 'Banner PS']);
})->with([
    'legal' => ['legal', 'legal-related query'],
    'technical' => ['technical', 'technical query'],
]);

it('keeps the 10c wording for a Legal return', function () {
    $case = case10d(CaseStatus::WITH_LEGAL);
    $officer = caseUser(Role::LegalOfficer);
    legalRow10d($case, $officer);
    app(RecordLegalDecision::class)->handle($case, $officer, 'return_to_cmo', 'Clause 4 needs the vendor.');

    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case))->assertOk());
    expect($props['returned'])->toMatchArray(['source' => 'Legal', 'kind' => null]);
});

it('shows the grant statement on Overview and replaces the form with the outcome', function () {
    $case = case10d(CaseStatus::WITH_PS);
    $ps = ps10d(['name' => 'Granting PS']);

    $before = pageProps($this->actingAs($ps)->get(route('cases.show', $case))->assertOk());
    expect($before['can']['record_ps_decision'])->toBeTrue()
        ->and(array_column($before['ps_form']['decisions'], 'label'))->toBe(['Grant non-objection', 'Return to CMO with a query'])
        ->and(array_column($before['ps_form']['kinds'], 'label'))->toBe(['Legal-related', 'Technical'])
        ->and($before['ps_outcome'])->toBeNull();

    $this->actingAs($ps)->post(route('cases.ps.decide', $case), ['decision' => 'grant', 'reason' => 'All in order.'])->assertSessionHasNoErrors();

    $after = pageProps($this->actingAs($ps)->get(route('cases.show', $case))->assertOk());
    expect($after['can']['record_ps_decision'])->toBeFalse()
        ->and($after['ps_form'])->toBeNull()
        ->and($after['ps_outcome'])->toMatchArray(['outcome' => 'Non-objection granted', 'by' => 'Granting PS'])
        ->and($after['granted'])->toMatchArray(['by' => 'Granting PS', 'note' => 'All in order.'])
        ->and($after['granted']['at'])->not->toBeNull()
        ->and($after['returned'])->toBeNull();
});

it('gives the CMO no PS form at WITH_PS', function () {
    $props = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', case10d(CaseStatus::WITH_PS)))->assertOk());

    expect($props['can']['record_ps_decision'])->toBeFalse()->and($props['ps_form'])->toBeNull();
});

// 6. Vendor silence --------------------------------------------------------------

it('shows the vendor the same in-progress view at WITH_PS, after a grant and after a PS return', function (string $decision) {
    $case = case10d(CaseStatus::WITH_PS);
    // A real case reaches the PS with its history; without it the Progress
    // timeline falls back to "Submitted" only and the first transition changes it.
    foreach ([[null, 'SUBMITTED', 9], ['SUBMITTED', 'IN_REVIEW', 5], ['IN_REVIEW', 'WITH_PS', 2]] as [$from, $to, $days]) {
        DB::table('case_transitions')->insert([
            'case_id' => $case->id, 'from_status' => $from, 'to_status' => $to, 'created_at' => CarbonImmutable::now('UTC')->subDays($days),
        ]);
    }
    $vendor = vendorUser($case->organisation()->first());
    $view = fn () => collect(pageProps($this->actingAs($vendor)->get(route('requests.show', $case))->assertOk()))->except(['auth', 'flash', 'errors', 'quote'])->all(); // quote: the starter kit's random shared prop

    $before = $view();
    app(RecordPsDecision::class)->handle(fresh10d($case), ps10d(['name' => 'Leaky PS Name']), $decision,
        'Reason must not leak to vendor', $decision === 'grant' ? null : 'legal');
    $after = $view();

    // updated_at moves with any change; nothing else may differ.
    $strip = fn (array $props) => [...$props, 'case' => Arr::except($props['case'], ['updated_at'])];
    expect($strip($after))->toBe($strip($before))
        ->and($after['case']['status_label'])->toBe(CaseStatus::WITH_PS->vendorLabel());

    expectVendorSafe($after);
    $scan = scanProps($after);
    foreach (['decision', 'return_kind', 'kind', 'reason', 'note', 'granted', 'returned', 'ps_outcome', 'ps_form'] as $key) {
        expect($scan['keys'])->not->toContain($key);
    }
    foreach ($scan['strings'] as $string) {
        expect($string)->not->toContain('Leaky PS Name')
            ->and($string)->not->toContain('must not leak')
            ->and($string)->not->toContain('granted')
            ->and($string)->not->toContain('Returned to CMO')
            ->and($string)->not->toContain('legal-related');
    }
})->with(['grant', 'return_to_cmo']);

it('gives WITH_PS, NON_OBJECTION_GRANTED and RETURNED_TO_CMO the same vendor label', function () {
    expect(CaseStatus::NON_OBJECTION_GRANTED->vendorLabel())->toBe(CaseStatus::WITH_PS->vendorLabel())
        ->and(CaseStatus::RETURNED_TO_CMO->vendorLabel())->toBe(CaseStatus::WITH_PS->vendorLabel())
        ->and(CaseStatus::NON_OBJECTION_GRANTED->vendorGroup())->toBe(CaseStatus::WITH_PS->vendorGroup());
});

// 7. T16 -------------------------------------------------------------------------

it('enables T16 for the CMO from WITH_PS and RETURNED_TO_CMO, with a reason (Q3 decided: on PS instruction)', function () {
    $t16 = collect(TransitionRules::all())->firstWhere('code', 'T16');

    expect($t16->enabled)->toBeTrue()
        ->and($t16->requiresReason)->toBeTrue()
        ->and(TransitionRules::find(CaseStatus::WITH_PS, CaseStatus::REJECTED_FINAL)?->code)->toBe('T16')
        ->and(TransitionRules::find(CaseStatus::RETURNED_TO_CMO, CaseStatus::REJECTED_FINAL)?->code)->toBe('T16')
        ->and(TransitionRules::find(CaseStatus::IN_REVIEW, CaseStatus::REJECTED_FINAL))->toBeNull();
});

// 8. Second round ----------------------------------------------------------------

it('lets the PS decide again after the case comes back to WITH_PS', function () {
    $case = case10d(CaseStatus::WITH_PS);
    app(RecordPsDecision::class)->handle($case, ps10d(), 'return_to_cmo', 'First round: clarify clause 4.', 'legal');

    // T17 directly through the service. Slice 10e: T17 needs a reason (the CMO's note).
    app(CaseTransitionService::class)->transition(fresh10d($case), CaseStatus::WITH_PS, caseUser(Role::Cmo), 'Answer attached for the PS.');

    $second = app(RecordPsDecision::class)->handle(fresh10d($case), ps10d(), 'grant');

    expect($second->already)->toBeFalse()
        ->and(row10d($case)->status)->toBe('NON_OBJECTION_GRANTED')
        ->and(transitions10d($case)->pluck('to_status')->all())->toBe(['RETURNED_TO_CMO', 'WITH_PS', 'NON_OBJECTION_GRANTED'])
        ->and(audits10d($case))->toHaveCount(2);
});

it('lets the still-assigned Legal officer decide again after the case comes back to WITH_LEGAL', function () {
    $case = case10d(CaseStatus::WITH_LEGAL);
    $officer = caseUser(Role::LegalOfficer);
    legalRow10d($case, $officer);
    app(RecordLegalDecision::class)->handle($case, $officer, 'return_to_cmo', 'First round: clause 4 needs work.');

    // T12 directly through the service. Slice 10e: T12 needs a reason (the CMO's note).
    app(CaseTransitionService::class)->transition(fresh10d($case), CaseStatus::WITH_LEGAL, caseUser(Role::Cmo), 'Answer attached for Legal.');

    $second = app(RecordLegalDecision::class)->handle(fresh10d($case), $officer, 'confirmed');

    expect($second->already)->toBeFalse()
        ->and(row10d($case)->status)->toBe('WITH_PS')
        ->and(transitions10d($case)->pluck('to_status')->all())->toBe(['RETURNED_TO_CMO', 'WITH_LEGAL', 'WITH_PS']);
});
