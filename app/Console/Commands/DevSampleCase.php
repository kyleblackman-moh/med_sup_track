<?php

namespace App\Console\Commands;

use App\Documents\DocumentUploadService;
use App\Enums\DocumentPurpose;
use App\Enums\Permission;
use App\Enums\ReturnKind;
use App\Enums\UserType;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use App\Submissions\CreateDraftCase;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;
use App\Submissions\SubmitCase;
use App\Workflow\Actions\CloseCase;
use App\Workflow\Actions\ForwardForReview;
use App\Workflow\Actions\IntakeCase;
use App\Workflow\Actions\IssueLetter;
use App\Workflow\Actions\MarkReadyForFile;
use App\Workflow\Actions\PrepareFile;
use App\Workflow\Actions\RecordFundingConfirmation;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Actions\RecordLegalDecision;
use App\Workflow\Actions\RecordPsDecision;
use App\Workflow\Actions\RecordTechnicalReview;
use App\Workflow\Actions\SendToLegal;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DEVELOPMENT TOOL ONLY (slice DEV-1). Creates ONE sample case for the dev
 * vendor organisation and drives it to a chosen stage through the real action
 * services, as the right dev user each time, with the inputs the UI would send.
 *
 * - Never writes status, tracks, assignments or history directly.
 * - Documents go through DocumentUploadService (validation and malware scan);
 *   readiness is never bypassed.
 * - Refuses to run unless APP_ENV is local. No --force.
 * - If a real service refuses a step, it says which step and why, and leaves
 *   the case where it stopped.
 *
 * Dev users are found by permission, never by role name.
 */
class DevSampleCase extends Command
{
    protected $signature = 'dev:sample-case {stage : submitted, intake, in_review, ready_for_file, file_prepared, with_legal, with_ps, returned_by_legal, ps_granted, returned_by_ps_legal, returned_by_ps_technical, letter_issued or closed}
        {--type=AD_HOC_TENDER_UNABLE : request type: AD_HOC_EMERGENCY, AD_HOC_TENDER_UNABLE, AD_HOC_NOT_AWARDED, TENDER_EXCESS or TENDER_VARIATION}';

    protected $description = 'DEV ONLY: create one sample case and drive it to a stage through the real workflow services';

    public const TAG = '[DEV SAMPLE]';

    public const RETURN_REASON = 'Dev sample: clause 4 needs the vendor to clarify the delivery terms.';

    /** Request type used for samples unless --type names another of the five (slice V2-1). */
    public const TYPE_CODE = 'AD_HOC_TENDER_UNABLE';

    /** The request type of this run (--type). */
    private string $typeCode = self::TYPE_CODE;

    /** Stage => the steps it runs (each stage includes all the earlier ones). */
    public const STAGES = [
        'submitted' => ['draft', 'documents', 'submit'],
        'intake' => ['draft', 'documents', 'submit', 'intake', 'hardcopy'],
        'in_review' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward'],
        'ready_for_file' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready'],
        'file_prepared' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare'],
        'with_legal' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare', 'send_to_legal'],
        'with_ps' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare', 'send_to_legal', 'legal_confirm'],
        'returned_by_legal' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare', 'send_to_legal', 'legal_return'],
        // Slice 10d.
        'ps_granted' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare', 'send_to_legal', 'legal_confirm', 'ps_grant'],
        // Slice 10f.
        'letter_issued' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare', 'send_to_legal', 'legal_confirm', 'ps_grant', 'issue_letter'],
        'closed' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare', 'send_to_legal', 'legal_confirm', 'ps_grant', 'issue_letter', 'close'],
        'returned_by_ps_legal' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare', 'send_to_legal', 'legal_confirm', 'ps_return_legal'],
        'returned_by_ps_technical' => ['draft', 'documents', 'submit', 'intake', 'hardcopy', 'forward', 'technical', 'funding', 'ready', 'prepare', 'send_to_legal', 'legal_confirm', 'ps_return_technical'],
    ];

    public const LETTER_REFERENCE = 'DEV/NOL/001';

    public const PS_RETURN_REASON = 'Dev sample: the PS office needs this point clarified before deciding.';

    /** Step => the dev user (actor key) it needs. */
    private const STEP_ACTORS = [
        'draft' => 'vendor', 'documents' => 'vendor', 'submit' => 'vendor',
        'intake' => 'registry', 'hardcopy' => 'registry',
        'forward' => 'cmo', 'technical' => 'technocrat', 'funding' => 'cab',
        'ready' => 'cmo', 'prepare' => 'cmo', 'send_to_legal' => 'cmo',
        'legal_confirm' => 'legal', 'legal_return' => 'legal',
        'ps_grant' => 'ps', 'ps_return_legal' => 'ps', 'ps_return_technical' => 'ps',
        'issue_letter' => 'cmo', 'close' => 'cmo',
    ];

    /** Step => other dev users it names (reviewers, Legal officer), looked up before anything is created. */
    private const STEP_TARGETS = [
        'forward' => ['technocrat', 'cab'],
        'send_to_legal' => ['legal'],
    ];

    private ?ProcurementCase $case = null;

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('dev:sample-case only runs when APP_ENV is local (this is "'.app()->environment().'"). Nothing was created.');

            return self::FAILURE;
        }

        $this->typeCode = (string) $this->option('type');
        if (! in_array($this->typeCode, ProcurementTypeProfile::REQUEST_TYPES, true)) {
            $this->error("Unknown type \"{$this->typeCode}\". Use one of: ".implode(', ', ProcurementTypeProfile::REQUEST_TYPES).'.');

            return self::FAILURE;
        }

        $stage = (string) $this->argument('stage');
        $steps = self::STAGES[$stage] ?? null;
        if ($steps === null) {
            $this->error("Unknown stage \"{$stage}\". Use one of: ".implode(', ', array_keys(self::STAGES)).'.');

            return self::FAILURE;
        }

        // Find every dev user this stage needs before creating anything.
        $users = [];
        $missing = [];
        $needed = [];
        foreach ($steps as $step) {
            $needed = [...$needed, self::STEP_ACTORS[$step], ...(self::STEP_TARGETS[$step] ?? [])];
        }
        foreach (array_unique($needed) as $key) {
            $user = $this->findUser($key);
            $user === null ? $missing[] = self::describe($key) : $users[$key] = $user;
        }
        if ($missing !== []) {
            $this->error('Missing dev user(s): '.implode('; ', $missing).'. Nothing was created. Create them (for example php artisan db:seed --class=DevUsersSeeder) and try again.');

            return self::FAILURE;
        }

        foreach ($steps as $step) {
            $actor = $users[self::STEP_ACTORS[$step]];

            try {
                $this->runStep($step, $actor, $users);
            } catch (Throwable $e) {
                $this->error("Step \"{$step}\" (as {$actor->name}) was refused: ".self::reason($e));
                if ($this->case !== null) {
                    $this->reportCase('The case was left where it stopped');
                }

                return self::FAILURE;
            }

            $this->line("  {$step}: done (as {$actor->name})");
        }

        $this->reportCase("Sample case ready at stage {$stage}");

        return self::SUCCESS;
    }

    /** @param  array<string, User>  $users */
    private function runStep(string $step, User $actor, array $users): void
    {
        $fresh = fn (): ProcurementCase => ProcurementCase::withoutGlobalScopes()->findOrFail($this->case->getKey());

        match ($step) {
            'draft' => $this->draft($actor),
            'documents' => $this->documents($fresh(), $actor),
            'submit' => app(SubmitCase::class)->handle($fresh(), $actor),
            'intake' => app(IntakeCase::class)->handle($fresh(), $actor),
            'hardcopy' => app(RecordHardcopy::class)->mark(
                $fresh(), $actor, CarbonImmutable::now(RecordHardcopy::TIMEZONE)->toDateString(),
            ),
            'forward' => app(ForwardForReview::class)->handle(
                $fresh(), $actor, [$users['technocrat']->id], [$users['cab']->id], 'Dev sample: please review.',
            ),
            'technical' => app(RecordTechnicalReview::class)->handle($fresh(), $actor, 'favourable'),
            'funding' => app(RecordFundingConfirmation::class)->handle($fresh(), $actor, 'confirmed'),
            'ready' => app(MarkReadyForFile::class)->handle($fresh(), $actor),
            'prepare' => app(PrepareFile::class)->handle($fresh(), $actor, (string) config('cases.file_no_prefix')),
            'send_to_legal' => app(SendToLegal::class)->handle($fresh(), $actor, [$users['legal']->id]),
            'legal_confirm' => app(RecordLegalDecision::class)->handle($fresh(), $actor, RecordLegalDecision::CONFIRMED),
            'legal_return' => app(RecordLegalDecision::class)->handle(
                $fresh(), $actor, RecordLegalDecision::RETURN_TO_CMO, self::RETURN_REASON,
            ),
            'ps_grant' => app(RecordPsDecision::class)->handle($fresh(), $actor, RecordPsDecision::GRANT),
            'ps_return_legal' => app(RecordPsDecision::class)->handle(
                $fresh(), $actor, RecordPsDecision::RETURN_TO_CMO, self::PS_RETURN_REASON, ReturnKind::Legal->value,
            ),
            'ps_return_technical' => app(RecordPsDecision::class)->handle(
                $fresh(), $actor, RecordPsDecision::RETURN_TO_CMO, self::PS_RETURN_REASON, ReturnKind::Technical->value,
            ),
            // Slice 10f: today's date, emailed today, sample reference.
            'issue_letter' => app(IssueLetter::class)->handle(
                $fresh(), $actor, CarbonImmutable::now(RecordHardcopy::TIMEZONE)->toDateString(), self::LETTER_REFERENCE,
                CarbonImmutable::now(RecordHardcopy::TIMEZONE)->toDateString(),
            ),
            'close' => app(CloseCase::class)->handle($fresh(), $actor),
        };
    }

    private function draft(User $vendor): void
    {
        $type = ProcurementType::where('code', $this->typeCode)->firstOrFail();
        $this->case = app(CreateDraftCase::class)->handle($vendor, $type);

        $save = fn (array $payload) => app(SaveRequisition::class)->handle($this->case, $vendor, RequisitionData::validate($payload, $type));

        // Orders point at saved award rows, so they are saved in a second step.
        // A Tender Variation also gets its variation entry.
        $variation = $this->typeCode === ProcurementTypeProfile::TENDER_VARIATION ? ['variation' => self::variation()] : [];
        $save([...self::payload(), ...$variation]);
        $awardId = CaseTenderAward::withoutGlobalScopes()->where('case_id', $this->case->getKey())->orderBy('sort_order')->value('id');
        $save(self::orders((int) $awardId));
    }

    /** One small PDF per required purpose, through the same upload service as the UI. */
    private function documents(ProcurementCase $case, User $vendor): void
    {
        $purposes = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->where('is_required', true)->orderBy('sort_order')->get()
            ->map(fn (ProcurementTypeDocument $d) => $d->purpose);

        foreach ($purposes as $purpose) {
            $this->upload($case, $vendor, $purpose instanceof DocumentPurpose ? $purpose : DocumentPurpose::from($purpose));
        }
    }

    private function upload(ProcurementCase $case, User $vendor, DocumentPurpose $purpose): void
    {
        $path = tempnam(sys_get_temp_dir(), 'devsample');
        file_put_contents($path, "%PDF-1.4\n% Dev sample document\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        try {
            // test: true only skips PHP's is_uploaded_file() check; every
            // validation and the malware scan still run.
            $file = new UploadedFile($path, "dev-sample-{$purpose->value}.pdf", 'application/pdf', null, true);
            app(DocumentUploadService::class)->store($case, $vendor, $purpose, $file);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A complete request (every one of the five types; a Tender Variation also
     * needs variation()); generic supplier names only. Tender partially
     * awarded (two award rows), a stock batch, two quotations (one not
     * considered, with a reason; one recommended) and a quantity to purchase.
     * The order is saved separately (orders()), once the award ids exist.
     */
    public static function payload(): array
    {
        $today = CarbonImmutable::now('America/Port_of_Spain');
        $quote = fn (string $supplier, string $price, array $extra = []) => [
            'supplier_name' => $supplier,
            'manufacturer' => 'Generic Manufacturer',
            'brand' => 'Generic Brand',
            'unit_price' => $price,
            'quantity_offered' => '3000',
            'lead_time_days' => 30,
            'considered' => true,
            'is_recommended' => false,
            ...$extra,
        ];

        return [
            'requisition' => [
                'item_description' => self::TAG.' Infant feeding tube, size 6Fr',
                'din' => '02345678',
                'quantity_requested' => '2800',
                'unit_of_measure' => 'Each',
                'tender_reference' => 'DEV-TENDER',
                'tender_cycle_start' => $today->subYear()->startOfMonth()->toDateString(),
                'tender_cycle_end' => $today->addYear()->endOfMonth()->toDateString(),
                'tender_issue_explanation' => 'Dev sample: the awarded supplier delivered part of the order only.',
                'stock_location' => 'Central stores',
                'average_monthly_consumption' => '500',
                'no_stock_on_hand' => false,
                'rfq_audience' => 'ALL_REGISTERED',
                'quantity_to_purchase' => '2800',
            ],
            'tender_awards' => [
                ['supplier_name' => 'Supplier A', 'manufacturer' => 'Generic Manufacturer', 'brand' => 'Generic Brand', 'quantity_awarded' => '3000', 'unit_cost' => '0.6500'],
                ['supplier_name' => 'Supplier B', 'quantity_awarded' => '1000', 'unit_cost' => '0.7000'],
            ],
            'stock_expiries' => [
                ['quantity' => '150', 'expiry_date' => $today->addMonths(6)->toDateString()],
            ],
            'quotations' => [
                $quote('Supplier C', '0.67', ['considered' => false, 'reason_accepted_excluded' => 'Dev sample: not registered.']),
                $quote('Supplier D', '1.5975', ['is_recommended' => true]),
            ],
        ];
    }

    /** The variation entry of a Tender Variation sample (slice V2-1). */
    public static function variation(): array
    {
        return [
            'variation_type' => 'Price',
            'existing_detail' => 'Dev sample: unit cost 0.65 under the current contract.',
            'proposed_detail' => 'Dev sample: unit cost 0.72 from the next delivery.',
            'effective_date' => CarbonImmutable::now('America/Port_of_Spain')->addMonth()->startOfMonth()->toDateString(),
        ];
    }

    /** One purchase order against the given award row. */
    public static function orders(int $awardId): array
    {
        $today = CarbonImmutable::now('America/Port_of_Spain');

        return [
            'purchase_orders' => [
                [
                    'award_id' => $awardId,
                    'po_number' => 'DEV-PO-1',
                    'quantity_ordered' => '3000',
                    'ordered_on' => $today->subMonths(2)->toDateString(),
                    'quantity_delivered' => '1200',
                    'delivery_status' => 'Part delivered',
                ],
            ],
        ];
    }

    /** The lowest-id active dev user for $key, found by permission. */
    private function findUser(string $key): ?User
    {
        $base = fn (Permission $p): Closure => fn () => User::query()
            ->permission($p->value)
            ->where('users.status', User::STATUS_ACTIVE)
            ->orderBy('users.id');

        $staff = fn (Permission $p) => $base($p)()->where('users.user_type', UserType::Staff->value);

        return match ($key) {
            'vendor' => $base(Permission::CaseSubmit)()->where('users.user_type', UserType::Vendor->value)->whereNotNull('users.org_id')->first(),
            // A registry clerk: intake and hardcopy, without case.view_all (that would be the CMO).
            'registry' => $staff(Permission::CaseIntake)->get()
                ->first(fn (User $u) => $u->can(Permission::HardcopyConfirm->value) && ! $u->can(Permission::CaseViewAll->value)),
            'cmo' => $staff(Permission::CaseRouteToReview)->get()->first(fn (User $u) => $u->can(Permission::FilePrepare->value)),
            'technocrat' => $staff(Permission::TechnicalConsider)->first(),
            'cab' => $staff(Permission::FundingConfirm)->first(),
            'legal' => $staff(Permission::LegalConfirm)->first(),
            'ps' => $staff(Permission::NonObjectionGrant)->first(),
        };
    }

    private static function describe(string $key): string
    {
        return match ($key) {
            'vendor' => 'a vendor applicant (active vendor user with case.submit)',
            'registry' => 'a registry clerk (active staff with case.intake and hardcopy.confirm, without case.view_all)',
            'cmo' => 'the CMO (active staff with case.route_to_review and file.prepare)',
            'technocrat' => 'a technocrat (active staff with technical.consider)',
            'cab' => 'a CAB officer (active staff with funding.confirm)',
            'legal' => 'a Legal officer (active staff with legal.confirm)',
            'ps' => 'a PS office user (active staff with nonobjection.grant)',
        };
    }

    private static function reason(Throwable $e): string
    {
        return match (true) {
            $e instanceof ValidationException => collect($e->errors())->flatten()->implode(' '),
            $e instanceof SubmissionNotReady => collect($e->report->errors)->pluck('message')->implode(' '),
            default => class_basename($e).': '.$e->getMessage(),
        };
    }

    private function reportCase(string $heading): void
    {
        $case = ProcurementCase::withoutGlobalScopes()->findOrFail($this->case->getKey());

        $this->info($heading.'.');
        $this->line('  Reference: '.($case->reference_no ?? '(none yet: still a draft)'));
        $this->line('  Status:    '.$case->status->label());
        $this->line('  Case page: '.($case->reference_no !== null
            ? route('cases.show', $case, false)
            : route('requests.edit', $case, false)));
    }
}
