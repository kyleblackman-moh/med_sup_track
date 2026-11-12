<?php

namespace App\Submissions;

use App\Models\ProcurementType;

/**
 * Which requisition sections each procurement type uses, keyed by type CODE.
 *
 * Draft profiles, confirm with the CMO's office (Q22, Q24).
 */
final readonly class ProcurementTypeProfile
{
    public const SECTIONS = ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'];

    /** code => [allowed sections, required sections] */
    private const PROFILES = [
        'AD_HOC_PHARM' => [
            ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'],
            ['quotations'],
        ],
        'AD_HOC_NON_PHARM' => [
            ['quotations'],
            ['quotations'],
        ],
        'TENDER_EXCESS' => [
            ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'],
            ['quotations', 'tender_awards', 'purchase_orders'],
        ],
        // Inactive: drafts cannot be created (CreateDraftCase checks is_active).
        'TENDER_VARIATION' => [[], []],
    ];

    /**
     * @param  list<string>  $allowedSections
     * @param  list<string>  $requiredSections
     */
    private function __construct(
        public string $code,
        public array $allowedSections,
        public array $requiredSections,
    ) {}

    /** Unknown codes get no sections at all (fail closed). */
    public static function for(ProcurementType|string $type): self
    {
        $code = $type instanceof ProcurementType ? $type->code : $type;
        [$allowed, $required] = self::PROFILES[$code] ?? [[], []];

        return new self($code, $allowed, $required);
    }

    public function allows(string $section): bool
    {
        return in_array($section, $this->allowedSections, true);
    }

    public function requires(string $section): bool
    {
        return in_array($section, $this->requiredSections, true);
    }

    public function isAdHoc(): bool
    {
        return str_starts_with($this->code, 'AD_HOC_');
    }
}
