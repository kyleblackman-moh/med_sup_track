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
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    [key: string]: unknown;
}

// Exactly what the server shares; nothing else is sent to the browser.
export interface User {
    id: number;
    name: string;
    email: string;
    user_type: string;
    avatar?: string;
    email_verified_at?: string | null;
}
