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
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-24: the request letter PDF (App\Letters\RequestLetter), in the
// wording of NIPDEC's paper letters. Three fixtures match the reference letters:
// Chlorpromazine (Tender Excess), Bimatoprost (Tender Variation) and
// Rivaroxaban (Ad Hoc). The HTML is asserted before it becomes a PDF.
// DatabaseTransactions only.

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

/** Saves $payload, then the orders against the saved award ids, attaches documents and submits (unless $submit is false). */
function rlCase(string $code, array $payload, ?callable $orders = null, bool $submit = true): array
{
    [$case, $vendor] = newDraft($code, rlVendor());
    saveDraft($case, $vendor, $payload);

    if ($orders !== null) {
        $ids = CaseTenderAward::withoutGlobalScopes()->where('case_id', $case->id)->orderBy('sort_order')->pluck('id')->all();
        saveDraft($case, $vendor, ['purchase_orders' => $orders($ids)]);
    }

    if ($submit) {
        attachRequiredDocuments($case);
        app(SubmitCase::class)->handle($case, $vendor);
    }

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
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
            'stock_location' => 'the Central Stores', 'average_monthly_consumption' => '70000', 'quantity_on_hand' => '80000',
            'rfq_audience' => 'ALL_REGISTERED',
            'tender_price' => '0.2200', 'previous_price' => '0.2400',
        ],
        'tender_awards' => [
            ['supplier_name' => 'Alstons Marketing Company Limited', 'manufacturer' => 'Remedica Ltd.', 'country_of_origin' => 'Cyprus', 'brand' => 'Chlorpromazine', 'quantity_awarded' => '800000', 'unit_cost' => '0.2200', 'percentage_awarded' => '100'],
        ],
        'stock_expiries' => [['expiry_date' => '2027-06-30']],
        'quotations' => [
            adHocQuoteRow('Alstons Marketing Company Limited', '0.22', ['quantity_offered' => '800000', 'is_recommended' => true, 'lead_time_days' => 60, 'manufacturer' => 'Remedica Ltd.', 'country_of_origin' => 'Cyprus']),
        ],
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
    ], fn (array $ids) => [
        ['award_id' => $ids[0], 'po_number' => '076547', 'quantity_ordered' => '7000', 'ordered_on' => '2024-01-31', 'quantity_delivered' => '7000', 'delivery_state' => 'FULLY_DELIVERED', 'delivery_date' => '2024-03-01'],
        ['award_id' => $ids[0], 'po_number' => '077439', 'quantity_ordered' => '7000', 'ordered_on' => '2024-06-04', 'quantity_delivered' => '7000', 'delivery_state' => 'FULLY_DELIVERED', 'delivery_date' => '2024-07-15'],
        ['award_id' => $ids[0], 'po_number' => '078421', 'quantity_ordered' => '10000', 'ordered_on' => '2024-07-03', 'quantity_delivered' => '10000', 'delivery_state' => 'FULLY_DELIVERED', 'delivery_date' => '2024-08-20'],
        ['award_id' => $ids[0], 'po_number' => '079128', 'quantity_ordered' => '8000', 'ordered_on' => '2026-05-28', 'quantity_delivered' => '4000', 'delivery_state' => 'PARTIALLY_DELIVERED', 'delivery_date' => '2026-07-10'],
        ['award_id' => $ids[0], 'po_number' => '079209', 'quantity_ordered' => '10000', 'ordered_on' => '2026-06-18', 'quantity_delivered' => '0', 'delivery_state' => 'NOT_DELIVERED'],
    ]);
}

function rlRivaroxaban(): array
{
    return rlCase('AD_HOC_TENDER_UNABLE', [
        'previous_request_in_cycle' => false,
        'requisition' => [
            'item_description' => 'Rivaroxaban', 'strength' => '20mg', 'dosage_form' => 'Tablet', 'din' => '90008557',
            'quantity_requested' => '29988', 'unit_of_measure' => 'Each',
            'tender_reference' => 'NIPDEC Tender 2023/2025',
            'tender_cycle_start' => '2023-10-01', 'tender_cycle_end' => '2025-09-30',
            'tender_issue_explanation' => 'The awarded suppliers were unable to supply the full tender quantity.',
            'stock_location' => 'the Central Stores', 'average_monthly_consumption' => '2499', 'quantity_on_hand' => '1200',
            'rfq_audience' => 'ALL_REGISTERED',
        ],
        'tender_awards' => [
            ['supplier_name' => 'Smith Robertson & Co. Ltd', 'manufacturer' => 'Bayer AG', 'country_of_origin' => 'Germany', 'brand' => 'Xarelto 20mg Tab', 'quantity_awarded' => '6250', 'unit_cost' => '368.20', 'percentage_awarded' => '70'],
            ['supplier_name' => 'Alstons Marketing Company Limited', 'manufacturer' => 'Macleods Pharmaceutical Ltd', 'country_of_origin' => 'India', 'brand' => 'Rivaroxaban 20mg Tab', 'quantity_awarded' => '2500', 'unit_cost' => '65.23', 'percentage_awarded' => '30'],
        ],
        'stock_expiries' => [['expiry_date' => '2027-03-31']],
        'quotations' => [
            adHocQuoteRow('Alstons Marketing Company Limited', '2.30', ['quantity_offered' => '29988', 'is_recommended' => true, 'lead_time_days' => 30, 'manufacturer' => 'Macleods Pharmaceutical Ltd', 'country_of_origin' => 'India']),
            adHocQuoteRow('Supplier X Limited', '1.90', ['quantity_offered' => '29988', 'considered' => false, 'reason_accepted_excluded' => 'Product not registered with the Drug Inspectorate']),
        ],
    ]);
}

function rlHtml(ProcurementCase $case): string
{
    // Blade escapes "&"; the assertions read the text as printed.
    return html_entity_decode(RequestLetter::for($case)->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

it('writes the Tender Excess letter (Chlorpromazine)', function () {
    [$case] = rlChlorpromazine();
    $html = rlHtml($case);

    expect($html)
        ->toContain('October 21, 2026')
        ->toContain('Dr. Jeanine St. Bernard')->toContain('Chief Medical Officer (Ag)')->toContain('4-6 Queen\'s Park East')
        ->toContain('Dear Dr. St. Bernard,')
        ->toContain('Re: Request for the Approval to Purchase in Excess of Tender- Chlorpromazine HCL 25mg – DIN 90000195')
        ->toContain('Your approval is sought to purchase in excess of tender eight hundred thousand (800,000) each of Chlorpromazine HCL 25mg – DIN 90000195.')
        ->toContain('At the 2023/2025 Tender, Chlorpromazine HCL 25mg was awarded to one (1) supplier. The details of this award are summarized in the table below:')
        ->toContain('Remedica Ltd.<br>(Cyprus)')
        ->toContain('800,000 Each')->toContain('100%')->toContain('$0.22 Each')->toContain('$176,000.00')
        ->toContain('At present, there are eighty thousand (80,000) each in stock at the Central Stores. The average monthly consumption is approximately 70,000 each per month.')
        ->toContain('a Request for Quotations (RFQ) was issued to all registered suppliers, and one (1) quotation was received.')
        ->toContain('it is recommended that approval be granted to purchase 800,000 each of Chlorpromazine HCL 25mg – DIN 90000195 from Alstons Marketing Company Limited at a unit cost of $0.22 and a total cost of $198,000.00.')
        ->toContain('For comparison, the tender unit cost was $0.22 and the previous purchase unit cost was $0.24.')
        ->toContain('The lead time for delivery is sixty (60) days.')
        ->toContain('Quotation Comparison Sheet')
        ->toContain('Submitted for your consideration and approval.')->toContain('Yours faithfully,')
        ->toContain('NATIONAL INSURANCE PROPERTY DEVELOPMENT COMPANY LIMITED')
        ->toContain('Jane Applicant')->toContain('Procurement Officer')
        // Tender Excess has no required documents in the fixture: no attachment list.
        ->not->toContain('/Attach')
        ->not->toContain('DRAFT – NOT SUBMITTED')
        ->not->toContain('Variation Type');
});

it('writes the Tender Variation letter (Bimatoprost) with its orders', function () {
    [$case] = rlBimatoprost();
    $html = rlHtml($case);

    expect($html)
        ->toContain('Re: Request for Variation of Tender – Change of Manufacturer and Country of Origin for Bimatoprost Opth. 0.01%/2.5ml Dro – DIN 90006473')
        ->toContain('Your approval is sought for the Request for Variation of Tender – Change of Manufacturer and Country of Origin for Bimatoprost Opth. 0.01%/2.5ml Dro – DIN 90006473')
        ->toContain('At the 2023/2025 Tender, Bimatoprost Opth. 0.01%/2.5ml Dro was awarded to one (1) supplier.')
        ->toContain('Smith Robertson & Co. Ltd')->toContain('Allergan Inc.<br>(United States)')->toContain('Lumigan')
        ->toContain('60,000 Each')->toContain('100%')->toContain('$95.00 Each')->toContain('$5,700,000.00')
        ->toContain('Orders were placed with the awarded supplier. The details are summarized in the table below:')
        ->toContain('076547')->toContain('January 31, 2024')->toContain('Delivered March 1, 2024')
        ->toContain('079128')->toContain('8,000 Each')->toContain('4,000 Each')->toContain('Partially delivered')
        ->toContain('079209')->toContain('10,000 Each')->toContain('Not delivered')
        ->toContain('Bimatoprost is used for the reduction of elevated intraocular pressure')
        ->toContain('Variation Type')->toContain('AbbVie Ltd. (Ireland)')->toContain('PO 079128 and PO 079209')
        // Stock, RFQ, recommendation and the annex are Ad Hoc and Tender Excess only.
        ->not->toContain('At present')->not->toContain('Request for Quotations')->not->toContain('recommended')
        ->not->toContain('Quotation Comparison Sheet')->not->toContain('tender unit cost');
});

it('writes the Ad Hoc letter (Rivaroxaban) with two awarded suppliers', function () {
    [$case] = rlRivaroxaban();
    $html = rlHtml($case);

    expect($html)
        ->toContain('Re: Request for the Approval of an Ad Hoc Purchase of Rivaroxaban 20mg Tablet - DIN 90008557')
        ->toContain('Your approval is sought for the Ad Hoc Purchase of twenty-nine thousand nine hundred eighty-eight (29,988) each of Rivaroxaban 20mg Tablet - DIN 90008557.')
        ->toContain('At the 2023/2025 Tender, Rivaroxaban 20mg Tablet was awarded to two (2) suppliers. The details of this award are summarized in the table below:')
        ->toContain('Bayer AG<br>(Germany)')->toContain('70%')->toContain('$368.20 Each')->toContain('$2,301,250.00')
        ->toContain('Macleods Pharmaceutical Ltd<br>(India)')->toContain('30%')->toContain('$65.23 Each')->toContain('$163,075.00')
        ->toContain('At present, there are one thousand two hundred (1,200) each in stock at the Central Stores.')
        ->toContain('March 31, 2027')
        ->toContain('and two (2) quotations were received. The details are summarised in the quotation comparison sheet.')
        ->toContain('The quotation from Supplier X Limited was not considered: Product not registered with the Drug Inspectorate.')
        ->toContain('it is recommended to procure twenty-nine thousand nine hundred eighty-eight (29,988) each of Rivaroxaban 20mg Tablet – DIN 90008557 from Alstons Marketing Company Limited at a total cost of $77,593.95. The unit cost is $2.30.')
        ->toContain('The lead time for delivery is thirty (30) days.')
        ->toContain('Quotation Comparison Sheet')->toContain('$68,972.40')
        ->toContain('/Attach')->toContain('bids.pdf (Bids)')
        // The issues text shows for every type (2026-11-25). No orders entered: no orders table.
        // Hidden for Ad Hoc: the variation table and the tender price.
        ->toContain('The awarded suppliers were unable to supply the full tender quantity.')->not->toContain('Orders were placed')
        ->not->toContain('Variation Type')->not->toContain('tender unit cost');
});

it('watermarks a draft and dates it today', function () {
    [$draft] = rlChlorpromazine(submit: false);

    expect(rlHtml($draft))->toContain('DRAFT – NOT SUBMITTED')->toContain('October 21, 2026');
});

it('leaves out what was not entered, never a placeholder', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY', rlVendor());
    saveDraft($case, $vendor, ['requisition' => ['item_description' => 'Paracetamol', 'quantity_requested' => '10', 'unit_of_measure' => 'Each']]);
    $html = rlHtml($case->fresh());

    expect($html)->toContain('Ad Hoc Purchase of ten (10) each of Paracetamol.')
        ->not->toContain('DIN')->not->toContain('At the')->not->toContain('At present')
        ->not->toContain('Quotation Comparison Sheet')->not->toContain('[')->not->toContain('—');
});

it('downloads a PDF for the vendor at any status and audits it', function () {
    [$case, $vendor] = rlRivaroxaban();

    $response = $this->actingAs($vendor)->get(route('requests.letter', $case))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->streamedContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('.pdf');

    $audit = DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CaseLetterExported->value)->sole();
    expect(json_decode($audit->metadata, true))->toBe(['audience' => 'vendor', 'draft' => false, 'kind' => 'ad_hoc']);
});

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

it('has no letter for the old Pharm / Non-Pharm types', function () {
    [$case] = legacyDraft('AD_HOC_NON_PHARM');

    expect(RequestLetter::for($case))->toBeNull();
});

// Writes one PDF per fixture for Kyle to compare with the paper letters.
// Run with: LETTER_SAMPLES=1 php artisan test --filter=RequestLetterTest
it('saves the sample PDFs', function () {
    $dir = base_path('storage/app/letter-samples');
    File::ensureDirectoryExists($dir);

    $fixtures = [
        'chlorpromazine-tender-excess' => rlChlorpromazine(...),
        'bimatoprost-tender-variation' => rlBimatoprost(...),
        'rivaroxaban-ad-hoc' => rlRivaroxaban(...),
        'chlorpromazine-draft' => fn () => rlChlorpromazine(submit: false),
    ];

    foreach ($fixtures as $name => $fixture) {
        [$case] = $fixture();
        file_put_contents("{$dir}/{$name}.pdf", RequestLetter::for($case)->pdf());
        file_put_contents("{$dir}/{$name}.html", RequestLetter::for($case)->html());
    }

    expect(File::files($dir))->not->toBeEmpty();
})->skip(fn () => ! getenv('LETTER_SAMPLES'), 'Set LETTER_SAMPLES=1 to write the sample PDFs.');
