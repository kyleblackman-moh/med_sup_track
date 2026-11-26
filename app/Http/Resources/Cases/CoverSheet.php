<?php

namespace App\Http\Resources\Cases;

use App\Models\CaseRequisition;
use App\Models\Organisation;
use App\Models\ProcurementCase;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * FR-03: the hardcopy cover sheet (printable page; the browser saves it as
 * PDF). The QR code holds the reference number only, so a scanner typing into
 * the dashboard's reference lookup finds the case, and the sheet carries no
 * internal link or id. Built field by field, the same for vendor and staff.
 */
final class CoverSheet
{
    public const INSTRUCTION = 'Attach this sheet to the front of the hardcopy and deliver it to the Office of the Chief Medical Officer. Quote the reference number on any correspondence.';

    /** @return array<string, mixed> */
    public static function for(ProcurementCase $case): array
    {
        return [
            'id' => (string) $case->getKey(),
            'reference_no' => $case->reference_no,
            'organisation_name' => Organisation::whereKey($case->org_id)->value('name'),
            'procurement_type' => $case->procurementType?->name,
            'item_description' => CaseRequisition::withoutGlobalScopes()->where('case_id', $case->getKey())->value('item_description'),
            'submitted_at' => $case->submitted_at?->copy()->utc()->toIso8601ZuluString(),
            'qr_svg' => self::qr((string) $case->reference_no),
            'instruction' => self::INSTRUCTION,
        ];
    }

    /** A black-on-white QR (print contrast; not a theme colour), as an inline SVG element. */
    public static function qr(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(176, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
            new SvgImageBackEnd,
        )))->writeString($text);

        // Drop the XML declaration: the element is placed inside the page.
        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
