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

    /** The single Ad Hoc form of slice F1. Inactive since V2-1; its cases stay readable. */
    public const AD_HOC = 'AD_HOC';

    public const AD_HOC_EMERGENCY = 'AD_HOC_EMERGENCY';

    public const AD_HOC_TENDER_UNABLE = 'AD_HOC_TENDER_UNABLE';

    public const AD_HOC_NOT_AWARDED = 'AD_HOC_NOT_AWARDED';

    public const TENDER_EXCESS = 'TENDER_EXCESS';

    public const TENDER_VARIATION = 'TENDER_VARIATION';

    /**
     * Slice V2-1b: a new draft's type until one of the five is chosen in the
     * form. Inactive and never offered as a choice; it cannot be submitted.
     */
    public const NOT_SELECTED = 'NOT_SELECTED';

    /**
     * Slice V2-1: the five request types, in display order. Each uses the one
     * request form; RequestSections decides what each shows and requires. The
     * only codes a new draft may have (CreateDraftCase) or change to.
     */
    public const REQUEST_TYPES = [
        self::AD_HOC_EMERGENCY,
        self::AD_HOC_TENDER_UNABLE,
        self::AD_HOC_NOT_AWARDED,
        self::TENDER_EXCESS,
        self::TENDER_VARIATION,
    ];

    /** The old Pharm / Non-Pharm ad hoc types (inactive; old form, old rules). */
    private const OLD_AD_HOC = ['AD_HOC_PHARM', 'AD_HOC_NON_PHARM'];

    private const ALL_SECTIONS = ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'];

    /** code => [allowed sections, required sections] */
    private const PROFILES = [
        // The request form: every section allowed; what is required is decided
        // by RequestSections (AD_HOC by its own slice F1 rules).
        self::AD_HOC => [self::ALL_SECTIONS, []],
        self::AD_HOC_EMERGENCY => [self::ALL_SECTIONS, []],
        self::AD_HOC_TENDER_UNABLE => [self::ALL_SECTIONS, []],
        self::AD_HOC_NOT_AWARDED => [self::ALL_SECTIONS, []],
        self::TENDER_EXCESS => [self::ALL_SECTIONS, []],
        self::TENDER_VARIATION => [self::ALL_SECTIONS, []],
        self::NOT_SELECTED => [self::ALL_SECTIONS, []],
        // Old types (inactive): unchanged.
        'AD_HOC_PHARM' => [
            ['quotations', 'tender_awards', 'purchase_orders', 'stock_expiries'],
            ['quotations'],
        ],
        'AD_HOC_NON_PHARM' => [
            ['quotations'],
            ['quotations'],
        ],
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

    /** The one request form: the five request types (V2-1), NOT_SELECTED (V2-1b) and the old AD_HOC (F1). */
    public function isAdHocForm(): bool
    {
        return $this->isRequestType() || $this->code === self::AD_HOC || $this->code === self::NOT_SELECTED;
    }

    /** One of the five request types of slice V2-1. */
    public function isRequestType(): bool
    {
        return in_array($this->code, self::REQUEST_TYPES, true);
    }

    /** The old Pharm / Non-Pharm ad hoc types (AD_HOC_PHARM, AD_HOC_NON_PHARM). */
    public function isAdHoc(): bool
    {
        return in_array($this->code, self::OLD_AD_HOC, true);
    }
}
