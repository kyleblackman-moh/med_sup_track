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

    /** Display label (for example the holder of a case). Display only, never for checks. */
    public function label(): string
    {
        return match ($this) {
            self::VendorApplicant => 'Vendor',
            self::CmoRegistry => 'CMO Registry',
            self::Cmo => 'CMO',
            self::Technocrat => 'Technocrat',
            self::CabOfficer => 'Cost and Budgeting',
            self::LegalOfficer => 'Legal Unit',
            self::PsOffice => 'PS Office',
            self::SystemAdmin => 'System Admin',
            self::Auditor => 'Auditor',
        };
    }

    /**
     * The permissions granted to this role.
     *
     * @return list<P>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::VendorApplicant => [
                P::CaseSubmit, P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload,
                P::CaseWithdrawOwn,
            ],
            self::CmoRegistry => [
                P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload, P::HardcopyConfirm,
                P::CaseIntake,
            ],
            self::Cmo => [
                P::CaseViewAll, P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload,
                P::HardcopyConfirm, P::CaseIntake, P::CaseRouteToReview, P::QueryForward,
                P::FilePrepare, P::LetterIssue, P::CaseHistoryView, P::VendorUserInvite,
                P::AssignmentReassign, P::DelegationManage, P::CaseWithdraw, P::CaseRejectFinal,
                P::UsersMfaReset, // MFA-1: vendor users only (MfaReset)
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
                P::AuditView, P::UserCreate, P::UsersManage, P::VendorUserInvite, P::UserResetCredentials,
                P::ConfigManage, P::AssignmentReassign, P::DelegationManage, P::UsersMfaReset,
            ],
            self::Auditor => [
                P::AuditView,
            ],
        };
    }

    /**
     * ADMIN-1: the kind of account this role is assigned to. A vendor role
     * belongs to a VENDOR organisation; every other role to the MOH. Used only
     * when assigning a role (Admin > Users, app:user-create), never for access.
     */
    public function userType(): UserType
    {
        return $this === self::VendorApplicant ? UserType::Vendor : UserType::Staff;
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        return array_map(fn (P $p) => $p->value, $this->permissions());
    }
}
