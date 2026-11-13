<?php

namespace App\Http\Resources\Cases;

use App\Enums\DocumentVisibility;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\ScanStatus;
use App\Http\Resources\Requests\RequestDetailResource;
use App\Models\CaseAssignment;
use App\Models\CaseTransition;
use App\Models\Document;
use App\Models\ProcurementCase;
use App\Models\ProcurementTypeDocument;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Props for the read-only staff case view (slice 9a), built field by field.
 *
 * - Requisition content comes from RequestDetailResource::requisitionContent,
 *   the same server-side figures the vendor sees.
 * - Quotation technical_* fields are left out for everyone (decision B).
 * - Documents: current and CLEAN only; never storage keys, hashes, mime or
 *   scan internals.
 */
final class StaffCaseDetail
{
    public static function for(ProcurementCase $case, User $user): array
    {
        $case->loadMissing([
            'organisation:id,name', 'procurementType',
            'requisition', 'quotations', 'tenderAwards', 'purchaseOrders', 'stockExpiries',
        ]);

        return [
            'case' => [
                ...StaffCaseSummary::fields($case),
                'item_description' => $case->requisition?->item_description,
                'category' => $case->procurementType?->category->value,
                ...RequestDetailResource::requisitionContent($case),
            ],
            'documents' => self::documents($case, $user),
            'timeline' => self::timeline($case),
            'assignments' => self::assignments($case),
            // A page prop named "can" replaces the shared one, so the shared
            // navigation flags are repeated here (as in RequestController).
            'can' => [
                'requests' => $user->can(Permission::CaseSubmit->value),
                'cases' => Gate::forUser($user)->allows('viewStaffCases', ProcurementCase::class),
                'download_documents' => $user->can(Permission::DocumentDownload->value),
            ],
        ];
    }

    private static function documents(ProcurementCase $case, User $user): array
    {
        $gate = Gate::forUser($user);
        $labels = ProcurementTypeDocument::where('procurement_type_id', $case->procurement_type_id)
            ->get(['purpose', 'label'])
            ->mapWithKeys(fn (ProcurementTypeDocument $d) => [$d->purpose->value => $d->label]);

        return Document::query()->current()
            ->where('case_id', $case->getKey())
            ->where('scan_status', ScanStatus::CLEAN->value) // staff never see unscanned or rejected files
            ->with('uploader:id,name')
            ->orderBy('uploaded_at')->orderBy('id')
            ->get()
            ->filter(fn (Document $d) => $gate->allows('view', $d))
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'display_name' => $d->original_name,
                'purpose_label' => $labels[$d->purpose->value] ?? Str::headline($d->purpose->value),
                'version' => $d->version,
                'uploaded_at' => $d->uploaded_at?->copy()->utc()->toIso8601ZuluString(),
                'uploaded_by' => $d->uploader?->name,
                'visibility' => $d->visibility === DocumentVisibility::INTERNAL ? 'Internal' : 'External',
                'can_download' => $gate->allows('download', $d),
            ])->values()->all();
    }

    private static function timeline(ProcurementCase $case): array
    {
        return $case->transitions()->with(['actor:id,name', 'actingFor:id,name'])->get()
            ->map(fn (CaseTransition $t) => [
                'id' => $t->id,
                'from_label' => $t->from_status?->label(),
                'to_label' => $t->to_status->label(),
                'actor' => $t->actor?->name,
                'acting_for' => $t->actingFor?->name,
                'reason' => $t->reason,
                'at' => $t->created_at?->copy()->utc()->toIso8601ZuluString(),
            ])->values()->all();
    }

    private static function assignments(ProcurementCase $case): array
    {
        return $case->assignments()->with('user:id,name')
            ->orderBy('assigned_at')->orderBy('id')->get()
            ->map(fn (CaseAssignment $a) => [
                'id' => $a->id,
                'role_label' => Role::tryFrom($a->role)?->label() ?? Str::headline($a->role),
                'user' => $a->user?->name, // null: any user in the role (pool)
                'state' => match (true) {
                    $a->cancelled_at !== null => 'Cancelled',
                    $a->completed_at !== null => 'Completed',
                    default => 'Active',
                },
                'assigned_at' => $a->assigned_at?->copy()->utc()->toIso8601ZuluString(),
                'completed_at' => $a->completed_at?->copy()->utc()->toIso8601ZuluString(),
            ])->values()->all();
    }
}
