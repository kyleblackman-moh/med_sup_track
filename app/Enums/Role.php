<?php

namespace App\Enums;

use App\Enums\Permission as P;

/**
 * Single source of truth for role names and the role → permission matrix.
 *
 * Roles are only referenced when assigning them to users and when seeding.
 * Authorisation code must check permissions, never these role names.
 */
enum Role: string
{
    case VendorApplicant = 'vendor_applicant';
    case CmoRegistry = 'cmo_registry';
    case Cmo = 'cmo';
    case Technocrat = 'technocrat';
    case CabOfficer = 'cab_officer';
    case LegalOfficer = 'legal_officer';
    case PsOffice = 'ps_office';
    case SystemAdmin = 'system_admin';
    case Auditor = 'auditor';

    /**
     * The permissions granted to this role.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::VendorApplicant => [
                P::CaseSubmit, P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload,
            ],
            self::CmoRegistry => [
                P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload, P::HardcopyConfirm,
                P::CaseIntake,
            ],
            self::Cmo => [
                P::CaseViewAll, P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload,
                P::HardcopyConfirm, P::CaseIntake, P::CaseRouteToReview, P::QueryForward,
                P::FilePrepare, P::LetterIssue, P::CaseHistoryView, P::VendorUserInvite,
                P::AssignmentReassign, P::DelegationManage,
            ],
            self::Technocrat => [
                P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload,
                P::TechnicalConsider, P::QueryRaiseToVendor,
            ],
            self::CabOfficer => [
                P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload, P::FundingConfirm,
            ],
            self::LegalOfficer => [
                P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload, P::LegalConfirm,
            ],
            self::PsOffice => [
                P::CaseViewAll, P::DocumentUpload, P::DocumentDownload,
                P::NonObjectionGrant, P::CaseHistoryView,
            ],
            // No case-content permissions: administers users and configuration only.
            self::SystemAdmin => [
                P::AuditView, P::UserCreate, P::VendorUserInvite, P::UserResetCredentials,
                P::ConfigManage, P::AssignmentReassign, P::DelegationManage,
            ],
            self::Auditor => [
                P::AuditView,
            ],
        };
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_map(fn (Permission $p) => $p->value, $this->permissions());
    }
}
