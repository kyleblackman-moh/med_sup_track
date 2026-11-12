<?php

namespace App\Enums;

/**
 * Main case statuses (spec 6.2). Case names equal their values.
 *
 * Status changes go only through the transition service (slice 7).
 */
enum CaseStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case CMO_INTAKE = 'CMO_INTAKE';
    case IN_REVIEW = 'IN_REVIEW';
    case READY_FOR_FILE = 'READY_FOR_FILE';
    case FILE_PREPARED = 'FILE_PREPARED';
    case WITH_LEGAL = 'WITH_LEGAL';
    case WITH_PS = 'WITH_PS';
    case NON_OBJECTION_GRANTED = 'NON_OBJECTION_GRANTED';
    case LETTER_ISSUED = 'LETTER_ISSUED';
    case CLOSED = 'CLOSED';
    case RETURNED_TO_CMO = 'RETURNED_TO_CMO';
    case WITHDRAWN = 'WITHDRAWN';
    case REJECTED_FINAL = 'REJECTED_FINAL';

    /**
     * What a vendor user sees. Internal stages collapse into "Under review", so
     * the raw status never reaches a vendor.
     * Draft, confirm with the CMO's office.
     */
    public function vendorLabel(): string
    {
        return match ($this->vendorGroup()) {
            'DRAFT' => 'Draft',
            'SUBMITTED' => 'Submitted',
            'UNDER_REVIEW' => 'Under review',
            'LETTER_ISSUED' => 'Letter issued',
            'CLOSED' => 'Closed',
            'WITHDRAWN' => 'Withdrawn',
            'NOT_APPROVED' => 'Not approved',
        };
    }

    /** Vendor-facing group code. Draft, confirm with the CMO's office. */
    public function vendorGroup(): string
    {
        return match ($this) {
            self::DRAFT => 'DRAFT',
            self::SUBMITTED => 'SUBMITTED',
            self::CMO_INTAKE, self::IN_REVIEW, self::READY_FOR_FILE, self::FILE_PREPARED,
            self::WITH_LEGAL, self::WITH_PS, self::RETURNED_TO_CMO, self::NON_OBJECTION_GRANTED => 'UNDER_REVIEW',
            self::LETTER_ISSUED => 'LETTER_ISSUED',
            self::CLOSED => 'CLOSED',
            self::WITHDRAWN => 'WITHDRAWN',
            self::REJECTED_FINAL => 'NOT_APPROVED',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::CLOSED, self::WITHDRAWN, self::REJECTED_FINAL => true,
            default => false,
        };
    }
}
