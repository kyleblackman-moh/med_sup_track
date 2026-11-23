/** IT-ADMIN: the audit log viewer's props (AuditLogController@index, AuditLogView). */

export type AuditRouteBase = 'admin.audit' | 'audit';

export interface AuditRow {
    id: number;
    /** Already in America/Port_of_Spain, formatted by the server. */
    occurred_at: string;
    actor: string;
    action: string;
    category: string;
    outcome_label: string;
    outcome_tone: 'error' | 'success';
    subject: string | null;
    case_reference: string | null;
    ip_address: string | null;
    request_id: string | null;
}

export type AuditMetadataValue = string | number | boolean | null | AuditMetadataValue[] | { [key: string]: AuditMetadataValue };

export interface AuditDetail extends AuditRow {
    actor_type: string | null;
    user_agent: string | null;
    metadata: { [key: string]: AuditMetadataValue } | AuditMetadataValue[];
}

export interface AuditFilters {
    actor: string;
    category: string;
    action: string;
    case: string;
    from: string;
    to: string;
    outcome: string;
}

export interface AuditOption {
    value: string;
    label: string;
}

export interface AuditActionOption extends AuditOption {
    category: string;
}

export interface AuditOptions {
    categories: AuditOption[];
    actions: AuditActionOption[];
    outcomes: AuditOption[];
}

/** AuditRedactor::MASK */
export const REDACTED = '[redacted]';

export const EMPTY_FILTERS: AuditFilters = { actor: '', category: '', action: '', case: '', from: '', to: '', outcome: '' };
