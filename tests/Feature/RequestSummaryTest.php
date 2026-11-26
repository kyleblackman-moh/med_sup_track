<?php

use App\Enums\DocumentVisibility;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Http\Resources\Requests\RequestSummary;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\ProcurementTypeProfile;
use App\Submissions\RequestSections;
use App\Submissions\SubmitCase;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

// Slice V2-3: the one read-only request summary (RequestSummary) used by the
// Review step, the vendor's request page and the staff case page.
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

/** A complete draft of $code (both save steps, the variation for a Tender Variation). */
function summaryDraft(string $code = 'AD_HOC_TENDER_UNABLE'): array
{
    [$case, $vendor] = newDraft($code);
    saveCompleteAdHoc($case, $vendor);

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

/** The summary as $viewer gets it (signed in: the models' scopes follow the user, as in a request). */
function summaryOf(ProcurementCase $case, User $viewer, bool $forStaff = false): array
{
    test()->actingAs($viewer);

    return RequestSummary::for(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $viewer, $forStaff);
}

/** The section with $key. */
function summarySection(array $summary, string $key): ?array
{
    return collect($summary['sections'])->firstWhere('key', $key);
}

/** Every label => value pair of a section's field blocks. */
function summaryFields(array $summary, string $key): array
{
    return collect(summarySection($summary, $key)['blocks'])->where('type', 'fields')
        ->flatMap(fn (array $block) => collect($block['items'])->pluck('value', 'label'))->all();
}

/** A section's table block with $caption. */
function summaryTable(array $summary, string $key, string $caption): ?array
{
    return collect(summarySection($summary, $key)['blocks'])->where('type', 'table')->firstWhere('caption', $caption);
}

// 1. Sections, order and numbering -------------------------------------------------------

it('lists the type\'s visible sections in form order, numbered among the visible ones', function (string $code) {
    [$case, $vendor] = summaryDraft($code);
    $summary = summaryOf($case, $vendor);

    // V2-5: a complete Tender Variation answers Yes (saveCompleteAdHoc); the others answer No.
    $visible = RequestSections::visible($code, true, $code === 'TENDER_VARIATION');
    expect(array_column($summary['sections'], 'key'))->toBe(array_column($visible, 'key'))
        ->and(array_column($summary['sections'], 'label'))->toBe(array_column($visible, 'label'))
        ->and(array_column($summary['sections'], 'number'))->toBe(range(1, count($visible)))
        // 2026-11-25: Existing Approved Arrangement shows for every type; variation details on a Tender Variation.
        ->and(count($visible))->toBe($code === 'TENDER_VARIATION' ? 8 : 7)
        ->and($summary['sections'][0]['key'])->toBe('request_type')
        ->and($summary['type_label'])->toBe(procurementTypeByCode($code)->name);
})->with(ProcurementTypeProfile::REQUEST_TYPES);

it('shows Details of Variation Requested only when the answer is Yes (a complete Tender Variation here)', function (string $code) {
    [$case, $vendor] = summaryDraft($code);
    $summary = summaryOf($case, $vendor);

    if ($code === 'TENDER_VARIATION') {
        $table = summaryTable($summary, 'variation_details', 'Details of Variation Requested');
        expect(summarySection($summary, 'variation_details')['number'])->toBe(3)
            ->and(array_column($table['rows'], 'label'))->toBe(['Variation Row 1'])
            ->and($table['rows'][0]['cells'])->toBe([
                'variation_type' => 'Price',
                'existing_detail' => 'Unit cost 1.25 under the current contract.',
                'proposed_detail' => 'Unit cost 1.40 from the next delivery.',
                'effective_detail' => 'From 1 Jan 2027, PO-2207',
            ]);
    } else {
        expect(summarySection($summary, 'variation_details'))->toBeNull();
    }
})->with(ProcurementTypeProfile::REQUEST_TYPES);

// V2-5: section 1 and Details of Variation Requested ----------------------------------------

it('shows the previous-request answer in section 1 as Yes, No or a dash', function (?bool $answer, string $shown) {
    [$case, $vendor] = newDraft('TENDER_EXCESS');
    saveDraft($case, $vendor, [
        'previous_request_in_cycle' => $answer,
        'requisition' => ['tender_cycle_start' => '2026-01-01', 'tender_cycle_end' => '2026-12-31'],
    ]);
    $summary = summaryOf($case, $vendor);

    expect($summary['sections'][0])->toMatchArray(['key' => 'request_type', 'number' => 1, 'label' => 'Request Type'])
        ->and(summaryFields($summary, 'request_type'))->toBe([
            'Request Type' => 'Tender - Excess',
            'Tender Cycle Start' => '1 Jan 2026',
            'Tender Cycle End' => '31 Dec 2026',
            RequestSummary::PREVIOUS_REQUEST_LABEL => $shown,
        ]);
})->with(['yes' => [true, 'Yes'], 'no' => [false, 'No'], 'unanswered' => [null, Display::EMPTY]]);

it('shows the variation rows as a table when the answer is Yes, with a dash for an empty value', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    saveDraft($case, $vendor, ['previous_request_in_cycle' => true, 'variation_rows' => [
        variationEntry(),
        ['variation_type' => 'Quantity', 'proposed_detail' => '3,600 units'],
    ]]);
    $table = summaryTable(summaryOf($case, $vendor), 'variation_details', 'Details of Variation Requested');

    expect(array_column($table['columns'], 'label'))->toBe(['Variation Type', 'Existing Approved Detail', 'Proposed Revised Detail', 'Effective Date / Application P.O.'])
        ->and(array_column($table['rows'], 'label'))->toBe(['Variation Row 1', 'Variation Row 2'])
        ->and($table['rows'][1]['cells'])->toBe([
            'variation_type' => 'Quantity', 'existing_detail' => Display::EMPTY,
            'proposed_detail' => '3,600 units', 'effective_detail' => Display::EMPTY,
        ])
        ->and($table['empty'])->toBeNull();
});

it('says "None entered" for a Yes with no variation rows', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, ['previous_request_in_cycle' => true]);

    expect(summaryTable(summaryOf($case, $vendor), 'variation_details', 'Details of Variation Requested'))
        ->toMatchArray(['rows' => [], 'empty' => RequestSummary::NONE]);
});

it('never shows the variation rows when the answer is not Yes, even if hidden rows exist', function (?bool $answer) {
    [$case, $vendor] = newDraft('TENDER_EXCESS'); // a Tender Variation always shows them (2026-11-25)
    saveDraft($case, $vendor, ['previous_request_in_cycle' => $answer, 'variation_rows' => [variationEntry()]]);
    $summary = summaryOf($case, $vendor);

    expect(summarySection($summary, 'variation_details'))->toBeNull()
        ->and(json_encode($summary))->not->toContain('Unit cost 1.25')->not->toContain('PO-2207');
})->with(['no' => false, 'unanswered' => null]);

it('is null for a case that is not on the request form', function () {
    [$case, $vendor] = legacyDraft('AD_HOC_PHARM');

    expect(RequestSummary::for($case, $vendor))->toBeNull();
});

// 2. Awards and orders never depend on the tender outcome ---------------------------------

it('shows awards and orders without a tender outcome, and never the outcome', function () {
    [$case, $vendor] = summaryDraft('TENDER_VARIATION');
    expect(DB::table('case_requisitions')->where('case_id', $case->id)->value('tender_outcome'))->toBeNull();

    $summary = summaryOf($case, $vendor);
    $awards = summaryTable($summary, 'awarded_supplier', 'Awarded Suppliers');
    $orders = summaryTable($summary, 'existing_arrangement', 'Orders');

    expect(array_column($awards['rows'], 'label'))->toBe(['Supplier Row 1', 'Supplier Row 2'])
        ->and($awards['empty'])->toBeNull()
        ->and(array_column($orders['rows'], 'label'))->toBe(['Order Row 1'])
        ->and($orders['rows'][0]['cells']['supplier'])->toBe('Supplier A')
        ->and(json_encode($summary))->not->toContain('Outcome');
});

it('says "None entered" for a table with no rows and a dash for empty optional values', function () {
    [$case, $vendor] = newDraft('AD_HOC_EMERGENCY');
    $summary = summaryOf($case, $vendor);

    expect(summaryTable($summary, 'awarded_supplier', 'Awarded Suppliers')['empty'])->toBe(RequestSummary::NONE)
        ->and(summaryTable($summary, 'existing_arrangement', 'Orders')['empty'])->toBe(RequestSummary::NONE) // 2026-11-25: every type
        ->and(summaryTable($summary, 'quotation_comparison', 'Quotations')['rows'])->toBe([])
        ->and(summaryFields($summary, 'item_tender')['Strength'])->toBe(Display::EMPTY)
        ->and(summaryFields($summary, 'stock')['Days on Hand'])->toBe(Display::EMPTY);
});

// 3. Every V2-1 field ----------------------------------------------------------------------

it('includes every V2-1 field', function () {
    [$case, $vendor] = summaryDraft();
    $summary = summaryOf($case, $vendor);
    $item = summaryFields($summary, 'item_tender');
    $awards = summaryTable($summary, 'awarded_supplier', 'Awarded Suppliers');
    $quotes = summaryTable($summary, 'quotation_comparison', 'Quotations');

    // V2-5: the type, the tender cycle and the question are section 1.
    expect(summaryFields($summary, 'request_type'))->toBe([
        'Request Type' => 'Ad Hoc - Tender Unable to Supply',
        'Tender Cycle Start' => '1 Jan 2026',
        'Tender Cycle End' => '31 Oct 2026',
        RequestSummary::PREVIOUS_REQUEST_LABEL => 'No',
    ])
        ->and($item)->toMatchArray([
            'DIN' => '02345678',
            'Contract Reference' => 'TENDER-2026-14 (2026-2027)',
        ])
        ->and($item)->not->toHaveKey('Tender Cycle Start')
        ->and(summaryFields($summary, 'stock')['Qty on Order'])->toBe('1,800 Each')
        ->and($awards['rows'][0]['cells']['country_of_origin'])->toBe('Country A')
        ->and($awards['rows'][1]['cells']['country_of_origin'])->toBe(Display::EMPTY)
        ->and(array_column($quotes['columns'], 'label'))->toContain('Ex Stock', 'Lead Time (Days)', 'Remarks', 'Country of Origin')
        ->and($quotes['rows'][1]['cells'])->toMatchArray([
            'ex_stock' => '500 Each', 'lead_time_days' => '30', 'remarks' => 'Two-week delivery.', 'country_of_origin' => 'Country B', 'recommended' => 'Yes',
        ])
        ->and($quotes['rows'][0]['cells'])->toMatchArray(['considered' => 'No', 'reason' => 'No registration on file.', 'ex_stock' => Display::EMPTY]);
});

// 4. Computed values, formatted by the server ----------------------------------------------

it('computes and formats every figure on the server', function () {
    [$case, $vendor] = summaryDraft();
    $summary = summaryOf($case, $vendor);
    $awards = summaryTable($summary, 'awarded_supplier', 'Awarded Suppliers');

    expect(summaryFields($summary, 'awarded_supplier'))->toBe([
        'No. of Suppliers Awarded' => '2',
        'Total Cost of the Awards' => '$3,535.00',
    ])
        ->and(array_map(fn ($row) => [$row['cells']['percentage'], $row['cells']['line_cost'], $row['cells']['unit_cost']], $awards['rows']))
        ->toBe([['75.00%', '$2,625.00', '$1.25'], ['25.00%', '$910.00', '$1.30']])
        ->and(array_column(summaryTable($summary, 'existing_arrangement', 'Orders')['rows'], 'label'))->toBe(['Order Row 1']) // 2026-11-25: every type
        // 2026-11-24: the consumption and the quantity on hand are quantities only (no unit of measure).
        ->and(summaryFields($summary, 'stock'))->toMatchArray([
            'Average Monthly Consumption' => '500', 'Qty on Hand' => '200.5', 'Days on Hand' => '12', 'No Stock on Hand' => 'No',
        ])
        ->and(summaryTable($summary, 'stock', 'Stock Batches')['columns'])->toHaveCount(1)
        ->and(summaryFields($summary, 'quotation_comparison'))->toMatchArray([
            'RFQ Sent To' => 'All registered suppliers',
            'No. of Quotations' => '2',
            'Quotations Considered' => '1',
            'Recommended Quotation' => 'Supplier D',
            'Recommended Unit Price' => '$1.5975',
            'Total Cost (incl. VAT)' => '$5,032.13', // 4,473.00 + 12.5% VAT (2026-11-23)
            'Quoted Price Is Acceptable' => 'Yes',
        ])
        // 2026-11-23: the tender and previous price show for Tender Excess only.
        ->and(summaryFields($summary, 'quotation_comparison'))->not->toHaveKeys(['Tender Price', 'Change Against Tender Price', 'Change Against Previous Price']);
});

it('shows the tender and previous price comparison for Tender Excess', function () {
    [$case, $vendor] = summaryDraft('TENDER_EXCESS');

    expect(summaryFields(summaryOf($case, $vendor), 'quotation_comparison'))->toMatchArray([
        'Tender Price' => '$1.25',
        'Previous Price' => '$1.50',
        'Change Against Tender Price' => '27.80%',
        'Change Against Previous Price' => '6.50%',
    ]);
});

it('words the expiry warning from the submission date once submitted', function () {
    [$case, $vendor] = readyDraft('AD_HOC_EMERGENCY');
    $submitted = CarbonImmutable::now('America/Port_of_Spain')->startOfDay()->subDays(60);
    $expiry = $submitted->addDays(10); // expired today, 10 days ahead on the submission date
    saveDraft($case, $vendor, [
        'requisition' => ['average_monthly_consumption' => '30', 'quantity_on_hand' => '100'],
        'stock_expiries' => [['expiry_date' => $expiry->toDateString()]],
    ]);

    // A DRAFT is measured from today: the batch has expired.
    $draftWarning = collect(summarySection(summaryOf($case, $vendor), 'stock')['blocks'])->firstWhere('type', 'warning');
    expect($draftWarning['lines'])->toBe(['Batch expiring '.$expiry->format('j M Y').' has expired']);

    // Submitted (the real flow), then dated 60 days ago (test setup: submitted_at only, not the status).
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);
    DB::table('cases')->where('id', $case->id)->update(['submitted_at' => $submitted->utc()]);

    $warning = collect(summarySection(summaryOf($case, $vendor), 'stock')['blocks'])->firstWhere('type', 'warning');
    expect($warning['title'])->toBe('Some stock may expire before it is used')
        ->and($warning['lines'])->toBe(['Batch expiring '.$expiry->format('j M Y').': about 100 days of stock on hand, and it expires in 10 days (the earliest to expire)']);
});

it('states "No stock on hand" plainly when the batch total is 0, for the vendor and staff', function () {
    [$case, $vendor] = summaryDraft();
    // Test setup: the flag the server sets from a zero batch total.
    DB::table('case_requisitions')->where('case_id', $case->id)->update(['no_stock_on_hand' => true]);

    $block = collect(summarySection(summaryOf($case, $vendor), 'stock')['blocks'])->firstWhere('title', RequestSummary::NO_STOCK_TITLE);
    expect($block)->toBe(['type' => 'warning', 'title' => 'No stock on hand', 'lines' => [RequestSummary::NO_STOCK_LINE]])
        ->and(summaryFields(summaryOf($case, $vendor), 'stock')['No Stock on Hand'])->toBe('Yes');
});

it('shows no "No stock on hand" block while there is stock', function () {
    [$case, $vendor] = summaryDraft();

    expect(collect(summarySection(summaryOf($case, $vendor), 'stock')['blocks'])->firstWhere('title', RequestSummary::NO_STOCK_TITLE))->toBeNull();
});

// 5. Documents and isolation -------------------------------------------------------------------

it('lists the documents with the existing visibility rules', function () {
    [$case, $vendor] = readyDraft('AD_HOC_TENDER_UNABLE');
    makeDocument($case, 'bids', ['original_name' => 'bids.pdf']);
    makeDocument($case, 'bids', ['original_name' => 'scanning.pdf', 'scan_status' => ScanStatus::PENDING, 'released_at' => null]);
    makeDocument($case, 'bids', ['original_name' => 'internal.pdf', 'visibility' => DocumentVisibility::INTERNAL]);

    $names = fn (array $summary) => array_map(fn ($row) => $row['cells']['name'], summaryTable($summary, 'documents', 'Documents')['rows']);

    // Vendor: never internal files; files still being scanned are listed with their status.
    expect($names(summaryOf($case, $vendor)))->toContain('bids.pdf', 'scanning.pdf')->not->toContain('internal.pdf');
    // Staff (who never see a DRAFT): CLEAN files only, as the staff documents list.
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);
    expect($names(summaryOf($case, caseUser(Role::Cmo), forStaff: true)))->toContain('bids.pdf')->not->toContain('scanning.pdf');
});

it('never contains values from another case', function () {
    [$mine, $vendor] = summaryDraft('TENDER_VARIATION');
    [$other, $otherVendor] = newDraft('AD_HOC_TENDER_UNABLE');
    saveDraft($other, $otherVendor, [
        'requisition' => ['item_description' => 'Other organisation item', 'din' => '99999999'],
        'tender_awards' => [['supplier_name' => 'Other supplier', 'quantity_awarded' => '5', 'unit_cost' => '9.99']],
        // V2-5: another case's variation rows never show either.
        'previous_request_in_cycle' => true,
        'variation_rows' => [variationEntry(['existing_detail' => 'Other organisation variation'])],
    ]);
    makeDocument($other, 'bids', ['original_name' => 'other-case.pdf']);

    $json = json_encode(summaryOf($mine, $vendor));

    expect($json)->not->toContain('Other organisation item')
        ->not->toContain('Other organisation variation')
        ->not->toContain('99999999')
        ->not->toContain('Other supplier')
        ->not->toContain('other-case.pdf');
});

// 6. The three pages show the same summary ---------------------------------------------------

it('gives the staff case page and the vendor page the same summary of a submitted case', function () {
    [$case, $vendor] = readyDraft('TENDER_VARIATION');
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);

    $vendorSummary = pageProps($this->actingAs($vendor)->get(route('requests.show', $case->id))->assertOk())['summary'];
    $staffSummary = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case->id))->assertOk())['summary'];

    // NEXT part 5: neither page repeats the documents (each has its own list), so the two are identical.
    expect($staffSummary)->toBe($vendorSummary)
        ->and(summarySection($staffSummary, 'variation_details')['number'])->toBe(3)
        ->and(summaryTable($staffSummary, 'variation_details', 'Details of Variation Requested')['rows'][0]['label'])->toBe('Variation Row 1')
        ->and(summaryFields($staffSummary, 'request_type')[RequestSummary::PREVIOUS_REQUEST_LABEL])->toBe('Yes');
});

it('lists documents only on the Review step, never on the request page or the case page', function (string $code) {
    [$case, $vendor] = readyDraft($code);
    makeDocument($case, 'bids', ['original_name' => 'bids.pdf']);
    $visible = RequestSections::visible($code, true, $code === 'TENDER_VARIATION');
    $numbers = array_combine(array_column($visible, 'key'), range(1, count($visible)));

    // Review and submit: OVERNIGHT-3 (A1) the summary is on the Review page; Documents is the last section.
    $review = pageProps($this->actingAs($vendor)->get(route('requests.review', $case->id))->assertOk())['summary'];

    expect(array_column($review['sections'], 'number', 'key'))->toBe($numbers)
        ->and(summarySection($review, 'documents'))->not->toBeNull();

    // The vendor request page and the staff case page: no Documents section, the other numbers unchanged.
    app(SubmitCase::class)->handle(ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor);
    $vendorPage = pageProps($this->actingAs($vendor)->get(route('requests.show', $case->id))->assertOk());
    $staffPage = pageProps($this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case->id))->assertOk());

    foreach ([$vendorPage['summary'], $staffPage['summary']] as $summary) {
        expect(summarySection($summary, 'documents'))->toBeNull()
            ->and(array_column($summary['sections'], 'number', 'key'))->toBe(array_diff_key($numbers, ['documents' => true]));
    }
    // Each page still has its own document list.
    expect($vendorPage['documents'])->not->toBeEmpty();
})->with(['AD_HOC_TENDER_UNABLE', 'TENDER_VARIATION']);

it('keeps staff away from drafts on the case page, old types included', function () {
    [$legacy] = legacyDraft('AD_HOC_PHARM');
    // A DRAFT is never shown to staff (404, as today).
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $legacy->id))->assertNotFound();

    [$case] = summaryDraft('AD_HOC_EMERGENCY');
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.show', $case->id))->assertNotFound();
});

// 7. Static guards --------------------------------------------------------------------------

it('has no fetch or axios in the summary and the pages that show it', function () {
    $files = [
        resource_path('js/components/requests/request-summary.tsx'),
        resource_path('js/components/requests/ad-hoc/review.tsx'),
        resource_path('js/pages/requests/review.tsx'),
        resource_path('js/pages/requests/show.tsx'),
        resource_path('js/pages/cases/show.tsx'),
    ];

    foreach ($files as $file) {
        expect(preg_match('/\bfetch\s*\(|axios|XMLHttpRequest/', file_get_contents($file)))->toBe(0, basename($file));
    }
});

it('never adds a "%" sign to a value in React', function () {
    // The server formats percentages (Display::percent). Two non-data uses stay: a
    // skeleton's random CSS width (sidebar) and the browser's own upload progress (FORM-COMPACT-1: upload-progress.tsx).
    $allowed = ['sidebar.tsx', 'upload-progress.tsx'];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    $hits = [];
    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true) || in_array($file->getFilename(), $allowed, true)) {
            continue;
        }
        foreach (preg_split('/\R/', file_get_contents($file->getPathname())) as $i => $line) {
            $code = trim($line);
            if (str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue; // comments
            }
            if (preg_match('/\}\s*%|[\'"`]%[\'"`]|\+\s*[\'"]%/', $code)) {
                $hits[] = $file->getFilename().':'.($i + 1);
            }
        }
    }

    expect($hits)->toBe([]);
});
