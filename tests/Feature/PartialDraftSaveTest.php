<?php

use App\Enums\CaseStatus;
use App\Models\CaseQuotation;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use App\Submissions\CreateDraftCase;
use App\Submissions\RequisitionData;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// FORM-SAVE-1 (A1, A2, A6): a draft save of the request form keeps every valid
// field and leaves out only what failed; Review and Submit stays strict.

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

function requisitionOf(ProcurementCase $case): ?CaseRequisition
{
    return CaseRequisition::withoutGlobalScopes()->where('case_id', $case->id)->first();
}

/** PUT a draft save as $vendor; returns the "saved" flash. */
function putDraft(ProcurementCase $case, $vendor, array $payload): array
{
    test()->actingAs($vendor)->put(route('requests.update', $case), $payload)->assertRedirect()->assertSessionHasNoErrors();

    return session('saved');
}

it('saves the valid fields and leaves out one bad number, with its message', function () {
    [$case, $vendor] = newDraft();

    $saved = putDraft($case, $vendor, ['requisition' => [
        'item_description' => 'Paracetamol 500mg tablets',
        'strength' => '500mg',
        'quantity_requested' => 'lots',
    ]]);

    $r = requisitionOf($case);
    expect($r->item_description)->toBe('Paracetamol 500mg tablets')
        ->and($r->strength)->toBe('500mg')
        ->and($r->quantity_requested)->toBeNull()
        ->and($saved['not_saved'])->toBe([[
            'key' => 'requisition.quantity_requested',
            'section' => 'item_tender',
            'section_label' => 'Item and Tender Details',
            'message' => 'Quantity Requested needs to be a number, for example 2800.',
        ]])
        ->and($saved['status'])->toBe('Saved. 1 field needs attention and was not saved yet.');
});

it('keeps the saved value when a later value fails', function () {
    [$case, $vendor] = newDraft();
    putDraft($case, $vendor, ['requisition' => ['quantity_requested' => '100']]);

    putDraft($case, $vendor, ['requisition' => ['quantity_requested' => 'abc', 'strength' => '250mg']]);

    expect(requisitionOf($case)->quantity_requested)->toBe('100.00')
        ->and(requisitionOf($case)->strength)->toBe('250mg');
});

it('creates a new row with one bad cell, leaving that cell empty', function () {
    [$case, $vendor] = newDraft();

    $saved = putDraft($case, $vendor, ['quotations' => [[
        'client_key' => 'k1', 'supplier_name' => 'Acme Pharma', 'quantity_offered' => '10', 'unit_price' => 'cheap',
    ]]]);

    $row = CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    expect($row->supplier_name)->toBe('Acme Pharma')
        ->and($row->quantity_offered)->toBe('10.00')
        ->and($row->unit_price)->toBeNull()
        ->and($saved['id_map']->k1 ?? $saved['id_map']['k1'])->toBe((int) $row->id)
        ->and(collect($saved['not_saved'])->pluck('key')->all())->toBe(['quotations.0.unit_price'])
        ->and($saved['not_saved'][0]['message'])->toBe('Quotation Row 1: Unit Price needs to be a number, for example 12.50.');
});

it('leaves out only the end date when the tender cycle ends before it starts', function () {
    [$case, $vendor] = newDraft();

    $saved = putDraft($case, $vendor, ['requisition' => ['tender_cycle_start' => '2026-06-01', 'tender_cycle_end' => '2026-01-01']]);

    $r = requisitionOf($case);
    expect($r->tender_cycle_start?->format('Y-m-d'))->toBe('2026-06-01')
        ->and($r->tender_cycle_end)->toBeNull()
        ->and(collect($saved['not_saved'])->pluck('key')->all())->toBe(['requisition.tender_cycle_end'])
        ->and($saved['not_saved'][0]['message'])->toBe('Tender Cycle End needs to be on or after Tender Cycle Start.');
});

it('never persists a value that failed its rule', function () {
    [$case, $vendor] = newDraft();

    putDraft($case, $vendor, [
        'requisition' => ['quantity_requested' => '-5', 'din' => 'AB-12', 'unit_of_measure' => str_repeat('u', 40), 'dosage_form' => 'Tablet'],
        'quotations' => [['supplier_name' => 'Ok', 'lead_time_days' => '1.5', 'quantity_offered' => '1.234']],
    ]);

    $r = requisitionOf($case);
    $q = CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    expect($r->quantity_requested)->toBeNull()
        ->and($r->din)->toBeNull()
        ->and($r->unit_of_measure)->toBeNull()
        ->and($r->dosage_form)->toBe('Tablet')
        ->and($q->lead_time_days)->toBeNull()
        ->and($q->quantity_offered)->toBeNull()
        ->and($q->supplier_name)->toBe('Ok');
});

it('reports a full save with no status line', function () {
    [$case, $vendor] = newDraft();

    $saved = putDraft($case, $vendor, ['requisition' => ['strength' => '10mg']]);

    expect($saved['not_saved'])->toBe([])->and($saved['status'])->toBeNull();
});

it('leaves a section unchanged when its rows cannot be matched safely', function () {
    [$case, $vendor] = newDraft();
    putDraft($case, $vendor, ['quotations' => [['supplier_name' => 'Kept']]]);
    $id = (int) CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->value('id');

    // Two rows claiming the same id: the section is left as it was; the request fields still save.
    putDraft($case, $vendor, [
        'requisition' => ['strength' => '5mg'],
        'quotations' => [['id' => $id, 'supplier_name' => 'A'], ['id' => $id, 'supplier_name' => 'B']],
    ]);

    expect(CaseQuotation::withoutGlobalScopes()->where('case_id', $case->id)->pluck('supplier_name')->all())->toBe(['Kept'])
        ->and(requisitionOf($case)->strength)->toBe('5mg');
});

it('still refuses the same payload strictly, and submit stays blocked', function () {
    [$case, $vendor] = newDraft();
    $payload = ['requisition' => ['item_description' => 'Item', 'quantity_requested' => 'lots']];

    putDraft($case, $vendor, $payload);

    expect(fn () => RequisitionData::validate($payload, $case->procurementType()->firstOrFail()))->toThrow(ValidationException::class);

    $this->actingAs($vendor)->post(route('requests.submit', $case));
    expect(ProcurementCase::withoutGlobalScopes()->find($case->id)->status)->toBe(CaseStatus::DRAFT);
});

it('answers 404 to another organisation\'s vendor', function () {
    [$case] = newDraft();
    [, $other] = newDraft();

    $this->actingAs($other)->put(route('requests.update', $case), ['requisition' => ['strength' => 'x']])->assertNotFound();
    expect(requisitionOf($case)->strength)->toBeNull();
});

it('does not edit a case that is no longer a draft', function () {
    [$case, $vendor] = newDraft();
    DB::table('cases')->where('id', $case->id)->update(['status' => CaseStatus::SUBMITTED->value, 'submitted_at' => now()]);

    $this->actingAs($vendor)->put(route('requests.update', $case), ['requisition' => ['strength' => 'x']]);

    expect(requisitionOf($case)->strength)->toBeNull();
});

it('lets the vendor choose a request type while a quotation cell is bad', function () {
    $vendor = vendorUser(vendorOrg());
    $case = app(CreateDraftCase::class)->handle($vendor);
    putDraft($case, $vendor, ['quotations' => [['supplier_name' => 'X', 'unit_price' => 'bad']]]);

    $this->actingAs($vendor)->put(route('requests.type', $case), ['procurement_type' => 'AD_HOC_EMERGENCY'])->assertSessionHasNoErrors();

    expect($case->fresh()->procurementType->code)->toBe('AD_HOC_EMERGENCY');
});

it('audits a partial save with section names and counts only', function () {
    [$case, $vendor] = newDraft();

    putDraft($case, $vendor, ['requisition' => ['item_description' => 'Secret Drug Name', 'quantity_requested' => 'lots']]);

    $row = DB::table('audit_logs')->where('case_id', $case->id)->where('action', 'case.draft_saved')->latest('id')->first();
    expect(json_decode($row->metadata, true))->toBe(['sections' => ['requisition'], 'counts' => [], 'not_saved' => 1])
        ->and($row->metadata)->not->toContain('Secret')
        ->and($row->metadata)->not->toContain('lots');
});

it('keeps the older form strict', function () {
    [$case, $vendor] = legacyDraft('AD_HOC_PHARM');

    $this->actingAs($vendor)->put(route('requests.update', $case), ['requisition' => ['item_description' => 'X', 'quantity_requested' => 'lots']])
        ->assertSessionHasErrors('requisition.quantity_requested');

    expect(requisitionOf($case)->item_description)->toBeNull();
});
