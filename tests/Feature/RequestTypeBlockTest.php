<?php

use App\Enums\AuditAction;
use App\Models\ProcurementCase;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use App\Submissions\RequisitionData;
use App\Submissions\SubmissionReadiness;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// V2-5 step 3: section 1 "Request Type" holds the type cards, the tender cycle
// and the question "Was a request previously made for this drug in this current
// tender cycle?" (cases.previous_request_in_cycle, null = unanswered).
// DatabaseTransactions only.

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

function previousAnswer(ProcurementCase $case): ?bool
{
    $value = DB::table('cases')->where('id', $case->id)->value('previous_request_in_cycle');

    return $value === null ? null : (bool) $value;
}

function requestTypeIssues(ProcurementCase $case): array
{
    $errors = app(SubmissionReadiness::class)->evaluate(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->errors;

    return array_values(array_filter(array_column($errors, 'key'), fn ($k) => RequestSections::sectionOf($k) === 'request_type'));
}

it('starts unanswered and saves Yes, No and back to unanswered through the autosave route', function () {
    [$case, $vendor] = newDraft();
    expect(previousAnswer($case))->toBeNull();

    foreach ([true, false, null] as $answer) {
        $this->actingAs($vendor)->put(route('requests.update', $case->id), ['previous_request_in_cycle' => $answer])
            ->assertRedirect()->assertSessionHasNoErrors();
        expect(previousAnswer($case))->toBe($answer);
    }
});

it('leaves the answer alone when a save does not send it', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['previous_request_in_cycle' => true]);
    saveDraft($case, $vendor, ['requisition' => ['item_description' => 'Feeding tube']]);

    expect(previousAnswer($case))->toBeTrue();
});

it('audits the save of the answer by name only', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['previous_request_in_cycle' => true]);

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseDraftSaved->value)->latest('id')->first();
    expect(json_decode($audit->metadata, true))->toBe(['sections' => ['previous_request_in_cycle'], 'counts' => []]);
});

it('refuses an answer that is not a yes/no value, and refuses it for the old types', function (string $code, mixed $value) {
    [$case] = draftOf($code);

    try {
        RequisitionData::validate(['previous_request_in_cycle' => $value], $case->procurementType()->firstOrFail());
        $this->fail('accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('previous_request_in_cycle');
    }
})->with([
    ['AD_HOC_EMERGENCY', 'maybe'],
    ['AD_HOC_EMERGENCY', ['yes']],
    ['AD_HOC_PHARM', true],
]);

it('completes section 1 only when a type is chosen, the cycle is valid and the question is answered', function (string $code) {
    [$case, $vendor] = newDraft($code);
    expect(requestTypeIssues($case))->toBe(['requisition.tender_cycle_start', 'requisition.tender_cycle_end', 'previous_request_in_cycle']);

    saveDraft($case, $vendor, ['requisition' => ['tender_cycle_start' => '2026-06-01', 'tender_cycle_end' => '2026-05-01']]);
    expect(requestTypeIssues($case))->toBe(['requisition.tender_cycle_end', 'previous_request_in_cycle']);

    saveDraft($case, $vendor, ['requisition' => ['tender_cycle_end' => '2026-10-31'], 'previous_request_in_cycle' => false]);
    expect(requestTypeIssues($case))->toBe([]);

    $form = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case->id))->assertOk())['form'];
    expect(collect($form['sections'])->firstWhere('key', 'request_type'))->toMatchArray([
        'number' => 1, 'visible' => true, 'state' => 'complete', 'label' => 'Request Type',
    ]);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('refuses a tender cycle date in the future (Port of Spain)', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    $today = CarbonImmutable::now('America/Port_of_Spain')->startOfDay();
    $messages = function () use ($case): array {
        $errors = app(SubmissionReadiness::class)->evaluate(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->errors;

        return collect($errors)->filter(fn ($e) => str_starts_with($e['key'], 'requisition.tender_cycle'))->pluck('message', 'key')->all();
    };

    // Starts tomorrow.
    saveDraft($case, $vendor, ['requisition' => ['tender_cycle_start' => $today->addDay()->toDateString(), 'tender_cycle_end' => $today->addDays(2)->toDateString()]]);
    expect($messages())->toBe(['requisition.tender_cycle_start' => RequestSections::CYCLE_START_AFTER_TODAY]);

    // Started in the past but ends tomorrow.
    saveDraft($case, $vendor, ['requisition' => ['tender_cycle_start' => $today->subYear()->toDateString(), 'tender_cycle_end' => $today->addDay()->toDateString()]]);
    expect($messages())->toBe(['requisition.tender_cycle_end' => RequestSections::CYCLE_END_AFTER_TODAY]);

    // A past cycle, and one ending today, both count.
    saveDraft($case, $vendor, ['requisition' => ['tender_cycle_start' => $today->subYear()->toDateString(), 'tender_cycle_end' => $today->subMonth()->toDateString()]]);
    expect($messages())->toBe([]);
    saveDraft($case, $vendor, ['requisition' => ['tender_cycle_start' => $today->toDateString(), 'tender_cycle_end' => $today->toDateString()]]);
    expect($messages())->toBe([])
        ->and(RequestSections::CYCLE_START_AFTER_TODAY)->toBe('The tender cycle cannot start after today.')
        ->and(RequestSections::CYCLE_END_AFTER_TODAY)->toBe('The tender cycle end cannot be in the future.');
});

it('keeps section 1 incomplete while no type is chosen', function () {
    $vendor = vendorUser(vendorOrg());
    $this->actingAs($vendor)->post(route('requests.store'))->assertRedirect();
    $case = ProcurementCase::withoutGlobalScopes()->where('created_by', $vendor->id)->latest('created_at')->firstOrFail();
    saveDraft($case, $vendor, [
        'requisition' => ['tender_cycle_start' => '2026-01-01', 'tender_cycle_end' => '2026-10-31'],
        'previous_request_in_cycle' => false,
    ]);

    expect(requestTypeIssues($case))->toBe(['request_type']);
});

it('renders section 1 with the type cards, then the cycle dates and the question in one row on the width scale', function () {
    $section = file_get_contents(resource_path('js/components/requests/ad-hoc/request-type-section.tsx'));

    // FORM-COMPACT-1 (1): two xs dates and the lg question share one row of the field grid (3 + 3 + 6 of 12 columns from a 56rem card).
    expect($section)->toContain('<TypeCards {...typeChoice} />')
        ->toContain('<FieldGrid>')
        ->toMatch('/field\(\'tender_cycle_start\'\)\}\s+width="xs"/')
        ->toMatch('/field\(\'tender_cycle_end\'\)\}\s+width="xs"/')
        ->toContain('width="lg"')
        ->toContain("field('tender_cycle_start')")
        ->toContain("field('tender_cycle_end')")
        ->toContain("'Was a request previously made for this drug in this current tender cycle?'")
        // RADIO-1: the question is the shared RadioGroup (a fieldset, its legend, the answers stacked).
        ->toContain('<RadioGroup')
        ->toContain('legend={PREVIOUS_REQUEST_QUESTION}')
        ->toContain('legendCase="sentence"')
        ->toContain("const answer = value === null ? null : value ? 'yes' : 'no';") // nothing preselected: null matches neither
        ->toContain('value={answer}')
        ->toContain('tabIndex={-1}')
        ->not->toContain('defaultChecked')
        ->toContain('Changing the answer to No hides Details of Variation Requested. What you entered there is kept until you submit.');

    // The cycle fields left Item and Tender Details; their names and the end-not-before-start rule are unchanged.
    $item = file_get_contents(resource_path('js/components/requests/ad-hoc/item-tender-section.tsx'));
    expect($item)->not->toContain("field('tender_cycle_start')")->not->toContain("field('tender_cycle_end')")
        ->and(RequestSections::CYCLE_END_BEFORE_START)->toBe('The tender cycle end cannot be before its start.');
});

it('gives the progress strip equal columns whose labels wrap, for up to 8 sections', function () {
    $strip = file_get_contents(resource_path('js/components/ui/progress-strip.tsx'));

    expect(count(RequestSections::ORDER))->toBe(8)
        ->and($strip)->toContain('className="relative min-w-0 flex-1"')
        ->and($strip)->toContain('break-words')
        ->and($strip)->not->toContain('truncate');
});
