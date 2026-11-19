import { type StatusTone } from '@/types/cases';
import { type OptionList } from '@/types/requests';

/** ADMIN-1: one row of Admin > Users (UserView::row). Every label is server-made. */
export interface AdminUserRow {
    id: number;
    name: string;
    email: string;
    organisation: string;
    role: string;
    status_label: string;
    status_tone: StatusTone;
    last_sign_in: string;
}

/** The detail page (UserView::detail). */
export interface AdminUserDetail extends AdminUserRow {
    role_value: string | null;
    is_active: boolean;
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

export interface AdminUserFilters {
    q: string;
    org: string;
    role: string;
    status: string;
}

/** A role option; `vendor` = the role belongs to a vendor organisation (the server validates the pairing). */
export type RoleOption = { value: string; label: string; vendor: boolean };

export interface AdminUserOptions {
    organisations: OptionList;
    roles: RoleOption[];
    statuses: OptionList;
}
