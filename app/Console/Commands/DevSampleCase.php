<?php

namespace App\Console\Commands;

use App\Documents\DocumentUploadService;
use App\Enums\DocumentPurpose;
use App\Enums\Permission;
use App\Enums\UserType;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use App\Submissions\CreateDraftCase;
use App\Submissions\Exceptions\SubmissionNotReady;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;
use App\Submissions\SubmitCase;
use App\Workflow\Actions\ForwardForReview;
use App\Workflow\Actions\IntakeCase;
use App\Workflow\Actions\MarkReadyForFile;
use App\Workflow\Actions\PrepareFile;
use App\Workflow\Actions\RecordFundingConfirmation;
use App\Workflow\Actions\RecordHardcopy;
use App\Workflow\Actions\RecordLegalDecision;
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
    protected $signature = 'dev:sample-case {stage : submitted, intake, in_review, ready_for_file, file_prepared, with_legal, with_ps or returned_by_legal}';

    protected $description = 'DEV ONLY: create one sample case and drive it to a stage through the real workflow services';

    public const TAG = '[DEV SAMPLE]';

    public const RETURN_REASON = 'Dev sample: clause 4 needs the vendor to clarify the delivery terms.';

    /** Procurement type used for samples (needs no DIN). */
    public const TYPE_CODE = 'AD_HOC_NON_PHARM';

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
    ];

    /** Step => the dev user (actor key) it needs. */
    private const STEP_ACTORS = [
        'draft' => 'vendor', 'documents' => 'vendor', 'submit' => 'vendor',
        'intake' => 'registry', 'hardcopy' => 'registry',
        'forward' => 'cmo', 'technical' => 'technocrat', 'funding' => 'cab',
        'ready' => 'cmo', 'prepare' => 'cmo', 'send_to_legal' => 'cmo',
        'legal_confirm' => 'legal', 'legal_return' => 'legal',
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
        };
    }

    private function draft(User $vendor): void
    {
        $type = ProcurementType::where('code', self::TYPE_CODE)->firstOrFail();
        $this->case = app(CreateDraftCase::class)->handle($vendor, $type);

        $data = RequisitionData::validate(self::payload(), $type);
        app(SaveRequisition::class)->handle($this->case, $vendor, $data);
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

    /** A complete AD_HOC_NON_PHARM request; generic supplier names only. */
    public static function payload(): array
    {
        $today = CarbonImmutable::now('America/Port_of_Spain');
        $quote = fn (string $supplier, string $price, array $extra = []) => [
            'supplier_name' => $supplier,
            'manufacturer' => 'Generic Manufacturer',
            'brand' => 'Generic Brand',
            'unit_price' => $price,
            'currency' => 'TTD',
            'quantity_offered' => '3000',
            'lead_time_days' => 30,
            'price_valid_until' => $today->addYear()->toDateString(),
            'previous_price_maintained' => 'UNKNOWN',
            'is_recommended' => false,
            ...$extra,
        ];

        return [
            'requisition' => [
                'vendor_reference' => 'DEV-'.$today->format('Ymd-His'),
                'request_date' => $today->toDateString(),
                'need_classification' => 'VITAL',
                'item_description' => self::TAG.' Infant feeding tube, size 6Fr',
                'item_code' => 'DEV-IFT-6FR',
                'unit_of_measure' => 'Each',
                'purpose' => 'Dev sample for manual testing',
                'quantity_requested' => '2800',
                'average_monthly_consumption' => '500',
                'central_stores_stock' => '0',
                'rha_stock' => '0',
                'stock_verified_on' => $today->toDateString(),
                'urgency_explanation' => 'Dev sample',
                'tender_reference' => 'DEV-TENDER',
                'tender_outcome' => 'NOT_AWARDED',
                'rfq_date' => $today->subDays(7)->toDateString(),
                'rfq_audience' => 'ALL_REGISTERED',
            ],
            'quotations' => [
                $quote('Supplier A', '0.67'),
                $quote('Supplier B', '1.5975', ['is_recommended' => true, 'recommended_quantity' => '2800']),
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
