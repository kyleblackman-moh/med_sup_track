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

    /** The single Ad Hoc form (slice F1). */
    public const AD_HOC = 'AD_HOC';

    /** code => [allowed sections, required sections] */
    private const PROFILES = [
        // Every section allowed; what is required is decided by AdHocReadiness.
        self::AD_HOC => [
            ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'],
            [],
        ],
        // Old types (inactive except TENDER_EXCESS): unchanged.
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

    /** The single Ad Hoc form (AD_HOC). */
    public function isAdHocForm(): bool
    {
        return $this->code === self::AD_HOC;
    }

    /** The old Pharm / Non-Pharm ad hoc types (AD_HOC_*), not AD_HOC itself. */
    public function isAdHoc(): bool
    {
        return str_starts_with($this->code, 'AD_HOC_');
    }
}
