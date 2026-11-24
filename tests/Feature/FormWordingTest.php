<?php

use App\Enums\CaseStatus;
use App\Enums\Role;
use App\Submissions\FormWording;
use App\Submissions\RequisitionMessages;
use App\Support\StaffFormMessages;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

// FORM-SAVE-1 (A5): wording. The on-screen label for Quantity on Hand, "needs"
// not "must", readiness rows named as the tables show them, one error summary
// with a server-made heading, and the staff forms on the same wording layer.

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

it('names Quantity on Hand as the Stock section shows it', function () {
    expect(RequisitionMessages::fieldLabel('quantity_on_order'))->toBe('Quantity on Hand')
        // 2026-11-24: on screen it is the read-only batch total, "Quantity on Hand (Total)".
        ->and(file_get_contents(resource_path('js/components/requests/ad-hoc/stock-section.tsx')))->toContain('label="Quantity on Hand (Total)"');
});

it('says "needs", never "must" or "invalid", in every vendor form message', function () {
    foreach (['AD_HOC_TENDER_UNABLE', 'AD_HOC_PHARM'] as $code) {
        foreach (RequisitionMessages::for(procurementTypeByCode($code)) as $message) {
            expect($message)->not->toMatch('/\bmust\b/i')->and($message)->not->toMatch('/\binvalid\b/i');
        }
    }
});

it('words the counts on the server, singular and plural', function () {
    expect(FormWording::partialSave(1))->toBe('Saved. 1 field needs attention and was not saved yet.')
        ->and(FormWording::partialSave(2))->toBe('Saved. 2 fields need attention and were not saved yet.')
        ->and(FormWording::issueHeading(1))->toBe('1 Thing Needs Fixing Before You Can Submit')
        ->and(FormWording::issueHeading(3))->toBe('3 Things Need Fixing Before You Can Submit');
});

it('sends the error summary heading with the form, and none when nothing blocks', function () {
    [$case, $vendor] = newDraft();
    $heading = pageProps($this->actingAs($vendor)->get(route('requests.edit', $case))->assertOk())['form']['issue_heading'];

    [$ready, $readyVendor] = readyDraft();
    $none = pageProps($this->actingAs($readyVendor)->get(route('requests.edit', $ready))->assertOk())['form']['issue_heading'];

    expect($heading)->toMatch('/^\d+ Things? Needs? Fixing Before You Can Submit$/')
        ->and($none)->toBeNull();
});

it('names readiness rows with the on-screen row and column labels', function () {
    [$case, $vendor] = newDraft();
    saveDraft($case, $vendor, [
        'tender_awards' => [['supplier_name' => 'A']],
        'quotations' => [['supplier_name' => 'Q', 'considered' => false]],
        'stock_expiries' => [['quantity' => '5']],
    ]);

    $messages = collect(pageProps($this->actingAs($vendor)->get(route('requests.edit', $case)))['request_sections'])
        ->flatMap(fn ($s) => $s['issues'])->pluck('message');

    expect($messages)->toContain('Supplier Row 1: Unit Cost is required.')
        ->and($messages)->toContain('Quotation Row 1: Unit Price is required.')
        ->and($messages)->toContain('Quotation Row 1: Reason Not Considered is required.')
        ->and($messages)->toContain('Batch 1: Expiry Date is required.');
});

it('shows one error summary at the top of the form, announced and in form order', function () {
    $page = file_get_contents(resource_path('js/pages/requests/ad-hoc/edit.tsx'));
    $review = file_get_contents(resource_path('js/components/requests/ad-hoc/review.tsx'));

    expect(substr_count($page, '<IssuesPanel'))->toBe(1)
        // Above the progress strip and the sections.
        ->and(strpos($page, '<IssuesPanel'))->toBeLessThan(strpos($page, '<ProgressStrip'))
        ->and($page)->toContain('form.issue_heading')
        ->and($review)->toContain("role={announce ? 'alert' : undefined}")
        ->and($review)->toContain('{item.sectionLabel}: {item.message}')
        // No duplicates; gone once nothing is left to fix.
        ->and($review)->toContain('seen.has(id)')
        ->and($review)->toContain('if (items.length === 0 || !heading)');
});

it('words the staff action forms through the same layer, with on-screen names', function () {
    $case = makeCase(['status' => CaseStatus::SUBMITTED, 'submitted_at' => now(), 'reference_no' => 'NR-WORD-'.Str::upper(Str::random(6))]);

    $this->actingAs(caseUser(Role::Cmo))->post(route('cases.hardcopy.reverse', $case), ['reason' => 'short'])
        ->assertSessionHasErrors(['reason' => 'Reason needs to be at least 10 characters.']);

    foreach (StaffFormMessages::FALLBACK as $rule => $message) {
        foreach ((array) $message as $text) {
            expect($text)->not->toMatch('/\bmust\b|\binvalid\b/i', "{$rule}: {$text}");
        }
    }
    foreach (StaffFormMessages::ATTRIBUTES as $label) {
        expect($label)->not->toContain('_')->and($label)->not->toContain('.');
    }
});
