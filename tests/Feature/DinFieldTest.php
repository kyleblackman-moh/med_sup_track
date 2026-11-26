<?php

use App\Http\Resources\Requests\RequestSummary;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use App\Submissions\RequisitionRules;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// V2-5 step 1, changed 2026-11-26 (Kyle): the DIN is optional and, when given, any
// text up to 32 characters (no 8-digit rule); trimmed, leading zeros kept, empty
// stored as null. DatabaseTransactions only.

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

function dinOf(ProcurementCase $case): ?string
{
    return CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->value('din');
}

it('accepts any DIN text, keeping leading zeros', function (string $din) {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['din' => $din]]);

    expect(dinOf($case))->toBe($din);
})->with(['02345678', '00000001', '1234567', '123456789', 'ABC-1234']);

it('trims the DIN and stores an empty one as null', function (?string $din) {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['din' => '12345678']]);
    saveDraft($case, $vendor, ['requisition' => ['din' => $din]]);

    expect(dinOf($case))->toBeNull();
})->with(['empty' => [''], 'spaces' => ['   '], 'null' => [null]]);

it('trims spaces around a DIN', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['din' => '  02345678  ']]);

    expect(dinOf($case))->toBe('02345678');
});

it('refuses a DIN over 32 characters', function () {
    [$case, $vendor] = newDraft();

    try {
        saveDraft($case, $vendor, ['requisition' => ['din' => str_repeat('9', 33)]]);
        $this->fail('The DIN was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['requisition.din' => ['DIN needs to be 32 characters or fewer.']]);
    }
});

it('uses the same DIN rule on the older request form', function () {
    $rules = RequisitionRules::requisitionFields('NON_PHARM');

    expect($rules['din'])->toBe(['type' => 'din']);
});

it('never requires the DIN to submit, for any type', function (string $code) {
    [$case, $vendor] = newDraft($code);
    saveCompleteAdHoc($case, $vendor);
    saveDraft($case, $vendor, ['requisition' => ['din' => null]]);
    attachRequiredDocuments($case);

    $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    expect(app(SubmissionReadiness::class)->evaluate($fresh)->errors)->toBe([])
        ->and(RequestSections::requiredFields($code)['item_tender']['din'])->toBeFalse();

    app(SubmitCase::class)->handle($fresh, $vendor);
    expect(dinOf($case))->toBeNull()
        ->and(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id)->reference_no)->not->toBeNull();
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('shows a dash in the summary when there is no DIN, and finds a draft by its DIN', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => ['item_description' => 'Feeding tube']]);

    $summary = RequestSummary::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);
    $din = collect($summary['sections'])->flatMap(fn ($s) => $s['blocks'])->flatMap(fn ($b) => $b['items'] ?? [])->firstWhere('label', 'DIN');
    expect($din['value'] ?? null)->toBe('—');

    saveDraft($case, $vendor, ['requisition' => ['din' => '00990099']]);
    $response = $this->actingAs($vendor)->get(route('requests.index', ['q' => '00990099']))->assertOk();
    expect(array_column(pageProps($response)['requests']['data'], 'id'))->toBe([$case->id]);
});
