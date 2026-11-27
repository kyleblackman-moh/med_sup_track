<?php

namespace App\Http\Resources\Requests;

use App\Models\CasePurchaseOrder;
use App\Models\CaseQuotation;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Submissions\ExpiryRisk;
use App\Submissions\FormWording;
use App\Submissions\ReadinessReport;
use App\Submissions\RequestSections;
use App\Submissions\RequisitionMessages;
use App\Support\Decimal;
use App\Support\Display;
use App\Support\QuotationPrice;

/**
 * Slice V2-2: the "form" prop of the request form. Additive; nothing here
 * decides anything new. It reshapes what the server already knows:
 *
 * - sections: every section in form order (RequestSections::ORDER) with its
 *   label, its number among the VISIBLE sections (null when hidden), whether
 *   it is visible, its state, the note when it waits for the type, and the
 *   field-level required flags (RequestSections::requiredFields). The state
 *   comes from AdHocSections::requestSections (RequestSections::state):
 *   blocked (waits for the type), incomplete (has issues), optional (nothing
 *   required for this type and nothing entered), else complete.
 * - display: the computed values as display strings (Display), per saved row
 *   by id, so the page does no maths and no formatting.
 * - expiry: the expiry warning from ExpiryRisk as structured items.
 *
 * Expects the case with procurementType, requisition and the child relations
 * loaded, in saved order.
 */
final class RequestFormView
{
    public const COMPLETE = RequestSections::COMPLETE;

    public const INCOMPLETE = RequestSections::INCOMPLETE;

    public const OPTIONAL = RequestSections::OPTIONAL;

    public const BLOCKED = RequestSections::BLOCKED;

    /** Kyle, 2026-11-26: the note at the end of a stock item's row. */
    public const MAY_EXPIRE = 'May expire before usage';

    public const EXPIRED_NOTE = 'Expired';

    /** @return array{sections: list<array<string, mixed>>, display: array<string, mixed>, expiry: array<string, mixed>} */
    public static function for(ProcurementCase $case, ReadinessReport $report): array
    {
        $risk = ExpiryRisk::for($case);

        return [
            'sections' => self::sections($case, $report),
            'display' => self::display($case, $risk),
            'expiry' => self::expiry($case, $risk),
            'issue_heading' => self::issueHeading($case, $report),
            // Kyle, 2026-11-27: whether Existing Approved Arrangement shows the Approved Tender Excesses
            // table (Tender Excess, or a Yes to the previous-request question). The server decides.
            'shows_previous_orders' => RequestSections::showsPreviousOrders(
                (string) $case->procurementType?->code,
                $case->previous_request_in_cycle,
            ),
        ];
    }

    /**
     * FORM-SAVE-1 (A5d): the error summary's heading when Review and Submit is
     * blocked ("3 Things Need Fixing Before You Can Submit"), counting each
     * section's issues once per message; null when nothing blocks.
     */
    public static function issueHeading(ProcurementCase $case, ReadinessReport $report): ?string
    {
        $count = collect(AdHocSections::requestSections($case, $report))
            ->flatMap(fn (array $section) => array_map(fn (array $issue) => $section['key'].'|'.$issue['message'], $section['issues']))
            ->unique()
            ->count();

        return $count === 0 ? null : FormWording::issueHeading($count);
    }

    /** @return list<array{key: string, label: string, number: int|null, visible: bool, state: string, note: string|null, required: array<string, bool>}> */
    public static function sections(ProcurementCase $case, ReadinessReport $report): array
    {
        $visible = collect(AdHocSections::requestSections($case, $report))->keyBy('key');
        $required = RequestSections::requiredFields((string) $case->procurementType?->code);

        $sections = [];
        $number = 0;
        foreach (RequestSections::ORDER as $key) {
            $section = $visible->get($key);
            $sections[] = [
                'key' => $key,
                'label' => RequestSections::LABELS[$key],
                'number' => $section === null ? null : ++$number,
                'visible' => $section !== null,
                // Hidden: nothing in it is required. Else the one rule (RequestSections::state).
                'state' => $section === null ? self::OPTIONAL : $section['state'],
                'note' => $section['note'] ?? null,
                'required' => $required[$key],
            ];
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    /** @param  array{days_on_hand: string|null, expiry_warnings: array<int, array{state: string, days_until_expiry: int, cover_days: string}>}  $risk */
    private static function display(ProcurementCase $case, array $risk): array
    {
        $daysOnHand = $risk['days_on_hand'];
        $awardTotals = $case->tenderAwards->map(fn (CaseTenderAward $a) => $a->total())->filter(fn ($t) => $t !== null);

        return [
            'stock_total' => Display::quantity($case->quantityOnHand()),
            'days_on_hand' => $daysOnHand ?? Display::EMPTY,
            'awards_total' => $awardTotals->isEmpty()
                ? Display::EMPTY
                : Display::money($awardTotals->reduce(fn (string $sum, string $t) => Decimal::add($sum, $t), '0')),
            'total_cost' => Display::money($case->totalCost()),
            // 2026-11-23: the form's live Subtotal / VAT / Total use this rate (QuotationPrice; never hard-coded in React).
            'vat_rate' => QuotationPrice::vatRate(),
            // V2-3: the price comparison's changes, with the sign added here (never in React).
            'price_change_vs_tender' => Display::percent($case->priceChangePercent($case->requisition?->tender_price)),
            'price_change_vs_previous' => Display::percent($case->priceChangePercent($case->requisition?->previous_price)),
            'rows' => [
                'tender_awards' => $case->tenderAwards->map(fn (CaseTenderAward $a) => [
                    'id' => $a->getKey(),
                    'percentage' => Display::percent($a->percentage_awarded), // typed by the vendor (2026-11-24)
                    'line_cost' => Display::money($a->total()),
                ])->values()->all(),
                'purchase_orders' => $case->purchaseOrders->map(fn (CasePurchaseOrder $po) => [
                    'id' => $po->getKey(),
                    'quantity_outstanding' => Display::quantity($po->quantityOutstanding()),
                ])->values()->all(),
                // Kyle, 2026-11-27: Approved Tender Excesses, the same computed column.
                'previous_orders' => $case->previousOrders->map(fn (CasePurchaseOrder $po) => [
                    'id' => $po->getKey(),
                    'quantity_outstanding' => Display::quantity($po->quantityOutstanding()),
                ])->values()->all(),
                // 2026-11-23: each saved quotation row's computed money, as saved.
                'quotations' => $case->quotations->map(fn (CaseQuotation $q) => [
                    'id' => $q->getKey(),
                    'subtotal' => Display::money($q->subtotal()),
                    'vat' => Display::money($q->vatAmount()),
                    'total_price' => Display::money($q->totalPrice()),
                ])->values()->all(),
                // Kyle, 2026-11-26: each saved item's note at the end of its row, from ExpiryRisk.
                'stock_expiries' => $case->stockExpiries->map(fn (CaseStockExpiry $b) => [
                    'id' => $b->getKey(),
                    'expiry_date' => Display::date($b->expiry_date),
                    'note' => match ($risk['expiry_warnings'][(int) $b->getKey()]['state'] ?? null) {
                        ExpiryRisk::AT_RISK => self::MAY_EXPIRE,
                        ExpiryRisk::EXPIRED => self::EXPIRED_NOTE,
                        default => null,
                    },
                ])->values()->all(),
            ],
        ];
    }

    /**
     * The warning as the page shows it: one item per batch at risk or expired,
     * earliest expiry first (ExpiryRisk's order). "Batch N" is the batch's
     * position in the saved list.
     *
     * @param  array{days_on_hand: string|null, expiry_warnings: array<int, array{state: string, days_until_expiry: int, cover_days: string}>}  $risk
     * @return array{days_on_hand: string, at_risk: list<array<string, string>>, expired: list<array<string, string>>}
     */
    private static function expiry(ProcurementCase $case, array $risk): array
    {
        $batches = $case->stockExpiries->values();
        $atRisk = [];
        $expired = [];

        foreach ($risk['expiry_warnings'] as $batchId => $warning) {
            $index = $batches->search(fn (CaseStockExpiry $b) => $b->getKey() === $batchId);
            $batch = $batches->get($index);
            $item = [
                'batch' => RequisitionMessages::rowLabel('stock_expiries', (int) $index), // "Item 1" (2026-11-26)
                // 2026-11-24: no batch quantity (the quantity on hand is one typed field).
                'expiry_date' => Display::date($batch?->expiry_date),
            ];

            if ($warning['state'] === ExpiryRisk::EXPIRED) {
                $expired[] = $item;
            } else {
                $atRisk[] = [
                    ...$item,
                    'cover_days' => Display::wholeDays($warning['cover_days']),
                    'days_to_expiry' => (string) $warning['days_until_expiry'],
                ];
            }
        }

        return [
            'days_on_hand' => $risk['days_on_hand'] ?? Display::EMPTY,
            'at_risk' => $atRisk,
            'expired' => $expired,
        ];
    }
}
