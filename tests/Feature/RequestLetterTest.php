<?php

use App\Enums\AuditAction;
use App\Enums\DocumentPurpose;
use App\Enums\OrganisationType;
use App\Enums\Role;
use App\Letters\RequestLetter;
use App\Models\CaseTenderAward;
use App\Models\CaseTrackEvent;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\SubmitCase;
use App\Support\Qpdf;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

// Kyle, 2026-11-27: the request letter PDF (App\Letters\RequestLetter) in the new
// section order of the "Reorganised Rivaroxaban Approval Letter". Rivaroxaban (Ad
// Hoc) is that letter's case; Chlorpromazine (Tender Excess) and Bimatoprost
// (Tender Variation) are the other two kinds. The HTML is asserted before it
// becomes a PDF. DatabaseTransactions only.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(ProcurementTypeSeeder::class);
    Storage::fake('documents');
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 14:00', 'UTC'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** The vendor organisation of the reference letters (a test record, not app code). */
function rlVendor(): User
{
    // Names are unique: reuse the record if the database has one (the transaction rolls the changes back).
    $org = Organisation::firstOrNew(['name' => 'National Insurance Property Development Company Limited']);
    $org->fill([
        'short_name' => 'NIPDEC',
        'type' => OrganisationType::Vendor,
        'email' => 'pharma@example.test',
        'phone' => '(868) 555-0100',
        'active' => true,
    ])->save();

    return vendorUser($org, ['name' => 'Jane Applicant', 'job_title' => 'Procurement Officer']);
}

/**
 * Saves $payload, then the order sections $orders returns for the saved award ids
 * (['purchase_orders' => [...], 'previous_orders' => [...]]), attaches documents and
 * submits (unless $submit is false).
 */
function rlCase(string $code, array $payload, ?callable $orders = null, bool $submit = true): array
{
    [$case, $vendor] = newDraft($code, rlVendor());
    saveDraft($case, $vendor, $payload);

    if ($orders !== null) {
        $ids = CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->pluck('id')->all();
        saveDraft($case, $vendor, $orders($ids));
    }

    if ($submit) {
        attachRequiredDocuments($case);
        app(SubmitCase::class)->handle($case, $vendor);
    }

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

/** A delivered order row against award $awardId. */
function rlDelivered(int $awardId, string $po, string $quantity, string $orderedOn, string $deliveredOn): array
{
    return ['award_id' => $awardId, 'po_number' => $po, 'quantity_ordered' => $quantity, 'ordered_on' => $orderedOn, 'quantity_delivered' => $quantity, 'delivery_state' => 'FULLY_DELIVERED', 'delivery_date' => $deliveredOn];
}

function rlChlorpromazine(bool $submit = true): array
{
    return rlCase('TENDER_EXCESS', [
        'previous_request_in_cycle' => false,
        'requisition' => [
            'item_description' => 'Chlorpromazine HCL', 'strength' => '25mg', 'din' => '90000195',
            'quantity_requested' => '800000', 'unit_of_measure' => 'Each',
            'tender_reference' => 'NIPDEC Tender 2023/2025',
            'tender_cycle_start' => '2023-10-01', 'tender_cycle_end' => '2025-09-30',
            'stock_location' => 'Central Stores', 'average_monthly_consumption' => '70000', 'quantity_on_hand' => '80000',
            'rfq_audience' => 'ALL_REGISTERED', 'rfq_number' => 'RFQ 118/2026',
            'tender_price' => '0.2200', 'previous_price' => '0.2400',
            'purpose_summary' => 'Continuity of supply until the next tender',
        ],
        'tender_awards' => [
            ['supplier_name' => 'Alstons Marketing Company Limited', 'manufacturer' => 'Remedica Ltd.', 'country_of_origin' => 'Cyprus', 'brand' => 'Chlorpromazine', 'quantity_awarded' => '800000', 'unit_cost' => '0.2200', 'percentage_awarded' => '100'],
        ],
        'stock_expiries' => [['item_name' => 'Chlorpromazine HCL 25mg', 'expiry_date' => '2027-06-30']],
        'quotations' => [
            adHocQuoteRow('Alstons Marketing Company Limited', '0.22', ['quantity_offered' => '800000', 'is_recommended' => true, 'reason_accepted_excluded' => 'The only quotation received', 'lead_time_days' => 60, 'manufacturer' => 'Remedica Ltd.', 'country_of_origin' => 'Cyprus']),
        ],
    ], fn (array $ids) => [
        'purchase_orders' => [rlDelivered($ids[0], '076120', '400000', '2024-01-15', '2024-03-01')],
        // Tender Excess shows Approved Tender Excesses whatever the previous-request answer.
        'previous_orders' => [rlDelivered($ids[0], '078010', '400000', '2025-02-10', '2025-04-02')],
    ], submit: $submit);
}

function rlBimatoprost(): array
{
    return rlCase('TENDER_VARIATION', [
        'previous_request_in_cycle' => true,
        'variation_rows' => [[
            'variation_type' => 'Change of Manufacturer and Country of Origin',
            'existing_detail' => 'Allergan Inc. (United States)',
            'proposed_detail' => 'AbbVie Ltd. (Ireland)',
            'effective_detail' => 'PO 079128 and PO 079209',
        ]],
        'requisition' => [
            'item_description' => 'Bimatoprost Opth.', 'strength' => '0.01%/2.5ml', 'dosage_form' => 'Dro', 'din' => '90006473',
            'quantity_requested' => '60000', 'unit_of_measure' => 'Each',
            'clinical_note' => 'Bimatoprost is used for the reduction of elevated intraocular pressure in patients with open-angle glaucoma or ocular hypertension.',
            'tender_reference' => 'NIPDEC Tender 2023/2025',
            'tender_cycle_start' => '2023-10-01', 'tender_cycle_end' => '2025-09-30',
        ],
        'tender_awards' => [
            ['supplier_name' => 'Smith Robertson & Co. Ltd', 'manufacturer' => 'Allergan Inc.', 'country_of_origin' => 'United States', 'brand' => 'Lumigan', 'quantity_awarded' => '60000', 'unit_cost' => '95.00', 'percentage_awarded' => '100'],
        ],
    ], fn (array $ids) => ['purchase_orders' => [
        rlDelivered($ids[0], '076547', '7000', '2024-01-31', '2024-03-01'),
        rlDelivered($ids[0], '077439', '7000', '2024-06-04', '2024-07-15'),
        rlDelivered($ids[0], '078421', '10000', '2024-07-03', '2024-08-20'),
        // Other prints what the vendor wrote for it; the delivery note is its number and is not printed.
        ['award_id' => $ids[0], 'po_number' => '079128', 'quantity_ordered' => '8000', 'ordered_on' => '2026-05-28', 'quantity_delivered' => '4000', 'delivery_state' => 'OTHER', 'delivery_status' => 'Variation of tender in progress', 'delivery_note' => 'DN-4471'],
        ['award_id' => $ids[0], 'po_number' => '079209', 'quantity_ordered' => '10000', 'ordered_on' => '2026-06-18', 'quantity_delivered' => '0', 'delivery_state' => 'NOT_DELIVERED'],
    ]]);
}

/**
 * The case of the Word letter: two awards (70/30, with pack sizes), three original
 * tender orders all delivered, four approved tender excesses (078830 outstanding,
 * no confirmed ETA), nil stock, five quotations (bids 1, 2, 3 and 5 not considered
 * for their lead times), Pharmtech Caribbean Ltd recommended. The letter's
 * $359,856.00 is 29,988 x $12.00 with no VAT, so the rate is 0 here.
 */
function rlRivaroxaban(bool $previousRequest = true, bool $submit = true): array
{
    config(['procurement.vat_rate' => '0']);
    $notConsidered = ['considered' => false, 'reason_accepted_excluded' => 'their respective lead times', 'quantity_offered' => '29988'];

    return rlCase('AD_HOC_TENDER_UNABLE', [
        'previous_request_in_cycle' => $previousRequest,
        'requisition' => [
            'item_description' => 'Rivaroxaban', 'strength' => '20mg', 'dosage_form' => 'Tablet', 'din' => '90008557',
            'quantity_requested' => '29988', 'unit_of_measure' => 'Tablets',
            'clinical_note' => implode("\n", [
                'Prevention of venous thromboembolic events (VTE) in patients who have undergone total hip replacement and total knee replacement surgery.',
                'Prevention of stroke and systemic embolism in patients with non-valvular atrial fibrillation.',
                'Treatment of deep vein thrombosis (DVT) and pulmonary embolism (PE).',
                'Reduction of the risk of recurrent DVT and/or PE.',
                'In combination with aspirin, reduction of the risk of major cardiovascular events in patients with chronic coronary artery disease or peripheral artery disease.',
                'Treatment and prevention of VTE in paediatric patients (from birth to 18 years of age).',
                'Thromboprophylaxis in paediatric patients aged two (2) years and older with congenital heart disease following the Fontan procedure.',
            ]),
            'purpose_of_request' => 'This purchase is required to provide an interim supply of the medication pending delivery of an outstanding purchase order.',
            'purpose_summary' => 'Interim supply pending delivery of Purchase Order No. 078830',
            'tender_reference' => 'NIPDEC Tender 2023/2025',
            'tender_cycle_start' => '2023-10-01', 'tender_cycle_end' => '2025-09-30',
            'outstanding_order_note' => 'the absence of a confirmed delivery date for the outstanding purchase order',
            'stock_location' => 'Central Stores', 'average_monthly_consumption' => '10417', 'quantity_on_hand' => '0',
            'rfq_audience' => 'ALL_REGISTERED', 'rfq_number' => 'RFQ 142/2026',
            'basis_of_recommendation' => 'availability and lead time',
        ],
        'tender_awards' => [
            ['supplier_name' => 'Smith Robertson & Co. Ltd', 'manufacturer' => 'Bayer AG', 'country_of_origin' => 'Germany', 'brand' => 'Xarelto 20mg Tab', 'quantity_awarded' => '6250', 'pack_size' => 'Boxes of 28 Tablets', 'unit_cost' => '368.20', 'percentage_awarded' => '70'],
            ['supplier_name' => 'Alstons Marketing Company Limited', 'manufacturer' => 'Macleods Pharmaceutical Ltd', 'country_of_origin' => 'India', 'brand' => 'Rivaroxaban 20mg Tab', 'quantity_awarded' => '2500', 'pack_size' => 'Boxes of 30 Tablets', 'unit_cost' => '65.23', 'percentage_awarded' => '30'],
        ],
        'quotations' => [
            adHocQuoteRow('Smith Robertson & Co. Ltd', '13.15', [...$notConsidered, 'lead_time_days' => 120, 'brand' => 'Xarelto', 'manufacturer' => 'Bayer AG', 'country_of_origin' => 'Germany']),
            adHocQuoteRow('Alstons Marketing Company Limited', '2.17', [...$notConsidered, 'lead_time_days' => 150, 'manufacturer' => 'Macleods Pharmaceutical Ltd', 'country_of_origin' => 'India']),
            adHocQuoteRow('Medi-Supplies Limited', '9.80', [...$notConsidered, 'lead_time_days' => 90]),
            adHocQuoteRow('Pharmtech Caribbean Ltd', '12.00', ['quantity_offered' => '29988', 'is_recommended' => true, 'reason_accepted_excluded' => 'Available ex stock', 'lead_time_days' => 7, 'quantity_available_now' => '29988', 'brand' => 'Rivaroxaban Pharmtech', 'manufacturer' => 'Pharmtech Laboratories', 'country_of_origin' => 'India', 'remarks' => 'Ex stock']),
            adHocQuoteRow('Caribbean Health Distributors', '11.40', [...$notConsidered, 'lead_time_days' => 75]),
        ],
    ], fn (array $ids) => [
        'purchase_orders' => [
            rlDelivered($ids[1], '076232', '1167', '2023-12-28', '2024-02-15'),
            rlDelivered($ids[0], '076310', '6250', '2024-05-09', '2024-06-20'),
            rlDelivered($ids[1], '077691', '1333', '2024-09-11', '2024-10-30'),
        ],
        'previous_orders' => [
            rlDelivered($ids[0], '077353', '6250', '2024-05-09', '2024-06-20'),
            rlDelivered($ids[0], '077754', '6250', '2024-09-18', '2024-11-05'),
            rlDelivered($ids[0], '078239', '6250', '2025-03-20', '2025-05-12'),
            ['award_id' => $ids[1], 'po_number' => '078830', 'quantity_ordered' => '8666', 'ordered_on' => '2026-01-20', 'quantity_delivered' => '0', 'delivery_state' => 'OTHER', 'delivery_status' => 'No confirmed ETA provided by supplier; no confirmed shipping date'],
        ],
    ], $submit);
}

function rlHtml(ProcurementCase $case): string
{
    // Blade escapes "&" and "’"; the assertions read the text as printed.
    return html_entity_decode(RequestLetter::for($case)->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

it('writes the Ad Hoc letter (Rivaroxaban) in the new section order', function () {
    [$case] = rlRivaroxaban();
    $html = rlHtml($case);

    $headings = [
        '1. Purpose of Request', '2. Current Stock Position', '3. Procurement Background',
        '3.1 Original Tender Awards (2023–2025)', '3.2 Purchase Orders Issued Under the Original Tender',
        '3.3 Approved Tender Excesses and Delivery Status', '4. Clinical Importance of Rivaroxaban',
        '5. Request for Quotations and Evaluation', '6. Recommendation and Approval Requested',
    ];
    $at = array_map(fn (string $h) => strpos($html, $h), $headings);
    expect($at)->not->toContain(false);
    expect($at)->toBe(collect($at)->sort()->values()->all()); // in this order

    expect($html)
        ->toContain('October 21, 2026')->toContain('Dear Dr. St. Bernard,')
        ->toContain('Re: Request for the Approval of an Ad Hoc Purchase of Rivaroxaban 20mg Tablet - DIN 90008557')
        // 1
        ->toContain('<p>The National Insurance Property Development Company Limited (NIPDEC) seeks the Ministry of Health’s approval for the ad hoc purchase of twenty-nine thousand nine hundred and eighty-eight (29,988) tablets of Rivaroxaban 20mg Tablet (DIN 90008557) from Pharmtech Caribbean Ltd at a total cost of $359,856.00.</p>')
        ->toContain('<p>This purchase is required to provide an interim supply of the medication pending delivery of an outstanding purchase order.</p>')
        // 2
        ->toContain('At present, there is nil stock of Rivaroxaban 20mg Tablet at Central Stores, with an average monthly consumption of approximately 10,417 tablets.')
        // 3.1, with the pack sizes
        ->toContain('Under the 2023–2025 tender, Rivaroxaban 20mg Tablet was awarded to two (2) suppliers. The details are outlined below.')
        ->toContain('<td>Smith Robertson & Co. Ltd</td><td>Bayer AG (Germany)</td><td>Xarelto 20mg Tab</td><td>6,250 boxes of 28 tablets</td><td>70%</td><td>$368.20 per box of 28 tablets</td><td>$2,301,250.00</td>')
        ->toContain('<td>2,500 boxes of 30 tablets</td><td>30%</td><td>$65.23 per box of 30 tablets</td><td>$163,075.00</td>')
        // 3.2: every original order delivered
        ->toContain('Purchase orders were subsequently issued to the awarded suppliers. All quantities ordered under the original tender were delivered, as detailed below.')
        ->toContain('<td>Alstons Marketing Company Limited</td><td>076232</td><td>1,167 boxes of 30 tablets</td><td>December 28, 2023</td><td>1,167 boxes of 30 tablets</td><td>0</td><td>Delivered</td>')
        // 3.3
        ->toContain('To maintain a continuous supply of Rivaroxaban 20mg Tablet to the public health system, four (4) tender excesses were submitted to and subsequently approved by the Ministry of Health. The corresponding purchase orders and delivery statuses are outlined below.')
        ->toContain('<td>078830</td><td>8,666 boxes of 30 tablets</td><td>January 20, 2026</td><td>0</td><td>8,666 boxes of 30 tablets</td><td>No confirmed ETA provided by supplier; no confirmed shipping date</td>')
        // 4
        ->toContain('<p>Rivaroxaban is indicated for the following:</p>')
        ->toContain('<li>Prevention of stroke and systemic embolism in patients with non-valvular atrial fibrillation.</li>')
        ->toContain('<li>Thromboprophylaxis in paediatric patients aged two (2) years and older with congenital heart disease following the Fontan procedure.</li>')
        // 5
        ->toContain('Given the current stock position, a Request for Quotations (RFQ) was issued to prequalified suppliers to provide the system with stock. Five (5) quotations were received, with the details presented in the attached comparison sheet.')
        ->toContain('<p>Following consideration of the quotations, Bids 1, 2, 3 and 5 were not considered due to their respective lead times.</p>')
        ->toContain('<p>Based on availability and lead time, and subject to the quotation fulfilling the requirements of the Ministry of Health, the proposed purchase is recommended from Pharmtech Caribbean Ltd.</p>')
        // 6
        ->toContain('<p>In light of the nil stock position at Central Stores, the average monthly consumption of approximately 10,417 tablets, and the absence of a confirmed delivery date for the outstanding purchase order, approval is requested for the following ad hoc purchase:</p>')
        ->toContain('<th>Description</th><th>Details</th>')
        ->toContain('<td>Item</td><td>Rivaroxaban 20mg Tablet</td>')
        ->toContain('<td>DIN</td><td>90008557</td>')
        ->toContain('<td>Recommended Supplier</td><td>Pharmtech Caribbean Ltd</td>')
        ->toContain('<td>Quantity</td><td>29,988 tablets</td>')
        ->toContain('<td>Total Cost</td><td>$359,856.00</td>')
        ->toContain('<td>Purpose</td><td>Interim supply pending delivery of Purchase Order No. 078830</td>')
        ->toContain('Submitted for your consideration and approval.')->toContain('Yours faithfully,')
        ->toContain('<p class="organisation">NATIONAL INSURANCE PROPERTY DEVELOPMENT COMPANY LIMITED</p>')
        ->toContain('Jane Applicant')->toContain('Procurement Officer')
        // The annex: the RFQ number and the recommended row marked.
        ->toContain('Quotation Comparison')->toContain('<th>RFQ No.</th><td>RFQ 142/2026</td>')
        ->toContain('<th>Required Quantity</th><td>29,988 tablets</td>')
        ->toContain('<tr class="recommended">')
        // Gone with the old layout.
        ->not->toContain('Your approval is sought')->not->toContain('Justification for the recommendation')
        ->not->toContain('/Attach')->not->toContain('DRAFT – NOT SUBMITTED')
        ->not->toContain('—')->not->toContain('[');
});

it('shows 3.3 for a Yes answer or a Tender Excess, and leaves it out for an Ad Hoc No', function () {
    [$no] = rlRivaroxaban(previousRequest: false, submit: false); // a draft keeps the hidden rows
    [$excess] = rlChlorpromazine();

    expect(rlHtml($no))->toContain('3.2 Purchase Orders Issued Under the Original Tender')
        ->not->toContain('Approved Tender Excesses')->not->toContain('<td>078830</td>')->not->toContain('tender excesses were submitted');

    expect(rlHtml($excess))
        ->toContain('3.3 Approved Tender Excesses and Delivery Status')
        ->toContain('To maintain a continuous supply of Chlorpromazine HCL 25mg to the public health system, one (1) tender excess was submitted to and subsequently approved by the Ministry of Health.')
        ->toContain('<td>078010</td>');
});

it('says all original orders were delivered only when they were', function () {
    [$case] = rlBimatoprost();

    expect(rlHtml($case))
        ->toContain('Purchase orders were subsequently issued to the awarded supplier. The details are outlined below.')
        ->not->toContain('All quantities ordered')
        // Other prints its description; the delivery note number is never a status.
        ->toContain('<td>079128</td><td>8,000 each</td><td>May 28, 2026</td><td>4,000 each</td><td>4,000 each</td><td>Variation of tender in progress</td>')
        ->toContain('<td>Not Delivered</td>')->not->toContain('DN-4471');
});

it('writes the Tender Excess letter (Chlorpromazine)', function () {
    [$case] = rlChlorpromazine();

    expect(rlHtml($case))
        ->toContain('Re: Request for the Approval to Purchase in Excess of Tender- Chlorpromazine HCL 25mg – DIN 90000195')
        ->toContain('seeks the Ministry of Health’s approval for the purchase in excess of tender of eight hundred thousand (800,000) each of Chlorpromazine HCL 25mg (DIN 90000195) from Alstons Marketing Company Limited at a total cost of $198,000.00.')
        ->toContain('At present, there are eighty thousand (80,000) each in stock at Central Stores, with an average monthly consumption of approximately 70,000 each.')
        ->toContain('<th>Item</th><th>Expiry Date</th>')->toContain('June 30, 2027')
        ->toContain('<td>800,000 each</td><td>100%</td><td>$0.22 each</td><td>$176,000.00</td>')
        ->toContain('a Request for Quotations (RFQ) was issued to prequalified suppliers to provide the system with stock. One (1) quotation was received')
        ->toContain('<p>Subject to the quotation fulfilling the requirements of the Ministry of Health, the proposed purchase is recommended from Alstons Marketing Company Limited.</p>')
        ->toContain('In light of the current stock position at Central Stores and the average monthly consumption of approximately 70,000 each, approval is requested for the following purchase in excess of tender:')
        ->toContain('<td>Purpose</td><td>Continuity of supply until the next tender</td>')
        ->toContain('| Pharmaceutical Division |') // the footer's fallback while the submitter has no division
        // No clinical note: section 4 is left out and the rest close up.
        ->not->toContain('Clinical Importance')
        ->toContain('4. Request for Quotations and Evaluation')->toContain('5. Recommendation and Approval Requested')
        ->not->toContain('Variation Requested');
});

it('writes the Tender Variation letter (Bimatoprost) with Variation Requested', function () {
    [$case] = rlBimatoprost();
    $html = rlHtml($case);

    expect($html)
        ->toContain('Re: Request for Variation of Tender – Change of Manufacturer and Country of Origin for Bimatoprost Opth. 0.01%/2.5ml Dro – DIN 90006473')
        ->toContain('seeks the Ministry of Health’s approval for a variation of tender (Change of Manufacturer and Country of Origin) of sixty thousand (60,000) each of Bimatoprost Opth. 0.01%/2.5ml Dro (DIN 90006473).</p>')
        // No stock entered: Current Stock Position is left out, so Background is section 2.
        ->toContain('2. Procurement Background')->toContain('2.1 Original Tender Awards (2023–2025)')->toContain('2.2 Purchase Orders Issued Under the Original Tender')
        ->toContain('<p>Bimatoprost Opth. is indicated for the following:</p>')
        ->toContain('<li>Bimatoprost is used for the reduction of elevated intraocular pressure')
        ->toContain('4. Variation Requested')
        ->toContain('<th>Variation Type</th><th>Existing Approved Detail</th><th>Proposed Revised Detail</th><th>Effective Date</th>')
        ->toContain('5. Recommendation and Approval Requested')
        ->toContain('<p>Approval is requested for the following variation of tender:</p>')
        ->toContain('<td>Variation Type</td><td>Change of Manufacturer and Country of Origin</td>')
        ->toContain('<td>Existing Approved Detail</td><td>Allergan Inc. (United States)</td>')
        ->toContain('<td>Proposed Revised Detail</td><td>AbbVie Ltd. (Ireland)</td>')
        ->toContain('<td>Effective Date</td><td>PO 079128 and PO 079209</td>')
        // No stock entered, no quotations: no section 2, no RFQ, no supplier or cost, no annex.
        ->not->toContain('Current Stock Position')->not->toContain('Request for Quotations')
        ->not->toContain('at a total cost')->not->toContain('Recommended Supplier')->not->toContain('Quotation Comparison')
        // The answer is Yes, but a Tender Variation shows no Approved Tender Excesses table without rows.
        ->not->toContain('Approved Tender Excesses');
});

it('watermarks a draft and dates it today', function () {
    [$draft] = rlChlorpromazine(submit: false);

    expect(rlHtml($draft))->toContain('DRAFT – NOT SUBMITTED')->toContain('October 21, 2026');
});

it('leaves out what was not entered, never a placeholder', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY', rlVendor());
    saveDraft($case, $vendor, ['requisition' => ['item_description' => 'Paracetamol', 'quantity_requested' => '10', 'unit_of_measure' => 'Each']]);
    $html = rlHtml($case->fresh());

    expect($html)->toContain('approval for the ad hoc purchase of ten (10) each of Paracetamol.</p>')
        ->toContain('1. Purpose of Request')->toContain('2. Recommendation and Approval Requested')
        ->toContain('<p>Approval is requested for the following ad hoc purchase:</p>')
        ->toContain('<td>Quantity</td><td>10 each</td>')
        ->not->toContain('DIN')->not->toContain('Stock Position')->not->toContain('Procurement Background')
        ->not->toContain('Recommended Supplier')->not->toContain('Purpose</td>')
        ->not->toContain('Quotation Comparison')->not->toContain('[')->not->toContain('—');
});

it('downloads a PDF for the vendor at any status and audits it', function () {
    [$case, $vendor] = rlRivaroxaban();

    $response = $this->actingAs($vendor)->get(route('requests.letter', $case))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->streamedContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('.pdf');

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseLetterExported->value)->sole();
    expect(json_decode($audit->metadata, true))
        ->toBe(['audience' => 'vendor', 'draft' => false, 'kind' => 'ad_hoc', 'attached' => count(requiredPurposes($case))]);
})->skip(rlNoQpdf(...), 'qpdf is not installed (QPDF_PATH).');

it('is 404 for another organisation, and for staff on a draft or an unassigned case', function () {
    [$case] = rlRivaroxaban();
    [$draft] = rlChlorpromazine(submit: false);

    $this->actingAs(vendorUser(vendorOrg()))->get(route('requests.letter', $case))->assertNotFound();
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.letter', $draft))->assertNotFound();
    $this->actingAs(caseUser(Role::Technocrat))->get(route('cases.letter', $case))->assertNotFound();

    expect(DB::table('audit_logs')->where('action', AuditAction::CaseLetterExported->value)->whereIn('case_id', [$case->id, $draft->id])->exists())->toBeFalse();
});

it('lets staff who can view the case download it', function () {
    [$case] = rlBimatoprost();

    $response = $this->actingAs(caseUser(Role::Cmo))->get(route('cases.letter', $case))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->streamedContent())->toStartWith('%PDF');
    expect(DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseLetterExported->value)->value('metadata'))
        ->toContain('"audience":"staff"');
});

it('never carries internal data', function () {
    [$case] = rlRivaroxaban();
    DB::table('cases')->where('id', $case->id)->update(['status' => 'IN_REVIEW', 'funding_track' => 'NOT_CONFIRMED']);
    $cab = caseUser(Role::CabOfficer, ['name' => 'Cab Staff Name']);
    (new CaseTrackEvent)->forceFill([
        'case_id' => $case->id, 'track' => 'funding', 'outcome' => 'not_confirmed',
        'reason' => 'Internal: move funds from vote 12.', 'actor_id' => $cab->id, 'created_at' => now(),
    ])->save();
    makeDocument($case, requiredPurposes($case)[0] ?? DocumentPurpose::cases()[0]->value, ['visibility' => 'INTERNAL', 'scan_status' => 'CLEAN', 'original_name' => 'internal-memo.pdf']);

    $html = rlHtml(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id));

    expect($html)->not->toContain('vote 12')->not->toContain('internal-memo.pdf')->not->toContain('Cab Staff Name')
        ->not->toContain('Not confirmed');
});

// ---- Appended documents (Kyle, 2026-11-25): the uploads follow the letter as pages, through qpdf.

function rlTempPdf(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'rl').'.pdf';
    file_put_contents($path, $bytes);

    return $path;
}

function rlPages(string $bytes): ?int
{
    return Qpdf::pages(rlTempPdf($bytes));
}

/** The PDF rewritten by qpdf in QDF form: uncompressed, so page content can be read. */
function rlQdf(string $bytes): string
{
    $process = new Process([Qpdf::binary(), '--qdf', '--object-streams=disable', rlTempPdf($bytes), '-']);
    $process->mustRun();

    return $process->getOutput();
}

function rlNoQpdf(): bool
{
    return rlPages(onePagePdfBytes()) !== 1;
}

it('appends the current external clean documents as pages, in upload order', function () {
    [$case] = rlRivaroxaban();
    $purposes = requiredPurposes($case);
    $before = rlPages(RequestLetter::for($case)->pdf());

    makeDocument($case, $purposes[0], ['_bytes' => onePagePdfBytes('Attachment one'), 'original_name' => 'bid.pdf', 'uploaded_at' => now()->addMinute()]);
    makeDocument($case, $purposes[1] ?? $purposes[0], ['_bytes' => onePagePdfBytes('Attachment two'), 'original_name' => 'evidence.pdf', 'uploaded_at' => now()->addMinutes(2)]);
    makeDocument($case, $purposes[0], ['_bytes' => onePagePdfBytes('Internal memo'), 'visibility' => 'INTERNAL']);
    makeDocument($case, $purposes[0], ['_bytes' => onePagePdfBytes('Infected file'), 'scan_status' => 'INFECTED']);
    makeDocument($case, $purposes[0], ['_bytes' => onePagePdfBytes('Withdrawn file'), 'withdrawn_at' => now()]);

    $letter = RequestLetter::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id));
    $bytes = $letter->pdf();
    $qdf = rlQdf($bytes);

    expect(rlPages($bytes))->toBe($before + 2);
    expect($qdf)->toContain('Attachment one')->toContain('Attachment two')
        ->not->toContain('Internal memo')->not->toContain('Infected file')->not->toContain('Withdrawn file');
    expect(strpos($qdf, 'Attachment one'))->toBeLessThan(strpos($qdf, 'Attachment two'));
    expect($letter->html())->not->toContain('/Attach')->not->toContain('bid.pdf');
})->skip(rlNoQpdf(...), 'qpdf is not installed (QPDF_PATH).');

it('lists a document it cannot append', function () {
    [$case] = rlRivaroxaban();
    $before = rlPages(RequestLetter::for($case)->pdf());
    makeDocument($case, requiredPurposes($case)[0], ['_bytes' => '%PDF-1.4 damaged', 'original_name' => 'scan.pdf']);

    $letter = RequestLetter::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id));

    expect($letter->html())->toContain('/Attach (not appended)')->toContain('scan.pdf');
    expect(rlPages($letter->pdf()))->toBe($before);
})->skip(rlNoQpdf(...), 'qpdf is not installed (QPDF_PATH).');

it('watermarks the appended pages of a draft', function () {
    [$case] = rlChlorpromazine(submit: false);
    makeDocument($case, requiredPurposes($case)[0] ?? DocumentPurpose::cases()[0]->value, ['_bytes' => onePagePdfBytes('Attachment one')]);

    $bytes = RequestLetter::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->pdf();

    expect(rlPages($bytes))->toBeGreaterThanOrEqual(2);
    // qpdf lays the stamp page over each appended page as a form XObject.
    expect(rlQdf($bytes))->toContain('/Fx1');
})->skip(rlNoQpdf(...), 'qpdf is not installed (QPDF_PATH).');

it('lists the documents when qpdf is not installed', function () {
    config(['procurement.letter.qpdf' => 'C:/not-installed/qpdf.exe']);
    [$case] = rlRivaroxaban();
    makeDocument($case, requiredPurposes($case)[0], ['_bytes' => onePagePdfBytes(), 'original_name' => 'bid.pdf']);

    $letter = RequestLetter::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id));

    expect($letter->html())->toContain('/Attach (not appended)')->toContain('bid.pdf');
    expect($letter->pdf())->toStartWith('%PDF');
});

it('has no letter for the old Pharm / Non-Pharm types', function () {
    [$case] = legacyDraft('AD_HOC_NON_PHARM');

    expect(RequestLetter::for($case))->toBeNull();
});

/** [width, height] of each page of $bytes, from the pages' MediaBox (qpdf QDF form). */
function rlPageSizes(string $bytes): array
{
    preg_match_all('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', rlQdf($bytes), $m, PREG_SET_ORDER);

    return array_map(fn (array $match) => [(float) $match[1], (float) $match[2]], $m);
}

it('puts the Quotation Comparison on a landscape page after the letter, only with quotations', function () {
    [$withQuotes] = rlRivaroxaban();
    [$variation] = rlBimatoprost();

    $sizes = rlPageSizes(RequestLetter::for($withQuotes)->pdf());
    $landscape = array_keys(array_filter($sizes, fn (array $s) => $s[0] > $s[1]));
    $portrait = array_keys(array_filter($sizes, fn (array $s) => $s[0] < $s[1]));
    expect($landscape)->not->toBeEmpty()
        // After the letter's pages, before the appended documents (portrait one-page PDFs here).
        ->and(min($landscape))->toBeGreaterThan(0)
        ->and(min($landscape))->toBeGreaterThan(min($portrait));
    expect(rlQdf(RequestLetter::for($withQuotes)->pdf()))->toContain('Quotation Comparison');

    $variationSizes = rlPageSizes(RequestLetter::for($variation)->pdf());
    expect(array_filter($variationSizes, fn (array $s) => $s[0] > $s[1]))->toBeEmpty();
    expect(rlHtml($variation))->not->toContain('Quotation Comparison');
})->skip(rlNoQpdf(...), 'qpdf is not installed (QPDF_PATH).');

it('keeps the annex as a portrait page of the letter without qpdf', function () {
    [$case] = rlRivaroxaban();
    config(['procurement.letter.qpdf' => 'C:/not-installed/qpdf.exe']);

    $bytes = RequestLetter::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id))->pdf();

    expect($bytes)->toStartWith('%PDF')
        ->and(preg_match('/\/MediaBox\s*\[\s*0\s+0\s+792(\.0+)?\s+612(\.0+)?\s*\]/', $bytes))->toBe(0);
});

// Writes one PDF per kind for Kyle to compare with the reference letters.
// Run with: LETTER_SAMPLES=1 php artisan test --filter=RequestLetterTest
it('saves the sample PDFs', function () {
    $dir = base_path('storage/app/letter-samples');
    File::ensureDirectoryExists($dir);
    foreach (File::files($dir) as $old) {
        File::delete($old->getPathname()); // the samples of the old layout
    }

    $fixtures = [
        'rivaroxaban-ad-hoc' => rlRivaroxaban(...),
        'chlorpromazine-tender-excess' => rlChlorpromazine(...),
        'bimatoprost-tender-variation' => rlBimatoprost(...),
    ];

    foreach ($fixtures as $name => $fixture) {
        [$case] = $fixture();
        file_put_contents("{$dir}/{$name}.pdf", RequestLetter::for($case)->pdf());
        file_put_contents("{$dir}/{$name}.html", RequestLetter::for($case)->html());
    }

    expect(File::files($dir))->toHaveCount(6);
})->skip(fn () => ! getenv('LETTER_SAMPLES'), 'Set LETTER_SAMPLES=1 to write the sample PDFs.');
