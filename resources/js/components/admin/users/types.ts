import { type StatusTone } from '@/types/cases';
import { type OptionList } from '@/types/requests';

/** IT-ADMIN: one row of Admin > Users (UserView::row). Every label and tone is server-made. */
export interface AdminUserRow {
    id: number;
    name: string;
    email: string;
    type: string;
    organisation: string;
    role: string;
    status_label: string;
    status_tone: StatusTone;
    mfa_label: string;
    mfa_tone: StatusTone;
    last_sign_in: string;
}

/** The detail page (UserView::detail). */
export interface AdminUserDetail extends AdminUserRow {
    role_value: string | null;
    is_active: boolean;
    is_pending: boolean;
    is_locked: boolean;
    locked_until: string | null;
    mfa_enrolled: boolean;
    created_on: string;
    disabled_on: string | null;
}

export interface AdminUserPage {
    data: AdminUserRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

/** The list's query string (UserController::index). An empty string is "all". */
export interface AdminUserFilters {
    q: string;
    type: string;
    org: string;
    role: string;
    status: string;
    mfa: string;
}

export const NO_USER_FILTERS: AdminUserFilters = { q: '', type: '', org: '', role: '', status: '', mfa: '' };

/** A role option; `vendor` = the role belongs to a vendor organisation (the server validates the pairing). */
export type RoleOption = { value: string; label: string; vendor: boolean };

export interface AdminUserOptions {
    types: OptionList;
    organisations: OptionList;
    roles: RoleOption[];
    statuses: OptionList;
    mfa: OptionList;
}

/** Page-level hints from the server (UserController::show); every endpoint authorises itself. */
export interface UserAbilities {
    change_role: boolean;
    disable: boolean;
    enable: boolean;
    resend_activation: boolean;
    mfa_reset: boolean;
}
