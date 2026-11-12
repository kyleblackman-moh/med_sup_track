<?php

namespace Database\Seeders;

use App\Enums\DocumentPurpose as D;
use App\Enums\ProcurementCategory;
use App\Models\ProcurementType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Procurement types and their document checklists. Reference data: runs in
 * every environment (registered in DatabaseSeeder). Idempotent.
 *
 * Bootstrap defaults, draft until the CMO's office confirms (Q22, Q24).
 * Switch to create-only once an admin screen can edit these.
 */
class ProcurementTypeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->types() as $sortOrder => [$code, $name, $category, $active, $documents]) {
                $type = ProcurementType::updateOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'category' => $category, 'is_active' => $active, 'sort_order' => ($sortOrder + 1) * 10],
                );

                foreach ($documents as $i => [$purpose, $label, $required]) {
                    $type->documents()->updateOrCreate(
                        ['purpose' => $purpose->value],
                        ['label' => $label, 'is_required' => $required, 'sort_order' => ($i + 1) * 10],
                    );
                }

                // Remove documents no longer listed for this type.
                $type->documents()
                    ->whereNotIn('purpose', array_map(fn (array $d) => $d[0]->value, $documents))
                    ->delete();
            }
        });
    }

    /** @return list<array{0: string, 1: string, 2: ProcurementCategory, 3: bool, 4: list<array{0: D, 1: string, 2: bool}>}> */
    private function types(): array
    {
        $adHocRequired = [
            [D::original_rfq, 'Original RFQ / procurement request', true],
            [D::supplier_quotations, 'All supplier quotations', true],
            [D::evaluation_report, 'Evaluation and reasons for exclusions', true],
            [D::technical_evidence, 'Technical / sample assessment evidence', true],
            [D::signed_request, 'Signed request from the applicant', true],
            [D::stock_evidence, 'Stock position and consumption evidence', true],
        ];
        $otherSupporting = [D::other_supporting, 'Other supporting justification', false];
        $supplierCorrespondence = [D::supplier_correspondence, 'Supplier correspondence and lead-time confirmation', false];

        return [
            ['AD_HOC_PHARM', 'Ad hoc purchase (Pharm)', ProcurementCategory::PHARM, true, [
                ...$adHocRequired,
                $otherSupporting,
            ]],
            ['AD_HOC_NON_PHARM', 'Ad hoc purchase (Non-Pharm)', ProcurementCategory::NON_PHARM, true, [
                ...$adHocRequired,
                [D::product_specifications, 'Product specifications / technical datasheets', false],
                [D::rha_sample_confirmation, 'RHA / institution sample evaluation confirmations', false],
                $supplierCorrespondence,
                $otherSupporting,
            ]],
            ['TENDER_EXCESS', 'Purchase in excess of tender', ProcurementCategory::PHARM, true, [
                [D::signed_request, 'Signed request from the applicant', true],
                [D::stock_evidence, 'Stock position and consumption evidence', true],
                [D::supplier_confirmation, 'Supplier confirmation / revalidation of bid', false],
                [D::tender_pricing_confirmation, 'Quotation or tender pricing confirmation', false],
                [D::purchase_orders_delivery, 'Purchase orders and delivery records', false],
                [D::outstanding_order_status, 'Outstanding order / shipment status', false],
                $supplierCorrespondence,
                $otherSupporting,
            ]],
            // Inactive. The category is a placeholder: there is no template for
            // tender variations yet, so no documents either.
            ['TENDER_VARIATION', 'Tender variation', ProcurementCategory::PHARM, false, []],
        ];
    }
}
