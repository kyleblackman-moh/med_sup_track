<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Events\CaseTransitioned;
use App\Events\HardcopyRecorded;
use App\Models\CaseAssignment;
use App\Models\ProcurementCase;
use App\Models\QueryRoute;
use App\Models\User;
use App\Notifications\CaseNotification;
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
use App\Notifications\Queries\DirectQueryActivity;
use App\Notifications\Queries\ForwardRequired;
use App\Notifications\Queries\ForwardResponse;
use App\Notifications\Queries\QueryAnswered;
use App\Notifications\Queries\QueryRaised;
use App\Notifications\Vendor\VendorApplicationApproved;
use App\Notifications\Vendor\VendorApplicationRejected;
use App\Notifications\Vendor\VendorNotification;
use App\Notifications\Vendor\VendorQueryReceived;
use App\Notifications\Vendor\VendorQueryResponseSent;
use App\Queries\QueryAccess;
use App\Queries\QueryService;
use App\Submissions\SubmitCase;
use App\Workflow\Actions\RecordFundingConfirmation;
use App\Workflow\Actions\RecordTechnicalReview;
use App\Workflow\TransitionRules;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\QueryRouteSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

// NOTIF-2 (Step 7): each notification reaches exactly its people, vendors hear
// only four things and never anything internal, via-CMO queries wait for the
// forward, a rolled-back action sends nothing, and recipients follow
// permissions, not role names. The dev database's own accounts are disabled
// inside each test's transaction, so only this test's users count.
// DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    $this->seed(QueryRouteSeeder::class);
    DB::table('users')->update(['status' => User::STATUS_DISABLED]);
    Notification::fake();

    $this->a = qvActors();
    $this->a['colleague'] = vendorUser($this->a['vendor']->organisation); // same organisation, not the submitter
});

afterEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    QueryAccess::forgetVendorRoles();
});

/** The user ids that received $class, sorted, each once. */
function nrTo(string $class): array
{
    $ids = [];
    foreach (Notification::getFacadeRoot()->sentNotifications() as $byId) {
        foreach ($byId as $id => $byClass) {
            if (isset($byClass[$class])) {
                $ids[] = (int) $id;
            }
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);

    return $ids;
}

/** The ids of the named actors, sorted. */
function nrIds(string ...$keys): array
{
    $ids = array_map(fn (string $k) => (int) test()->a[$k]->id, $keys);
    sort($ids);

    return $ids;
}

/** Every notification instance a user received. @return list<CaseNotification> */
function nrReceived(User $user): array
{
    $all = [];
    foreach (Notification::getFacadeRoot()->sentNotifications()[User::class][$user->id] ?? [] as $sends) {
        foreach ($sends as $send) {
            $all[] = $send['notification'];
        }
    }

    return $all;
}

function nrTransition(ProcurementCase $case, S $from, S $to, ?User $actor = null): void
{
    CaseTransitioned::dispatch($case, $from, $to, $actor ?? test()->a['cmo']);
}

function nrRoute(string $from, string $to): QueryRoute
{
    return QueryRoute::query()->where('from_role', $from)->where('to_role', $to)->firstOrFail();
}

// CMO --------------------------------------------------------------------------------------

it('tells intake and the CMO about a submission (action), and never the PS office', function () {
    nrTransition(qvCase($this->a, S::IN_REVIEW), S::DRAFT, S::SUBMITTED, $this->a['vendor']);

    expect(nrTo(CaseSubmitted::class))->toBe(nrIds('cmo', 'registry'))
        ->and(nrTo(CaseSubmitted::class))->not->toContain($this->a['ps']->id);
});

it('tells the CMO when the hardcopy is received (info only)', function () {
    $case = qvCase($this->a, S::IN_REVIEW);
    HardcopyRecorded::dispatch($case->id);

    expect(nrTo(HardcopyReceived::class))->toBe(nrIds('cmo'))
        ->and(nrTo(ReadyToPrepareFile::class))->toBe([])
        ->and(HardcopyReceived::for($case)->kind())->toBe(CaseNotification::INFO);
});

it('tells the CMO each review outcome (info), then Ready to Prepare File (action) once all three gates are met', function () {
    $case = qvCase($this->a, S::IN_REVIEW);
    DB::table('cases')->where('id', $case->id)->update([
        'hardcopy_received_on' => now()->toDateString(), 'hardcopy_received_at' => now(),
        'hardcopy_received_by' => $this->a['registry']->id, 'hardcopy_receipt_method' => 'manual',
    ]);

    app(RecordTechnicalReview::class)->handle(ProcurementCase::withoutGlobalScopes()->find($case->id), $this->a['tech'], 'favourable');
    expect(nrTo(TechnicalReviewRecorded::class))->toBe(nrIds('cmo'))
        ->and(nrTo(ReadyToPrepareFile::class))->toBe([]);

    app(RecordFundingConfirmation::class)->handle(ProcurementCase::withoutGlobalScopes()->find($case->id), $this->a['cab'], 'not_confirmed', 'No budget line for this item yet.');
    expect(nrTo(FundingReviewRecorded::class))->toBe(nrIds('cmo'))
        ->and(nrTo(ReadyToPrepareFile::class))->toBe([]);

    app(RecordFundingConfirmation::class)->handle(ProcurementCase::withoutGlobalScopes()->find($case->id), $this->a['cab'], 'confirmed');
    expect(nrTo(ReadyToPrepareFile::class))->toBe(nrIds('cmo'));
    Notification::assertSentToTimes($this->a['cmo'], ReadyToPrepareFile::class, 1);

    $titles = collect(nrReceived($this->a['cmo']))->filter(fn ($n) => $n instanceof FundingReviewRecorded)->map->title()->all();
    expect(array_values($titles))->toBe([
        'Funding Recorded on '.$case->reference_no.': Funding not confirmed',
        'Funding Recorded on '.$case->reference_no.': Funding confirmed',
    ])->and(TechnicalReviewRecorded::for($case)->kind())->toBe(CaseNotification::INFO)
        ->and(ReadyToPrepareFile::for($case)->kind())->toBe(CaseNotification::ACTION);
});

it('tells the CMO Ready to Prepare File when the hardcopy is the last gate to arrive', function () {
    $case = qvCase($this->a, S::IN_REVIEW);
    DB::table('cases')->where('id', $case->id)->update([
        'technical_track' => 'FAVOURABLE', 'funding_track' => 'CONFIRMED',
        'hardcopy_received_on' => now()->toDateString(), 'hardcopy_received_at' => now(),
        'hardcopy_received_by' => $this->a['registry']->id, 'hardcopy_receipt_method' => 'manual',
    ]);
    HardcopyRecorded::dispatch($case->id);

    expect(nrTo(ReadyToPrepareFile::class))->toBe(nrIds('cmo'));
});

it('tells the CMO a case was returned (action), naming who returned it', function (S $from, string $by) {
    $case = qvCase($this->a, S::WITH_PS);
    nrTransition($case, $from, S::RETURNED_TO_CMO, $this->a[$from === S::WITH_PS ? 'ps' : 'legal']);

    expect(nrTo(ReturnedToCmo::class))->toBe(nrIds('cmo'));
    $note = collect(nrReceived($this->a['cmo']))->first(fn ($n) => $n instanceof ReturnedToCmo);
    expect($note->title())->toBe('Case '.$case->reference_no.' Returned to the CMO by '.$by)
        ->and($note->kind())->toBe(CaseNotification::ACTION);
})->with([
    'by Legal' => [S::WITH_LEGAL, 'Legal'],
    'by the PS' => [S::WITH_PS, 'PS office'],
]);

it('tells the CMO Legal confirmed (info) and the PS office the file arrived (action)', function () {
    $case = qvCase($this->a, S::WITH_PS);
    nrTransition($case, S::WITH_LEGAL, S::WITH_PS, $this->a['legal']);

    expect(nrTo(LegalConfirmed::class))->toBe(nrIds('cmo'))
        ->and(nrTo(FileSentToPs::class))->toBe(nrIds('ps'))
        ->and(LegalConfirmed::for($case)->kind())->toBe(CaseNotification::INFO);
});

it('does not send Legal Confirmed when a returned case goes back to the PS', function () {
    nrTransition(qvCase($this->a, S::WITH_PS), S::RETURNED_TO_CMO, S::WITH_PS);

    expect(nrTo(LegalConfirmed::class))->toBe([])
        ->and(nrTo(FileSentToPs::class))->toBe(nrIds('ps'));
});

it('tells the CMO to issue the letter (action) and the submitter it was approved', function () {
    $case = qvCase($this->a, S::NON_OBJECTION_GRANTED);
    nrTransition($case, S::WITH_PS, S::NON_OBJECTION_GRANTED, $this->a['ps']);

    expect(nrTo(NonObjectionGranted::class))->toBe(nrIds('cmo'))
        ->and(NonObjectionGranted::for($case)->title())->toBe('Issue Letter for '.$case->reference_no)
        ->and(nrTo(VendorApplicationApproved::class))->toBe(nrIds('vendor'));
});

it('tells the CMO when the vendor withdraws a submitted case (info), and nobody for a staff or draft withdrawal', function () {
    $case = qvCase($this->a, S::IN_REVIEW);

    nrTransition($case, S::SUBMITTED, S::WITHDRAWN, $this->a['vendor']);
    expect(nrTo(CaseWithdrawn::class))->toBe(nrIds('cmo'))
        ->and(CaseWithdrawn::for($case)->kind())->toBe(CaseNotification::INFO);

    Notification::fake();
    nrTransition($case, S::IN_REVIEW, S::WITHDRAWN, $this->a['cmo']);
    nrTransition($case, S::DRAFT, S::WITHDRAWN, $this->a['vendor']);
    expect(Notification::getFacadeRoot()->sentNotifications())->toBe([]);
});

// Technocrats, CAB, Legal, PS --------------------------------------------------------------

it('tells a Technocrat assigned for review, and CAB asked to confirm funding, in parallel (action)', function () {
    $case = makeCase(['org_id' => $this->a['vendor']->org_id, 'created_by' => $this->a['vendor']->id, 'status' => S::IN_REVIEW, 'reference_no' => 'NR-NR-'.Str::upper(Str::random(6))]);
    foreach ([['technocrat', 'technical', 'tech'], ['cab_officer', 'funding', 'cab']] as [$role, $purpose, $key]) {
        (new CaseAssignment)->forceFill(['case_id' => $case->id, 'role' => $role, 'purpose' => $purpose, 'user_id' => $this->a[$key]->id, 'assigned_at' => now()])->save();
    }

    expect(nrTo(CaseAssigned::class))->toBe(nrIds('tech', 'cab'));
    $titles = fn (string $k) => collect(nrReceived($this->a[$k]))->map->title()->all();
    expect($titles('tech'))->toBe(['Technical Review Requested: '.$case->reference_no])
        ->and($titles('cab'))->toBe(['Funding Confirmation Requested: '.$case->reference_no]);
});

it('tells the assigned Legal officer the file arrived (action), else every legal.confirm holder', function () {
    nrTransition(qvCase($this->a, S::WITH_LEGAL), S::FILE_PREPARED, S::WITH_LEGAL);
    expect(nrTo(FileSentToLegal::class))->toBe(nrIds('legal'));

    Notification::fake();
    $unassigned = makeCase(['org_id' => $this->a['vendor']->org_id, 'created_by' => $this->a['vendor']->id, 'status' => S::WITH_LEGAL, 'reference_no' => 'NR-NR-'.Str::upper(Str::random(6))]);
    nrTransition($unassigned, S::FILE_PREPARED, S::WITH_LEGAL);
    expect(nrTo(FileSentToLegal::class))->toBe(nrIds('legal', 'legal_unassigned', 'legal_named'));
});

// Queries ----------------------------------------------------------------------------------

it('holds a Legal query for the CMO and tells the Technocrats only once forwarded, then routes the answer back', function () {
    $case = qvCase($this->a, S::WITH_LEGAL);
    $route = nrRoute('legal_officer', 'technocrat');

    $query = qvDrive($this->a, $case, $route, 'legal', 'tech', 'tech_named', 'held');
    expect(nrTo(ForwardRequired::class))->toBe(nrIds('cmo'))
        ->and(nrTo(QueryRaised::class))->toBe([]);

    $held = qvHeld($query);
    $this->actingAs($this->a['cmo'])->post(route('cases.queries.forward', [$case, $query->id, $held->id]), ['officer_id' => $this->a['tech_named']->id])->assertSessionHasNoErrors();
    expect(nrTo(QueryRaised::class))->toBe(nrIds('tech', 'tech_named'))
        ->and(nrTo(QueryRaised::class))->not->toContain($this->a['tech_unassigned']->id);

    $this->actingAs($this->a['tech_named'])->post(route('cases.queries.reply', [$case, $query->id]), ['body' => 'Answer words NR'])->assertSessionHasNoErrors();
    expect(nrTo(ForwardResponse::class))->toBe(nrIds('cmo'))
        ->and(nrTo(QueryAnswered::class))->toBe([]);

    $this->actingAs($this->a['cmo'])->post(route('cases.queries.forward', [$case, $query->id, qvHeld($query)->id]))->assertSessionHasNoErrors();
    expect(nrTo(QueryAnswered::class))->toBe(nrIds('legal'));

    foreach ([ForwardRequired::class, ForwardResponse::class, QueryRaised::class, QueryAnswered::class] as $class) {
        expect((new $class($case->id, $case->reference_no))->kind())->toBe(CaseNotification::ACTION);
    }
});

it('holds a PS query for the CMO and tells Legal only once forwarded, then the PS the answer', function () {
    $case = qvCase($this->a, S::WITH_PS);
    $query = qvDrive($this->a, $case, nrRoute('ps_office', 'legal_officer'), 'ps', 'legal', null, 'held');

    expect(nrTo(ForwardRequired::class))->toBe(nrIds('cmo'))
        ->and(nrTo(QueryRaised::class))->toBe([]);

    $this->actingAs($this->a['cmo'])->post(route('cases.queries.forward', [$case, $query->id, qvHeld($query)->id]))->assertSessionHasNoErrors();
    expect(nrTo(QueryRaised::class))->toBe(nrIds('legal'));

    $this->actingAs($this->a['legal'])->post(route('cases.queries.reply', [$case, $query->id]), ['body' => 'Legal answer NR'])->assertSessionHasNoErrors();
    $this->actingAs($this->a['cmo'])->post(route('cases.queries.forward', [$case, $query->id, qvHeld($query)->id]))->assertSessionHasNoErrors();
    expect(nrTo(QueryAnswered::class))->toBe(nrIds('ps'));
});

it('runs a direct Technocrat and vendor query: vendor told, CMO watching (info), Technocrat told of the answer', function () {
    $case = qvCase($this->a, S::IN_REVIEW);
    $query = qvDrive($this->a, $case, nrRoute('technocrat', 'vendor_applicant'), 'tech', 'vendor', null, 'delivered');

    expect(nrTo(VendorQueryReceived::class))->toBe(nrIds('vendor'))
        ->and(nrTo(DirectQueryActivity::class))->toBe(nrIds('cmo'))
        ->and(nrTo(QueryRaised::class))->toBe([]);

    $this->actingAs($this->a['vendor'])->post(route('requests.queries.reply', [$case, $query->id]), ['body' => 'Vendor answer NR'])->assertSessionHasNoErrors();

    expect(nrTo(QueryAnswered::class))->toBe(nrIds('tech'))
        ->and(nrTo(VendorQueryResponseSent::class))->toBe(nrIds('vendor'));
    Notification::assertSentToTimes($this->a['cmo'], DirectQueryActivity::class, 2);
    expect((new DirectQueryActivity($case->id, $case->reference_no))->kind())->toBe(CaseNotification::INFO);
});

// Vendors ----------------------------------------------------------------------------------

it('gives a vendor nothing for any transition except approved and rejected', function () {
    $case = qvCase($this->a, S::IN_REVIEW);

    $failures = [];
    foreach (TransitionRules::all() as $rule) {
        foreach ($rule->from as $from) {
            foreach ([$this->a['cmo'], $this->a['vendor']] as $actor) {
                Notification::fake();
                nrTransition($case, $from, $rule->to, $actor);
                $got = collect([$this->a['vendor'], $this->a['colleague'], $this->a['other_vendor']])
                    ->flatMap(fn (User $u) => array_map(fn ($n) => $u->id.':'.class_basename($n), nrReceived($u)))->all();

                $expected = match ($rule->to) {
                    S::NON_OBJECTION_GRANTED => [$this->a['vendor']->id.':VendorApplicationApproved'],
                    S::REJECTED_FINAL => [$this->a['vendor']->id.':VendorApplicationRejected'],
                    default => [],
                };
                if ($got !== $expected) {
                    $failures[] = "{$rule->code} {$from->value} -> {$rule->to->value}: ".json_encode($got);
                }
            }
        }
    }

    // Nor for the hardcopy, review assignments or a staff-only query.
    Notification::fake();
    HardcopyRecorded::dispatch($case->id);
    qvDrive($this->a, qvCase($this->a, S::WITH_LEGAL), nrRoute('legal_officer', 'technocrat'), 'legal', 'tech', null, 'answered');
    foreach (['vendor', 'colleague', 'other_vendor'] as $k) {
        if (nrReceived($this->a[$k]) !== []) {
            $failures[] = "{$k}: told about a hardcopy or an internal query";
        }
    }

    expect($failures)->toBe([]);
});

it('gives vendors only the four vendor classes, never a staff notification, whatever happens', function () {
    $case = qvCase($this->a, S::IN_REVIEW);
    $query = qvDrive($this->a, $case, nrRoute('technocrat', 'vendor_applicant'), 'tech', 'vendor', null, 'delivered');
    $this->actingAs($this->a['vendor'])->post(route('requests.queries.reply', [$case, $query->id]), ['body' => 'Vendor answer NR']);
    nrTransition($case, S::WITH_PS, S::NON_OBJECTION_GRANTED, $this->a['ps']);
    nrTransition($case, S::WITH_PS, S::REJECTED_FINAL, $this->a['ps']);

    $classes = array_map(fn ($n) => $n::class, nrReceived($this->a['vendor']));
    expect(array_values(array_unique($classes)))->toEqualCanonicalizing([
        VendorQueryReceived::class, VendorQueryResponseSent::class, VendorApplicationApproved::class, VendorApplicationRejected::class,
    ]);
    foreach (nrReceived($this->a['vendor']) as $n) {
        expect($n)->toBeInstanceOf(VendorNotification::class)
            ->and($n->via($this->a['vendor']))->toContain('mail');
    }
    expect(nrReceived($this->a['colleague']))->toBe([]);
});

it('never puts query text, reasons or internal document names in a vendor notification', function () {
    $case = qvCase($this->a, S::IN_REVIEW);
    DB::table('documents')->insert([
        'id' => Str::lower((string) Str::ulid()), 'case_id' => $case->id, 'purpose' => 'bids', 'version' => 1,
        'original_name' => 'LEAKDOC Internal Minute.pdf', 'storage_key' => "cases/{$case->id}/leak",
        'mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64),
        'scan_status' => 'CLEAN', 'visibility' => 'INTERNAL', 'uploaded_by' => $this->a['tech']->id,
        'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $query = app(QueryService::class)->raise(ProcurementCase::withoutGlobalScopes()->find($case->id), $this->a['tech'], nrRoute('technocrat', 'vendor_applicant')->id, 'LEAKBODY please send the dossier');
    test()->actingAs($this->a['vendor'])->post(route('requests.queries.reply', [$case, $query->id]), ['body' => 'LEAKREPLY here it is'])->assertSessionHasNoErrors();
    nrTransition($case, S::WITH_PS, S::NON_OBJECTION_GRANTED, $this->a['ps']);
    nrTransition($case, S::WITH_PS, S::REJECTED_FINAL, $this->a['ps']);

    $sent = nrReceived($this->a['vendor']);
    expect($sent)->toHaveCount(4);
    foreach ($sent as $n) {
        $payload = json_encode($n->toDatabase($this->a['vendor']));
        $mail = (string) $n->toMail($this->a['vendor'])->render();
        expect(array_keys($n->toDatabase($this->a['vendor'])))->toBe(['case_id', 'reference', 'event', 'kind', 'title', 'url']);
        foreach (['LEAKBODY', 'LEAKREPLY', 'LEAKDOC', 'dossier', $this->a['tech']->name, 'Technocrat', '/cases/'] as $secret) {
            expect($payload)->not->toContain($secret)
                ->and($mail)->not->toContain($secret);
        }
        expect($payload)->toContain($case->reference_no);
    }
});

// Rollback and permissions -----------------------------------------------------------------

it('sends nothing when the transaction around an action rolls back', function () {
    [$draft, $vendor] = readyDraft();
    try {
        DB::transaction(function () use ($draft, $vendor) {
            app(SubmitCase::class)->handle($draft, $vendor);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    $case = qvCase($this->a, S::IN_REVIEW);
    try {
        DB::transaction(function () use ($case) {
            app(QueryService::class)->raise(ProcurementCase::withoutGlobalScopes()->find($case->id), $this->a['tech'], nrRoute('technocrat', 'vendor_applicant')->id, 'Rolled back words');
            app(RecordTechnicalReview::class)->handle(ProcurementCase::withoutGlobalScopes()->find($case->id), $this->a['tech'], 'favourable');
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(ProcurementCase::withoutGlobalScopes()->find($draft->id)->status)->toBe(S::DRAFT);
    Notification::assertNothingSent();
});

it('resolves recipients by permission: renaming the roles does not stop delivery', function () {
    $case = qvCase($this->a, S::WITH_LEGAL);
    foreach ([Role::Cmo, Role::CmoRegistry, Role::LegalOfficer, Role::PsOffice] as $role) {
        RoleModel::findByName($role->value)->forceFill(['name' => $role->value.'_renamed_'.Str::lower(Str::random(4))])->save();
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    nrTransition($case, S::DRAFT, S::SUBMITTED, $this->a['vendor']);
    nrTransition($case, S::FILE_PREPARED, S::WITH_LEGAL);
    nrTransition($case, S::WITH_LEGAL, S::WITH_PS, $this->a['legal']);

    expect(nrTo(CaseSubmitted::class))->toBe(nrIds('cmo', 'registry'))
        ->and(nrTo(FileSentToLegal::class))->toBe(nrIds('legal'))
        ->and(nrTo(FileSentToPs::class))->toBe(nrIds('ps'));
});
