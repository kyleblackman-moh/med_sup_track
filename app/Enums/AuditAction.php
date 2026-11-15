<?php

namespace App\Enums;

/**
 * Names of audited events. Later slices add more cases.
 */
enum AuditAction: string
{
    case LoginSuccess = 'auth.login.success';
    case LoginFailed = 'auth.login.failed';
    case AccountLocked = 'auth.account.locked';
    case RateLimited = 'auth.rate_limited';
    case Logout = 'auth.logout';
    case PasswordReset = 'auth.password.reset';

    // Case workflow
    case CaseTransitioned = 'case.transitioned';
    case CaseCreated = 'case.created';
    case CaseDraftSaved = 'case.draft_saved';
    case CaseReferenceAssigned = 'case.reference_assigned';
    case CaseAssignedToPool = 'case.assigned_to_pool';
    case CaseAssignmentCompleted = 'case.assignment_completed';
    case CaseAssignmentCreated = 'case.assignment_created';
    case CaseHardcopyReceived = 'case.hardcopy_received';
    case CaseHardcopyReversed = 'case.hardcopy_reversed';
    case CaseForwardedForReview = 'case.forwarded_for_review';
    case CaseTechnicalReviewRecorded = 'case.technical_review_recorded';
    case CaseFundingRecorded = 'case.funding_recorded';
    case CaseFilePrepared = 'case.file_prepared';
    case CaseSentToLegal = 'case.sent_to_legal';
    case CaseLegalDecision = 'case.legal_decision';

    // Documents
    case DocumentValidationRejected = 'document.validation_rejected';
    case DocumentUploadReceived = 'document.upload_received';
    case DocumentSuperseded = 'document.superseded';
    case DocumentScanClean = 'document.scan_clean';
    case DocumentReleased = 'document.released';
    case DocumentScanInfected = 'document.scan_infected';
    case DocumentScanError = 'document.scan_error';
    case DocumentRescanQueued = 'document.rescan_queued';
    case DocumentWithdrawn = 'document.withdrawn';
    case DocumentDownloaded = 'document.downloaded';
}
