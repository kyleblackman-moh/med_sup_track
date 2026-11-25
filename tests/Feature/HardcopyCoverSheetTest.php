<?php

use App\Enums\CaseStatus as S;
use App\Enums\Role;
use App\Http\Resources\Cases\CoverSheet;
use App\Models\CaseRequisition;
use App\Models\ProcurementCase;
use Database\Seeders\ProcurementTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

// FR-03: the printable hardcopy cover sheet, for the vendor and for staff.
// Ported from blackman/mfa-withdraw-hardcopy (0ad365f, fcd6fb6) on 2026-11-25;
// this branch has its own reference lookup (HardcopyLookupTest), so only the
// cover sheet comes across. DatabaseTransactions only.

describe('with the database', function () {
    beforeEach(function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ProcurementTypeSeeder::class);

        $this->org = vendorOrg();
        $this->vendor = vendorUser($this->org);
        $this->cmo = caseUser(Role::Cmo);
        $this->case = coverCase($this, S::SUBMITTED);
    });

    afterEach(function () {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    });

    it('gives the vendor and the CMO the cover sheet with the reference and a QR code', function () {
        foreach ([[$this->vendor, 'requests.cover-sheet'], [$this->cmo, 'cases.cover-sheet']] as [$user, $route]) {
            $this->actingAs($user)->get(route($route, $this->case))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('cover-sheet')
                    ->where('sheet.reference_no', $this->case->reference_no)
                    ->where('sheet.organisation_name', $this->org->name)
                    ->where('sheet.item_description', 'Cover sheet item')
                    ->where('sheet.qr_svg', fn (string $svg) => str_starts_with($svg, '<svg') && ! str_contains($svg, '<?xml')));
        }
    });

    it('has no cover sheet for a draft, and none for another organisation', function () {
        $draft = coverCase($this, S::DRAFT);
        $this->actingAs($this->vendor)->get(route('requests.cover-sheet', $draft))->assertNotFound();

        $this->actingAs(vendorUser(vendorOrg()))->get(route('requests.cover-sheet', $this->case))->assertNotFound();
    });

    it('never puts an internal id or link in the sheet', function () {
        $sheet = pageProps($this->actingAs($this->vendor)->get(route('requests.cover-sheet', $this->case)))['sheet'];

        expect(array_keys($sheet))->toBe(['id', 'reference_no', 'organisation_name', 'procurement_type', 'item_description', 'submitted_at', 'qr_svg', 'instruction'])
            // The SVG namespace is the only URL allowed: no link to the app or the case.
            ->and(str_replace('http://www.w3.org/2000/svg', '', $sheet['qr_svg']))->not->toContain('http')
            ->and($sheet['qr_svg'])->not->toContain(url('/'));
        expectVendorSafe(pageProps($this->actingAs($this->vendor)->get(route('requests.cover-sheet', $this->case))));
    });
});

function coverCase($t, S $status, ?string $reference = null): ProcurementCase
{
    $case = makeCase([
        'org_id' => $t->org->id,
        'created_by' => $t->vendor->id,
        'status' => $status,
        'reference_no' => $status === S::DRAFT ? null : ($reference ?? 'NR-CS-'.Str::upper(Str::random(8))),
        'submitted_at' => $status === S::DRAFT ? null : now()->subDay(),
    ]);
    (new CaseRequisition)->forceFill(['case_id' => $case->id, 'item_description' => 'Cover sheet item'])->save();

    return ProcurementCase::withoutGlobalScopes()->findOrFail($case->id);
}

it('draws the QR as an inline SVG of the reference only', function () {
    $svg = CoverSheet::qr('NR-TEST-0001');

    expect($svg)->toStartWith('<svg')
        ->and($svg)->not->toContain('<?xml')
        ->and(str_replace('http://www.w3.org/2000/svg', '', $svg))->not->toContain('http');
});

it('offers the cover sheet on the staff Next Step panel, the vendor request page and the receipt', function () {
    $next = file_get_contents(resource_path('js/components/cases/next-step-card.tsx'));
    $show = file_get_contents(resource_path('js/pages/requests/show.tsx'));
    $receipt = file_get_contents(resource_path('js/pages/requests/receipt.tsx'));

    // Beside Export PDF, for any case with a reference (the route checks who may see it).
    expect($next)->toMatch("/route\\('cases\\.letter', caseId\\).*?\\{reference && \\(\\s*<Link href=\\{route\\('cases\\.cover-sheet', caseId\\)\\}/s")
        ->and($show)->toMatch("/\\{detail\\.reference_no && \\(.*?route\\('requests\\.cover-sheet', detail\\.id\\)/s")
        ->and($receipt)->toContain("route('requests.cover-sheet', receipt.id)")
        ->and(file_exists(resource_path('js/pages/cover-sheet.tsx')))->toBeTrue();
});
