<?php

// Requisition payload fixtures (slice 8a; AD_HOC added in slice F1). Loaded from tests/Pest.php.
// Generic supplier names only; modelled on the form's infant feeding tube example.

use App\Enums\CaseStatus;
use App\Models\CaseRequisition;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\User;
use App\Submissions\CreateDraftCase;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;
use Carbon\CarbonImmutable;

function procurementTypeByCode(string $code): ProcurementType
{
    return ProcurementType::where('code', $code)->firstOrFail();
}

/** A quotation row; $overrides replace or add fields. */
function quoteRow(string $supplier, string $price, array $overrides = []): array
{
    return [
        'supplier_name' => $supplier,
        'manufacturer' => 'Generic Manufacturer',
        'brand' => 'Generic Brand',
        'unit_price' => $price,
        'currency' => 'TTD',
        'quantity_offered' => '3000',
        'lead_time_days' => 30,
        'price_valid_until' => now()->addYear()->toDateString(),
        'previous_price_maintained' => 'UNKNOWN',
        'is_recommended' => false,
        ...$overrides,
    ];
}

/** Complete AD_HOC_NON_PHARM payload: six quotations, one recommended for 2800 units. */
function nonPharmPayload(): array
{
    return [
        'requisition' => [
            'vendor_reference' => 'REQ-2026-001',
            'request_date' => '2026-11-02',
            'need_classification' => 'VITAL',
            'item_description' => 'Infant feeding tube, size 6Fr',
            'item_code' => 'IFT-6FR',
            'unit_of_measure' => 'Each',
            'purpose' => 'Neonatal enteral feeding',
            'quantity_requested' => '2800',
            'average_monthly_consumption' => '500',
            'central_stores_stock' => '0',
            'rha_stock' => '0',
            'stock_verified_on' => '2026-11-01',
            'stockout_date' => '2026-10-15',
            'urgency_explanation' => 'Out of stock at central stores and all RHAs',
            'tender_reference' => 'TENDER-2026-14',
            'tender_outcome' => 'NOT_AWARDED',
            'rfq_date' => '2026-10-20',
            'rfq_audience' => 'ALL_REGISTERED',
        ],
        'quotations' => [
            quoteRow('Supplier A', '0.67'),
            quoteRow('Supplier B', '1.40'),
            quoteRow('Supplier C', '1.5975', ['is_recommended' => true, 'recommended_quantity' => '2800']),
            quoteRow('Supplier D', '4.15'),
            quoteRow('Supplier E', '0.754'),
            quoteRow('Supplier F', '7.00'),
        ],
    ];
}

/** Complete AD_HOC_PHARM payload (needs a DIN; no tender_outcome needed). */
function pharmPayload(): array
{
    $payload = nonPharmPayload();
    $payload['requisition']['din'] = '02345678';
    unset($payload['requisition']['tender_outcome']);

    return $payload;
}

/**
 * Complete old-form AD_HOC_PHARM payload with every section filled: one
 * quotation, two tender awards and one purchase order. (Was the TENDER_EXCESS
 * fixture; TENDER_EXCESS uses the request form since slice V2-1.)
 */
function pharmAllSectionsPayload(): array
{
    return [
        'requisition' => pharmPayload()['requisition'],
        'quotations' => [
            quoteRow('Supplier A', '1.5975', ['is_recommended' => true, 'recommended_quantity' => '2800']),
        ],
        'tender_awards' => [
            ['supplier_name' => 'Supplier A', 'quantity_awarded' => '2800', 'unit_cost' => '1.2500'],
            ['supplier_name' => 'Supplier B', 'quantity_awarded' => '1200', 'unit_cost' => '1.3000'],
        ],
        'purchase_orders' => [
            ['supplier_name' => 'Supplier A', 'po_number' => 'PO-1001', 'quantity_ordered' => '3000', 'quantity_delivered' => '1200'],
        ],
    ];
}

/*
 * The request form (slice F1; V2-1 fields added). A complete request for every
 * one of the five types (plus variationEntry for a Tender Variation): item,
 * contract reference and tender cycle, two award rows, stock batches, two
 * quotations (one not considered, with a reason; one recommended), quantity to
 * purchase and both reference prices.
 * Orders point at award ids, so they are saved in a second step
 * (adHocOrdersFor).
 */
function adHocPayload(): array
{
    return [
        // V2-5: the previous-request question, required to submit (No: no variation details).
        'previous_request_in_cycle' => false,
        'requisition' => [
            'item_description' => 'Infant feeding tube, size 6Fr',
            'strength' => '6Fr',
            'dosage_form' => 'Tube',
            'din' => '02345678',
            'quantity_requested' => '2800',
            'unit_of_measure' => 'Each',
            'clinical_note' => 'Neonatal enteral feeding',
            'tender_reference' => 'TENDER-2026-14 (2026-2027)',
            'tender_issue_explanation' => 'The awarded supplier delivered part of the order only.',
            // Slice V2-1. The tender outcome is no longer read (not sent).
            'tender_cycle_start' => '2026-01-01',
            'tender_cycle_end' => '2027-12-31',
            'quantity_on_order' => '1800',
            'stock_location' => 'Central stores',
            'average_monthly_consumption' => '500',
            'no_stock_on_hand' => false,
            'rfq_audience' => 'ALL_REGISTERED',
            'quantity_to_purchase' => '2800',
            'tender_price' => '1.2500',
            'previous_price' => '1.5000',
            'quoted_price_acceptable' => true,
            'price_note' => 'Within the expected range.',
        ],
        'tender_awards' => [
            ['supplier_name' => 'Supplier A', 'manufacturer' => 'Generic Manufacturer', 'brand' => 'Generic Brand', 'quantity_awarded' => '3000', 'unit_cost' => '1.2500', 'country_of_origin' => 'Country A'],
            ['supplier_name' => 'Supplier B', 'quantity_awarded' => '1000', 'unit_cost' => '1.3000'],
        ],
        'stock_expiries' => [
            // Relative to today (slice V2-1), so the expiry warning never fires on the fixture.
            ['quantity' => '120', 'expiry_date' => now('America/Port_of_Spain')->addMonths(4)->endOfMonth()->toDateString()],
            ['quantity' => '80.5', 'expiry_date' => now('America/Port_of_Spain')->addMonths(7)->endOfMonth()->toDateString()],
        ],
        'quotations' => [
            adHocQuoteRow('Supplier C', '1.40', ['considered' => false, 'reason_accepted_excluded' => 'No registration on file.']),
            adHocQuoteRow('Supplier D', '1.5975', ['is_recommended' => true, 'ex_stock' => true, 'country_of_origin' => 'Country B', 'remarks' => 'Two-week delivery.']),
        ],
    ];
}

/** A complete variation row (V2-5, Details of Variation Requested); $overrides replace or add fields. */
function variationEntry(array $overrides = []): array
{
    return [
        'variation_type' => 'Price',
        'existing_detail' => 'Unit cost 1.25 under the current contract.',
        'proposed_detail' => 'Unit cost 1.40 from the next delivery.',
        'effective_detail' => 'From 1 Jan 2027, PO-2207',
        ...$overrides,
    ];
}

/** An AD_HOC quotation row; $overrides replace or add fields. */
function adHocQuoteRow(string $supplier, string $price, array $overrides = []): array
{
    return [
        'supplier_name' => $supplier,
        'manufacturer' => 'Generic Manufacturer',
        'brand' => 'Generic Brand',
        'unit_price' => $price,
        'quantity_offered' => '3000',
        'lead_time_days' => 30,
        'considered' => true,
        'is_recommended' => false,
        ...$overrides,
    ];
}

/** One order against the case's first award row (the saved award ids are needed). */
function adHocOrdersFor(ProcurementCase $case): array
{
    $awardId = CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->value('id');

    return [
        'purchase_orders' => [
            ['award_id' => (int) $awardId, 'po_number' => 'PO-1001', 'quantity_ordered' => '3000', 'ordered_on' => '2026-06-01', 'quantity_delivered' => '1200', 'delivery_status' => 'Part delivered'],
        ],
    ];
}

/**
 * Saves the complete request-form content in its two steps; complete for every
 * one of the five types (a Tender Variation also gets its variation entry).
 */
function saveCompleteAdHoc(ProcurementCase $case, User $vendor): ProcurementCase
{
    // V2-5: a Tender Variation answers Yes and gives its variation details.
    $variation = $case->procurementType()->value('code') === 'TENDER_VARIATION'
        ? ['previous_request_in_cycle' => true, 'variation_rows' => [variationEntry()]]
        : [];
    saveDraft($case, $vendor, [...adHocPayload(), ...$variation]);

    return saveDraft($case, $vendor, adHocOrdersFor($case));
}

/** A vendor user and a fresh draft of $code created through CreateDraftCase (active types only). */
function newDraft(string $code = 'AD_HOC_TENDER_UNABLE', ?User $vendor = null): array
{
    $vendor ??= vendorUser(vendorOrg());
    $case = app(CreateDraftCase::class)->handle($vendor, procurementTypeByCode($code));

    return [$case, $vendor];
}

/**
 * A draft of an old, now inactive type (AD_HOC_PHARM, AD_HOC_NON_PHARM), as
 * drafts made before slice F1 exist. CreateDraftCase refuses inactive types,
 * so this writes the same rows it would (case and empty requisition), without
 * its audit row. For tests of the old types' rules only.
 */
function legacyDraft(string $code, ?User $vendor = null): array
{
    $vendor ??= vendorUser(vendorOrg());
    $type = procurementTypeByCode($code);

    $case = new ProcurementCase;
    $case->forceFill([
        'org_id' => $vendor->org_id,
        'created_by' => $vendor->getKey(),
        'procurement_type_id' => $type->getKey(),
        'title' => $type->name,
        'status' => CaseStatus::DRAFT,
        'current_holder_role' => 'vendor_applicant',
        'status_changed_at' => CarbonImmutable::now('UTC'),
    ])->save();
    (new CaseRequisition)->forceFill(['case_id' => $case->getKey()])->save();

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->getKey()), $vendor];
}

/** newDraft for active types, legacyDraft for inactive ones. */
function draftOf(string $code, ?User $vendor = null): array
{
    return procurementTypeByCode($code)->is_active ? newDraft($code, $vendor) : legacyDraft($code, $vendor);
}

/** Validates and saves $payload on $case as $vendor. */
function saveDraft(ProcurementCase $case, User $vendor, array $payload): ProcurementCase
{
    $data = RequisitionData::validate($payload, $case->procurementType()->firstOrFail());

    return app(SaveRequisition::class)->handle($case, $vendor, $data)->case;
}

/**
 * A draft that is ready to submit (slice 8d): complete request content plus a
 * CLEAN document for every required purpose. Needs Storage::fake('documents').
 */
function readyDraft(string $code = 'AD_HOC_TENDER_UNABLE', ?User $vendor = null): array
{
    [$case, $vendor] = draftOf($code, $vendor);
    match ($code) {
        'AD_HOC_TENDER_UNABLE', 'AD_HOC_EMERGENCY', 'AD_HOC_NOT_AWARDED', 'TENDER_EXCESS', 'TENDER_VARIATION' => saveCompleteAdHoc($case, $vendor),
        'AD_HOC_PHARM' => saveDraft($case, $vendor, pharmPayload()),
        default => saveDraft($case, $vendor, nonPharmPayload()),
    };
    attachRequiredDocuments($case);

    return [$case->fresh() ?? ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}
