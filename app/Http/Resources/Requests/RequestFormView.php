<?php

namespace App\Http\Resources\Requests;

use App\Models\CasePurchaseOrder;
use App\Models\CaseStockExpiry;
use App\Models\CaseTenderAward;
use App\Models\ProcurementCase;
use App\Submissions\ExpiryRisk;
use App\Submissions\ReadinessReport;
use App\Submissions\RequestSections;
use App\Support\Decimal;
use App\Support\Display;

/**
 * Slice V2-2: the "form" prop of the request form. Additive; nothing here
 * decides anything new. It reshapes what the server already knows:
 *
 * - sections: every section in form order (RequestSections::ORDER) with its
 *   label, its number among the VISIBLE sections (null when hidden), whether
 *   it is visible, its state, the note when it waits for the type, and the
 *   field-level required flags (RequestSections::requiredFields). The state
 *   comes from AdHocSections::requestSections (the readiness report):
 *   blocked (waits for the type), incomplete (has issues), optional (nothing
 *   required for this type), else complete.
 * - display: the computed values as display strings (Display), per saved row
 *   by id, so the page does no maths and no formatting.
 * - expiry: the expiry warning from ExpiryRisk as structured items.
 *
 * Expects the case with procurementType, requisition and the child relations
 * loaded, in saved order.
 */
final class RequestFormView
{
    public const COMPLETE = 'complete';

    public const INCOMPLETE = 'incomplete';

    public const OPTIONAL = 'optional';

    public const BLOCKED = 'blocked';

    /** @return array{sections: list<array<string, mixed>>, display: array<string, mixed>, expiry: array<string, mixed>} */
    public static function for(ProcurementCase $case, ReadinessReport $report): array
    {
        $risk = ExpiryRisk::for($case);

        return [
            'sections' => self::sections($case, $report),
            'display' => self::display($case, $risk['days_on_hand']),
            'expiry' => self::expiry($case, $risk),
        ];
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
                'state' => match (true) {
                    $section === null => self::OPTIONAL, // hidden: nothing in it is required
                    $section['note'] !== null => self::BLOCKED,
                    $section['issues'] !== [] => self::INCOMPLETE,
                    $section['optional'] => self::OPTIONAL,
                    default => self::COMPLETE,
                },
                'note' => $section['note'] ?? null,
                'required' => $required[$key],
            ];
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    private static function display(ProcurementCase $case, ?int $daysOnHand): array
    {
        $percentages = $case->tenderAwardPercentages();
        $awardTotals = $case->tenderAwards->map(fn (CaseTenderAward $a) => $a->total())->filter(fn ($t) => $t !== null);

        return [
            'stock_total' => Display::quantity($case->batchStockOnHand()),
            'days_on_hand' => $daysOnHand === null ? Display::EMPTY : (string) $daysOnHand,
            'awards_total' => $awardTotals->isEmpty()
                ? Display::EMPTY
                : Display::money($awardTotals->reduce(fn (string $sum, string $t) => Decimal::add($sum, $t), '0')),
            'total_cost' => Display::money($case->totalCost()),
            'rows' => [
                'tender_awards' => $case->tenderAwards->map(fn (CaseTenderAward $a) => [
                    'id' => $a->getKey(),
                    'percentage' => Display::percent($percentages[$a->getKey()] ?? null),
                    'line_cost' => Display::money($a->total()),
                ])->values()->all(),
                'purchase_orders' => $case->purchaseOrders->map(fn (CasePurchaseOrder $po) => [
                    'id' => $po->getKey(),
                    'quantity_outstanding' => Display::quantity($po->quantityOutstanding()),
                ])->values()->all(),
                'stock_expiries' => $case->stockExpiries->map(fn (CaseStockExpiry $b) => [
                    'id' => $b->getKey(),
                    'expiry_date' => Display::date($b->expiry_date),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * The warning as the page shows it: one item per batch at risk or expired,
     * earliest expiry first (ExpiryRisk's order). "Batch N" is the batch's
     * position in the saved list.
     *
     * @param  array{days_on_hand: int|null, expiry_warnings: array<int, array{state: string, days_until_expiry: int, cover_days: string}>}  $risk
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
                'batch' => 'Batch '.($index + 1),
                'quantity' => Display::quantity($batch?->quantity),
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
            'days_on_hand' => $risk['days_on_hand'] === null ? Display::EMPTY : (string) $risk['days_on_hand'],
            'at_risk' => $atRisk,
            'expired' => $expired,
        ];
    }
}
