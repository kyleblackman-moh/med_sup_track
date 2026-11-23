import { LucideIcon } from 'lucide-react';

/**
 * Shared by HandleInertiaRequests. Use `can` (permission names) for show/hide only;
 * never check roles or user_type in React. The server authorises every request.
 */
export interface Auth {
    user: User;
    can: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** The server's nav.section under which this item is active (URL prefix). */
    section?: string;
    /** QUERIES-1: a count badge after the label (hidden at 0). */
    count?: number;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    can: { requests: boolean; cases: boolean };
    /** Sidebar sections (show/hide only). Separate from `can`, which a page prop can replace. */
    nav: {
        cmo_office: boolean;
        users: boolean;
        analytics?: boolean;
        queries?: boolean;
        /** IT-ADMIN: the IT health page (/admin). */
        it_health?: boolean;
        audit?: boolean;
        /** IT-ADMIN: the audit log URL for this user (console or /audit), or null. */
        audit_url?: string | null;
        section: string | null;
    };
    flash: { success: string | null; error: string | null; info: string | null };
    /** V2-4: the Ministry crest's URL once the file is supplied, else null (the sidebar shows the pill tile). */
    brand: { logo: string | null };
    /** NOTIF-2: the bell's badge, counted at render time on the server. */
    unreadNotificationsCount: number;
    /** QUERIES-1: queries waiting on this user (the sidebar Queries badge). */
    openQueriesCount?: number;
    [key: string]: unknown;
}

// Exactly what the server shares; nothing else is sent to the browser.
export interface User {
    id: number;
    name: string;
    email: string;
    user_type: string;
    /** V2-4: the sidebar user card's second line, decided by the server (organisation for vendors, role names for staff). */
    subtitle: string | null;
    avatar?: string;
    email_verified_at?: string | null;
}
