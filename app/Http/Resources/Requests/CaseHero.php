<?php

namespace App\Http\Resources\Requests;

use App\Enums\CaseStatus;
use App\Models\ProcurementCase;
use App\Models\User;
use App\Submissions\ProcurementTypeProfile;
use App\Support\Display;
use Carbon\CarbonInterface;

/**
 * V2-4 Stage B: the case hero on the vendor form and the vendor request page
 * (design.md section 7). Display only; nothing here decides anything new.
 *
 * - reference: the reference number, or null on a draft (numbers are
 *   allocated at submit by SubmitCase; the hero then shows the heading,
 *   "New Request" when there is no item description; V2-4b).
 * - title: the item description, or null.
 * - heading: the page's h1 (V2-4b): the item description, else UNTITLED.
 * - type: the type's label and the server-chosen lucide icon name, or null
 *   while the vendor has not chosen a type; the hero then shows
 *   type_unset_label (TYPE_UNSET), so the page never names a type code.
 * - status_label / status_tone: the vendor label and tone (CaseStatus::
 *   vendorLabel and vendorTone) for a vendor viewer, else the staff ones. Flat
 *   keys, as everywhere else: "status" is a key that never reaches a vendor.
 * - date: "Last Saved" (updated_at) on a draft, "Submitted On" (submitted_at)
 *   once submitted, formatted by Display in America/Port_of_Spain.
 */
final class CaseHero
{
    public const TYPE_UNSET = 'Not chosen yet';

    /** V2-4b: the h1 when the request has no item description yet. */
    public const UNTITLED = 'New Request';

    public const DRAFT_DATE_LABEL = 'Last Saved';

    public const SUBMITTED_DATE_LABEL = 'Submitted On';

    /** The icon for a type outside RequestDetailResource::TYPE_ICONS (the old types). */
    public const DEFAULT_TYPE_ICON = 'clipboard-list';

    private const DISPLAY_TIMEZONE = 'America/Port_of_Spain';

    /**
     * Expects procurementType and requisition loaded (or loads them).
     *
     * @return array{reference: string|null, title: string|null, heading: string, type: array{label: string, icon: string}|null, type_unset_label: string, status_label: string, status_tone: string, date: array{label: string, value: string}}
     */
    public static function for(ProcurementCase $case, ?User $viewer): array
    {
        $type = $case->procurementType;
        $code = (string) $type?->code;
        $vendorView = $viewer === null || $viewer->isVendor();
        $isDraft = $case->status === CaseStatus::DRAFT;
        $title = $case->requisition?->item_description;
        $din = trim((string) $case->requisition?->din);

        return [
            'reference' => $case->reference_no,
            'title' => is_string($title) && trim($title) !== '' ? $title : null,
            // Kyle, 2026-11-24: the drug and its DIN name the case ("Degarelix · DIN 02345678").
            'heading' => is_string($title) && trim($title) !== '' ? $title.($din !== '' ? ' · DIN '.$din : '') : self::UNTITLED,
            'type' => $type === null || $code === ProcurementTypeProfile::NOT_SELECTED ? null : [
                'label' => $type->name,
                'icon' => RequestDetailResource::TYPE_ICONS[$code] ?? self::DEFAULT_TYPE_ICON,
            ],
            'type_unset_label' => self::TYPE_UNSET,
            'status_label' => $vendorView ? $case->status->vendorLabel() : $case->status->label(),
            'status_tone' => $vendorView ? $case->status->vendorTone() : $case->status->tone(),
            'date' => $isDraft
                ? ['label' => self::DRAFT_DATE_LABEL, 'value' => self::day($case->updated_at)]
                : ['label' => self::SUBMITTED_DATE_LABEL, 'value' => self::day($case->submitted_at)],
        ];
    }

    /** The calendar day in Port of Spain ("18 Nov 2026"), or Display::EMPTY. */
    private static function day(?CarbonInterface $at): string
    {
        return Display::date($at?->copy()->setTimezone(self::DISPLAY_TIMEZONE));
    }
}
