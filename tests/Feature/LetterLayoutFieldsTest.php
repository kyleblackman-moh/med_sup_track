<?php

use App\Enums\OrderKind;
use App\Models\CasePurchaseOrder;
use App\Models\CaseRequisition;
use App\Models\CaseTenderAward;
use App\Models\CaseVariationRow;
use App\Models\ProcurementCase;
use App\Submissions\RequestSections;
use App\Submissions\SubmissionReadiness;
use App\Submissions\SubmitCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-27: the request form's data for the new letter layout. Five
// optional request fields, and the order rows' kind: the original tender's
// orders (purchase_orders) and the Approved Tender Excesses (previous_orders),
// one table, two payload sections. Approved Tender Excesses shows for Tender
// Excess and for a Yes to the previous-request question; the variation details
// are Tender Variation only. DatabaseTransactions only.

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

function llOrders(ProcurementCase $case, OrderKind $kind): array
{
    return CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->where('order_kind', $kind->value)
        ->orderBy('sort_order')->pluck('po_number')->all();
}

function llOrder(int $awardId, string $po, array $overrides = []): array
{
    return ['award_id' => $awardId, 'po_number' => $po, 'quantity_ordered' => '100', 'ordered_on' => '2026-01-10', 'quantity_delivered' => '100', 'delivery_state' => 'FULLY_DELIVERED', 'delivery_date' => '2026-02-01', ...$overrides];
}

function llAward(ProcurementCase $case): int
{
    return (int) CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->value('id');
}

function llErrorKeys(ProcurementCase $case): array
{
    return array_column(app(SubmissionReadiness::class)->evaluate(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->errors, 'key');
}

it('saves the five letter fields, all optional, with their length limits', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['requisition' => [
        'purpose_of_request' => 'Interim supply pending an outstanding order.',
        'purpose_summary' => 'Interim supply pending PO 078830',
        'outstanding_order_note' => 'the absence of a confirmed delivery date',
        'basis_of_recommendation' => 'availability and lead time',
        'rfq_number' => 'RFQ 142/2026',
    ]]);

    expect(CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->first()->only([
        'purpose_of_request', 'purpose_summary', 'outstanding_order_note', 'basis_of_recommendation', 'rfq_number',
    ]))->toBe([
        'purpose_of_request' => 'Interim supply pending an outstanding order.',
        'purpose_summary' => 'Interim supply pending PO 078830',
        'outstanding_order_note' => 'the absence of a confirmed delivery date',
        'basis_of_recommendation' => 'availability and lead time',
        'rfq_number' => 'RFQ 142/2026',
    ]);

    expect(fn () => saveDraft($case, $vendor, ['requisition' => ['rfq_number' => str_repeat('9', 51)]]))
        ->toThrow(ValidationException::class, 'RFQ Number needs to be 50 characters or fewer.');
    expect(fn () => saveDraft($case, $vendor, ['requisition' => ['purpose_of_request' => str_repeat('a', 1001)]]))
        ->toThrow(ValidationException::class, 'Purpose of Request needs to be 1000 characters or fewer.');

    // Optional: none of them is a readiness error.
    expect(collect(llErrorKeys($case))->filter(fn (string $k) => str_contains($k, 'purpose') || str_contains($k, 'rfq_number')
        || str_contains($k, 'basis_of') || str_contains($k, 'outstanding_order')))->toBeEmpty();
});

it('saves the two order tables as their own kinds, each section owning its rows', function () {
    [$case, $vendor] = newDraft('TENDER_EXCESS');
    saveCompleteAdHoc($case, $vendor); // one original order, PO-1001
    $award = llAward($case);

    saveDraft($case, $vendor, ['previous_orders' => [llOrder($award, '077353'), llOrder($award, '078830')]]);
    expect(llOrders($case, OrderKind::ORIGINAL_TENDER))->toBe(['PO-1001'])
        ->and(llOrders($case, OrderKind::PREVIOUS_REQUEST))->toBe(['077353', '078830']);

    // Saving one table never removes the other's rows.
    saveDraft($case, $vendor, ['purchase_orders' => [llOrder($award, '076232')]]);
    saveDraft($case, $vendor, ['previous_orders' => [llOrder($award, '078830')]]);
    expect(llOrders($case, OrderKind::ORIGINAL_TENDER))->toBe(['076232'])
        ->and(llOrders($case, OrderKind::PREVIOUS_REQUEST))->toBe(['078830']);

    // A row id of the other table is not a row of this one.
    $originalId = CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->where('order_kind', 'ORIGINAL_TENDER')->value('id');
    expect(fn () => saveDraft($case, $vendor, ['previous_orders' => [['id' => (int) $originalId, 'po_number' => 'X']]]))
        ->toThrow(ValidationException::class, 'Tender Excess Order Row 1 no longer exists.');

    $fresh = ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
    // The relations themselves split by kind (read without the viewer scope: no one is signed in here).
    expect($fresh->purchaseOrders()->withoutGlobalScopes()->pluck('po_number')->all())->toBe(['076232'])
        ->and($fresh->previousOrders()->withoutGlobalScopes()->pluck('po_number')->all())->toBe(['078830']);
});

it('checks the Approved Tender Excesses rows while the table is shown', function () {
    [$case, $vendor] = newDraft('AD_HOC_TENDER_UNABLE');
    saveCompleteAdHoc($case, $vendor);
    saveDraft($case, $vendor, ['previous_request_in_cycle' => true, 'previous_orders' => [
        llOrder(llAward($case), '078830', ['quantity_delivered' => '0', 'delivery_state' => 'FULLY_DELIVERED']),
    ]]);

    expect(llErrorKeys($case))->toContain('previous_orders.0.delivery_state')
        ->and(RequestSections::sectionOf('previous_orders.0.delivery_state'))->toBe('existing_arrangement');

    // Answer No: an Ad Hoc type hides the table, so its rows are not checked.
    saveDraft($case, $vendor, ['previous_request_in_cycle' => false]);
    expect(llErrorKeys($case))->not->toContain('previous_orders.0.delivery_state');
});

it('shows Approved Tender Excesses for Tender Excess or a Yes, and the variation details for Tender Variation only', function (string $code, ?bool $answer, bool $previousOrders, bool $variation) {
    expect(RequestSections::showsPreviousOrders($code, $answer))->toBe($previousOrders)
        ->and(RequestSections::isVisible($code, 'variation_details', $answer))->toBe($variation);
})->with([
    'ad hoc, yes' => ['AD_HOC_TENDER_UNABLE', true, true, false],
    'ad hoc, no' => ['AD_HOC_TENDER_UNABLE', false, false, false],
    'ad hoc, unanswered' => ['AD_HOC_EMERGENCY', null, false, false],
    'tender excess, no' => ['TENDER_EXCESS', false, true, false],
    'tender variation, yes' => ['TENDER_VARIATION', true, true, true],
    'tender variation, no' => ['TENDER_VARIATION', false, false, true],
]);

it('discards the hidden Approved Tender Excesses and variation rows at submit', function () {
    [$case, $vendor] = readyDraft('AD_HOC_TENDER_UNABLE');
    $award = llAward($case);
    saveDraft($case, $vendor, [
        'previous_request_in_cycle' => false,
        'previous_orders' => [llOrder($award, '078830')],
        'variation_rows' => [variationEntry()],
    ]);

    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    expect(llOrders($case, OrderKind::PREVIOUS_REQUEST))->toBe([])
        ->and(llOrders($case, OrderKind::ORIGINAL_TENDER))->toBe(['PO-1001'])
        ->and(CaseVariationRow::withoutGlobalScopes()->where('case_id', $case->id)->exists())->toBeFalse();
});

it('keeps them for a Tender Excess, which needs no variation rows for a Yes', function () {
    [$case, $vendor] = readyDraft('TENDER_EXCESS');
    saveDraft($case, $vendor, ['previous_request_in_cycle' => true, 'previous_orders' => [llOrder(llAward($case), '078830')]]);

    // Before 2026-11-27 a Yes needed a variation row on every type.
    expect(llErrorKeys($case))->not->toContain('variation_rows');

    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    expect(llOrders($case, OrderKind::PREVIOUS_REQUEST))->toBe(['078830']);
});

it('marks rows saved before the kind existed as original tender orders', function () {
    [$case, $vendor] = newDraft();
    saveCompleteAdHoc($case, $vendor);

    $row = CasePurchaseOrder::withoutGlobalScopes()->where('case_id', $case->id)->firstOrFail();
    expect($row->order_kind)->toBe(OrderKind::ORIGINAL_TENDER);
});
