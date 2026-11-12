<?php

// Requisition payload fixtures for the slice 8a tests. Loaded from tests/Pest.php.
// Generic supplier names only; modelled on the form's infant feeding tube example.

use App\Models\ProcurementCase;
use App\Models\ProcurementType;
use App\Models\User;
use App\Submissions\CreateDraftCase;
use App\Submissions\RequisitionData;
use App\Submissions\SaveRequisition;

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

/** Complete TENDER_EXCESS payload: quotations, tender awards and purchase orders. */
function tenderExcessPayload(): array
{
    $requisition = pharmPayload()['requisition'];
    unset($requisition['rfq_date'], $requisition['rfq_audience']);

    return [
        'requisition' => $requisition,
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

/** A vendor user and a fresh draft of $code created through CreateDraftCase. */
function newDraft(string $code = 'AD_HOC_NON_PHARM', ?User $vendor = null): array
{
    $vendor ??= vendorUser(vendorOrg());
    $case = app(CreateDraftCase::class)->handle($vendor, procurementTypeByCode($code));

    return [$case, $vendor];
}

/** Validates and saves $payload on $case as $vendor. */
function saveDraft(ProcurementCase $case, User $vendor, array $payload): ProcurementCase
{
    $data = RequisitionData::validate($payload, $case->procurementType()->firstOrFail());

    return app(SaveRequisition::class)->handle($case, $vendor, $data);
}

/**
 * A draft that is ready to submit (slice 8d): complete request content plus a
 * CLEAN document for every required purpose. Needs Storage::fake('documents').
 */
function readyDraft(string $code = 'AD_HOC_NON_PHARM', ?User $vendor = null): array
{
    [$case, $vendor] = newDraft($code, $vendor);
    saveDraft($case, $vendor, match ($code) {
        'AD_HOC_PHARM' => pharmPayload(),
        'TENDER_EXCESS' => tenderExcessPayload(),
        default => nonPharmPayload(),
    });
    attachRequiredDocuments($case);

    return [$case->fresh() ?? ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}
