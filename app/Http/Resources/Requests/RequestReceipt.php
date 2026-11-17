<?php

namespace App\Http\Resources\Requests;

use App\Enums\CaseStatus;
use App\Enums\ScanStatus;
use App\Models\CaseRequisition;
use App\Models\CaseTransition;
use App\Models\Document;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;

/**
 * The printable submission receipt (vendor-facing). Built field by field: no
 * ids of organisations or users, no hashes, storage keys, scan states or raw
 * internal status.
 */
final class RequestReceipt
{
    public const HARDCOPY_NOTICE = "Hardcopy must be delivered to the CMO's Office. Quote the reference number above.";

    public static function for(ProcurementCase $case): array
    {
        $submission = CaseTransition::query()
            ->where('case_id', $case->getKey())
            ->where('to_status', CaseStatus::SUBMITTED->value)
            ->orderBy('id')
            ->with('actor')
            ->first();

        // Slice F1: every category that has files, not only the required ones, in
        // the type's order; files outside the type's categories come last.
        $labels = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->orderBy('sort_order')->get()
            ->mapWithKeys(fn (ProcurementTypeDocument $d) => [$d->purpose->value => $d->label]);
        $order = $labels->keys();

        $documents = Document::withoutGlobalScopes()->current()
            ->where('case_id', $case->getKey())
            ->where('scan_status', ScanStatus::CLEAN->value)
            ->orderBy('uploaded_at')->orderBy('id')->get()
            ->sortBy(fn (Document $d) => $order->search($d->purpose->value) === false ? $order->count() : $order->search($d->purpose->value))
            ->map(fn (Document $d) => [
                'label' => $labels[$d->purpose->value] ?? 'Earlier uploads',
                'display_name' => $d->original_name,
                'version' => $d->version,
                'uploaded_at' => $d->uploaded_at?->copy()->utc()->toIso8601ZuluString(),
            ])->values()->all();

        return [
            'id' => $case->getKey(),
            'reference_no' => $case->reference_no,
            'organisation_name' => Organisation::whereKey($case->org_id)->value('name'),
            'procurement_type' => $case->procurementType?->name,
            'item_description' => CaseRequisition::withoutGlobalScopes()->where('case_id', $case->getKey())->value('item_description'),
            'submitted_at' => $case->submitted_at?->copy()->utc()->toIso8601ZuluString(),
            'submitted_by' => $submission?->actor?->name,
            'documents' => $documents,
            'hardcopy_notice' => self::HARDCOPY_NOTICE,
        ];
    }
}
