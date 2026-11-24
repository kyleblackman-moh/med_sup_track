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
                P::CasesList, P::TrackingView, // 2026-11-24: sidebar pages
            ],
            self::Cmo => [
                P::CaseViewAll, P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload,
                P::HardcopyConfirm, P::CaseIntake, P::CaseRouteToReview, P::QueryForward,
                P::FilePrepare, P::LetterIssue, P::CaseHistoryView, P::UsersInviteVendor,
                P::AssignmentReassign, P::DelegationManage, P::CaseWithdraw, P::CaseRejectFinal,
                P::UsersMfaReset, // MFA-1: vendor users only (MfaReset)
                P::AuditView, // IT-ADMIN: the audit log is for the CMO, Legal and ICT (Kyle, 2026-11-22)
                P::CasesList, P::TrackingView, P::AnalyticsView, // 2026-11-24: sidebar pages
            ],
            self::Technocrat => [
                P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload,
                P::TechnicalConsider, P::QueryRaiseToVendor,
                P::CasesList, P::TrackingView, // 2026-11-24: sidebar pages (their assigned cases only)
            ],
            self::CabOfficer => [
                P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload, P::FundingConfirm,
                P::CasesList, P::TrackingView, // 2026-11-24: sidebar pages (their assigned cases only)
            ],
            self::LegalOfficer => [
                P::CaseViewAssigned, P::DocumentUpload, P::DocumentDownload, P::LegalConfirm,
                P::AuditView, // IT-ADMIN (Kyle, 2026-11-22)
                P::CasesList, P::TrackingView, // 2026-11-24: sidebar pages (their assigned cases only)
            ],
            self::PsOffice => [
                P::CaseViewAll, P::DocumentUpload, P::DocumentDownload,
                P::NonObjectionGrant, P::CaseHistoryView,
                P::CasesList, P::TrackingView, P::AnalyticsView, // 2026-11-24: sidebar pages
            ],
            // No case work at all (no case.*, document.* or query.*, and since
            // 2026-11-23 no assignment.reassign or delegation.manage either):
            // users, configuration and the IT health of the system only. The
            // role is granted from the command line only (app:user-grant-admin).
            self::SystemAdmin => [
                P::AdminAccess, P::UsersView, P::UsersCreate, P::UsersInviteVendor, P::UsersUpdateRole,
                P::UsersDisable, P::UsersMfaReset, P::AuditView, P::SystemHealthView, P::JobsRetry,
                P::ConfigManage,
            ],
            // IT-ADMIN: no audit.view (Kyle, 2026-11-22). Read-only case access (spec 4.1) is not built.
            self::Auditor => [],
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
