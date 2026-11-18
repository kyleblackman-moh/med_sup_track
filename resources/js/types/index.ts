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
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    can: { requests: boolean; cases: boolean };
    /** Sidebar sections (show/hide only). Separate from `can`, which a page prop can replace. */
    nav: { cmo_office: boolean; section: string | null };
    flash: { success: string | null; error: string | null; info: string | null };
    /** V2-4: the Ministry crest's URL once the file is supplied, else null (the sidebar shows the shield-plus tile). */
    brand: { logo: string | null };
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
