<?php

use App\Enums\AuditAction;
use App\Enums\DocumentPurpose;
use App\Enums\Role;
use App\Models\CaseTrackEvent;
use App\Models\ProcurementCase;
use App\Submissions\SubmitCase;
use Carbon\CarbonImmutable;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// Kyle, 2026-11-24: the printable request form (Inertia pages requests/print
// and cases/print, prop `print`). Vendor: own organisation, any status. Staff:
// anyone who can view the case. The request form only, nothing internal.
// Audited. DatabaseTransactions only.

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

/** A submitted, complete request of $code, with an order. */
function pfSubmitted(string $code = 'AD_HOC_TENDER_UNABLE'): array
{
    [$case, $vendor] = readyDraft($code);
    app(SubmitCase::class)->handle($case, $vendor);

    return [ProcurementCase::withoutGlobalScopes()->findOrFail($case->id), $vendor];
}

/** The `print` prop of an Inertia response. */
function pfProp(TestResponse $response): array
{
    return $response->viewData('page')['props']['print'];
}

/** Every label and cell value in the printed sections, flattened. */
function pfText(array $print): string
{
    return json_encode($print['sections'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

it('prints the vendor\'s own request at any status, a draft too', function () {
    [$draft, $vendor] = newDraft();
    saveDraft($draft, $vendor, adHocPayload());
    [$submitted, $vendor2] = pfSubmitted();

    $this->actingAs($vendor)->get(route('requests.print', $draft))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('requests/print')->where('print.header.status', 'Draft'));
    $this->actingAs($vendor2)->get(route('requests.print', $submitted))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('requests/print')
            ->where('print.header.reference', $submitted->reference_no)
            ->where('print.header.status', 'Submitted'));
});

it('is 404 for another organisation\'s request', function () {
    [$case] = pfSubmitted();
    $other = vendorUser(vendorOrg());

    $this->actingAs($other)->get(route('requests.print', $case))->assertNotFound();
});

it('lets staff who can view the case print it, and hides drafts and unassigned cases (404)', function () {
    [$case] = pfSubmitted();
    [$draft, $vendor] = newDraft();
    saveDraft($draft, $vendor, adHocPayload());

    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.print', $case))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('cases/print')->where('print.header.status', 'Submitted'));
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.print', $draft))->assertNotFound();
    $this->actingAs(caseUser(Role::Technocrat))->get(route('cases.print', $case))->assertNotFound(); // not assigned
});

it('carries every visible section in order, every value and the computed figures', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 02:30', 'UTC')); // 22:30 on the 23rd in Port of Spain
    [$case, $vendor] = pfSubmitted();

    $print = pfProp($this->actingAs($vendor)->get(route('requests.print', $case))->assertOk());
    $text = pfText($print);

    // 2026-11-24: Existing Approved Arrangement is Tender Variation only (its orders are discarded at submit).
    expect(array_column($print['sections'], 'key'))->toBe(['request_type', 'item_tender', 'awarded_supplier', 'stock', 'quotation_comparison', 'documents'])
        ->and(array_column($print['sections'], 'number'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($print['header'])->toMatchArray(['type' => $print['type_label'], 'printed_at' => '23 Nov 2026, 10:30 pm'])
        // Values as filled.
        ->and($text)->toContain('Infant feeding tube, size 6Fr')->toContain('Supplier D')->not->toContain('PO-1001')
        // The typed % awarded, days on hand, Subtotal / VAT / Total Price, total cost.
        ->and($text)->toContain('75.00%')->toContain('1,800')->toContain('Days on Hand')
        ->toContain('Subtotal')->toContain('$4,473.00')->toContain('$559.13')->toContain('$5,032.13');

    CarbonImmutable::setTestNow();
});

it('never carries internal data, for the vendor or for staff', function () {
    [$case, $vendor] = pfSubmitted();
    DB::table('cases')->where('id', $case->id)->update(['status' => 'IN_REVIEW', 'funding_track' => 'NOT_CONFIRMED']);
    $cab = caseUser(Role::CabOfficer);
    (new CaseTrackEvent)->forceFill([
        'case_id' => $case->id, 'track' => 'funding', 'outcome' => 'not_confirmed',
        'reason' => 'Internal: move funds from vote 12.', 'actor_id' => $cab->id, 'created_at' => now(),
    ])->save();
    makeDocument($case, requiredPurposes($case)[0] ?? DocumentPurpose::cases()[0]->value, ['visibility' => 'INTERNAL', 'scan_status' => 'CLEAN', 'original_name' => 'internal-memo.pdf']);

    foreach ([[$vendor, 'requests.print'], [caseUser(Role::Cmo), 'cases.print']] as [$user, $route]) {
        $print = pfProp($this->actingAs($user)->get(route($route, $case))->assertOk());
        $body = json_encode($print, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        expect($body)->not->toContain('vote 12')
            ->not->toContain('Virement')
            ->not->toContain('internal-memo.pdf')
            ->not->toContain('Visibility')
            ->and($print)->not->toHaveKeys(['queries', 'review', 'funding']);
    }
});

it('audits each print with the audience only', function () {
    [$case, $vendor] = pfSubmitted();

    $this->actingAs($vendor)->get(route('requests.print', $case))->assertOk();
    $this->actingAs(caseUser(Role::Cmo))->get(route('cases.print', $case))->assertOk();

    expect(DB::table('audit_logs')->where('case_id', $case->id)->where('action', AuditAction::CasePrinted->value)
        ->orderBy('id')->pluck('metadata')->map(fn ($m) => json_decode($m, true))->all())
        ->toBe([['audience' => 'vendor'], ['audience' => 'staff']]);
});
