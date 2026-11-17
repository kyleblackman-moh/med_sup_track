<?php

namespace App\Http\Resources\Requests;

use App\Enums\CaseStatus;
use App\Enums\Permission;
use App\Models\ProcurementCase;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Vendor-facing summary of a request. Built field by field: nothing internal
 * (organisation, creator, file number, holder, tracks, hardcopy, raw status)
 * is ever included. Call ->resolve() so there is no "data" wrapper.
 *
 * @property ProcurementCase $resource
 */
class RequestSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return self::fields($this->resource, $request->user());
    }

    public static function fields(ProcurementCase $case, ?User $user): array
    {
        $type = $case->procurementType;
        $requisition = $case->requisition;

        return [
            'id' => $case->id,
            'reference_no' => $case->reference_no,
            'status_label' => $case->status->vendorLabel(),
            'status_group' => $case->status->vendorGroup(),
            'procurement_type' => [
                'code' => $type->code,
                'name' => $type->name,
                'category' => $type->category?->value, // null for AD_HOC
            ],
            'item_description' => $requisition?->item_description,
            'vendor_reference' => $requisition?->vendor_reference,
            'updated_at' => $case->updated_at?->copy()->utc()->toIso8601ZuluString(),
            'can' => [
                'edit' => self::canEdit($case, $user),
            ],
        ];
    }

    public static function canEdit(ProcurementCase $case, ?User $user): bool
    {
        return $user !== null
            && $case->status === CaseStatus::DRAFT
            && Gate::forUser($user)->allows('act', [$case, Permission::CaseSubmit->value]);
    }
}
