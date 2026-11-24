<?php

namespace App\Enums;

/**
 * Single source of truth for permission names.
 *
 * Server code checks these (e.g. $user->can(Permission::QueryForward->value)),
 * never role names.
 */
enum Permission: string
{
    // Case access
    case CaseViewAll = 'case.view_all';
    case CaseViewAssigned = 'case.view_assigned';
    case CaseSubmit = 'case.submit';
    case CaseHistoryView = 'case.history.view';

    // Assignment (administrative; deliberately outside the case.* namespace)
    case AssignmentReassign = 'assignment.reassign';

    // Documents
    case DocumentUpload = 'document.upload';
    case DocumentDownload = 'document.download';

    // Workflow actions
    case HardcopyConfirm = 'hardcopy.confirm';
    case CaseIntake = 'case.intake';                 // T2: SUBMITTED -> CMO_INTAKE
    case CaseRouteToReview = 'case.route_to_review'; // T3: CMO_INTAKE -> IN_REVIEW
    case CaseWithdraw = 'case.withdraw';             // T15 (staff): SUBMITTED|CMO_INTAKE|IN_REVIEW -> WITHDRAWN
    case CaseWithdrawOwn = 'case.withdraw_own';      // T15V (vendor, own org): DRAFT|SUBMITTED -> WITHDRAWN
    case CaseRejectFinal = 'case.reject_final';      // T16: WITH_PS|RETURNED_TO_CMO -> REJECTED_FINAL (behind workflow.final_reject_enabled)
    case TechnicalConsider = 'technical.consider';
    case FundingConfirm = 'funding.confirm';
    case FilePrepare = 'file.prepare';
    case LegalConfirm = 'legal.confirm';
    case NonObjectionGrant = 'nonobjection.grant';
    case LetterIssue = 'letter.issue';

    // Queries
    case QueryRaiseToVendor = 'query.raise_to_vendor';
    case QueryForward = 'query.forward';

    // Administration and oversight (IT-ADMIN: the IT Admin console)
    case AdminAccess = 'admin.access';              // the /admin console exists for you (404 otherwise)
    case UsersView = 'users.view';                  // Admin > Users list and detail
    case UsersCreate = 'users.create';              // create a staff account (never system_admin: CLI only)
    case UsersInviteVendor = 'users.invite_vendor'; // invite a user into a vendor organisation
    case UsersUpdateRole = 'users.update_role';
    case UsersDisable = 'users.disable';            // disable and re-enable
    case UsersMfaReset = 'users.mfa.reset';         // MFA-1: vendor users only without admin.access
    case AuditView = 'audit.view';
    case SystemHealthView = 'system.health.view';
    case JobsRetry = 'jobs.retry';
    case ConfigManage = 'config.manage';
    case DelegationManage = 'delegation.manage';

    // Sidebar pages (Kyle, 2026-11-24). Each is held by exactly the roles that
    // could open the page before, so no access changed; rows stay scoped by
    // VisibleToUserScope. The page checks it; the nav flag shows or hides.
    case CasesList = 'cases.list';        // Cases (/cases)
    case TrackingView = 'tracking.view';  // File Tracking (/tracking)
    case AnalyticsView = 'analytics.view'; // Analytics (/analytics)

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
