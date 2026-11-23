<?php

namespace App\Http\Resources\Cases;

use App\Enums\Role;
use App\Models\ProcurementCase;
use App\Submissions\ExpiryRisk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One row of the staff case list (slice 9a), built field by field. Expects
 * organisation, procurementType and requisition to be eager loaded.
 */
final class StaffCaseSummary
{
    public const TIMEZONE = 'America/Port_of_Spain';

    public static function fields(ProcurementCase $case, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $submitted = $case->submitted_at?->copy()->setTimezone(self::TIMEZONE);

        return [
            'id' => $case->id,
            'reference_no' => $case->reference_no,
            'organisation' => $case->organisation?->name,
            'procurement_type' => $case->procurementType?->name,
            'item_description' => $case->requisition?->item_description !== null
                ? Str::limit($case->requisition->item_description, 80)
                : null,
            'status' => $case->status->value,
            'status_label' => $case->status->label(),
            'status_tone' => $case->status->tone(),
            'holder_label' => self::holderLabel($case->current_holder_role),
            'submitted_at' => $case->submitted_at?->copy()->utc()->toIso8601ZuluString(),
            // Calendar days in Port of Spain. Display only: no thresholds, no SLA meaning.
            'days_since_submitted' => $submitted ? (int) $submitted->startOfDay()->diffInDays($today) : null,
            // Kyle, 2026-11-23: stock may expire before use (ExpiryRisk::flag); null when nothing to flag.
            // Only when the list eager loaded the batches, so a row never costs a query per case.
            'expiry_flag' => $case->relationLoaded('stockExpiries') ? ExpiryRisk::flag($case) : null,
        ];
    }

    public static function holderLabel(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }

        return Role::tryFrom($role)?->label() ?? Str::headline($role);
    }
}
