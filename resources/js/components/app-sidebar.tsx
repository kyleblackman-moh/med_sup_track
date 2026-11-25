import { useAppDrawer } from '@/components/app-shell';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Activity, ChartColumn, FileText, FolderOpen, LayoutGrid, MessageSquareText, Route, ScrollText, UsersRound, X } from 'lucide-react';
import { AppLogoMark, SidebarBrand } from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
        section: 'dashboard',
    },
];

// Shown only when the server shares can.requests (display only; the routes authorise).
const requestsNavItem: NavItem = {
    title: 'Requests',
    url: '/requests',
    icon: FileText,
    section: 'requests',
};

// 2026-11-24: shown when the server shares nav.cases (the case register; display only).
const casesNavItem: NavItem = {
    title: 'Cases',
    url: '/cases',
    icon: FolderOpen,
    section: 'cases',
};

// Backlog, 2026-11-25: shown when the server shares nav.tracking; the File Tracking page.
// (The old CMO shortcuts To Review, CAB, PS File and Drafts are now status chips on Cases.)
const trackingNavItem: NavItem = {
    title: 'File Tracking',
    url: '/tracking',
    icon: Route,
    section: 'tracking',
};

// ADMIN-1: shown when the server shares nav.users.
const usersNavItem: NavItem = { title: 'Users', url: '/admin/users', icon: UsersRound, section: 'admin' };

/** The admin item whose path is the longest prefix of the page's path (so /admin/users/5 is Users, /admin/jobs is Health). */
function longestPrefixItem(items: NavItem[], pageUrl: string): NavItem | undefined {
    const path = new URL(pageUrl, window.location.origin).pathname;
    return items
        .filter((item) => {
            const itemPath = new URL(item.url, window.location.origin).pathname;
            return path === itemPath || path.startsWith(itemPath.endsWith('/') ? itemPath : `${itemPath}/`);
        })
        .sort((a, b) => b.url.length - a.url.length)[0];
}

/**
 * The drawer side's panel (Blueprint: drawer + menu w-full); the layout places
 * it in drawer-side. White, below the top bar from lg: full width when open,
 * an icon rail with tooltips when collapsed. On phones an overlay above the
 * top bar with its own header row (pill tile, "Menu", close). One active item for the whole
 * sidebar: the item for the server's nav.section (the longest matching path in Administration).
 */
export function AppSidebar() {
    const { can, nav, brand, openQueriesCount } = usePage<SharedData>().props;
    const page = usePage();
    const { id } = useAppDrawer();
    // Shown only when the server shares nav.analytics; route() is called only then.
    const analyticsItems: NavItem[] = nav?.analytics
        ? [{ title: 'Analytics', url: route('analytics'), icon: ChartColumn, section: 'analytics' }]
        : [];
    // QUERIES-1: shown when the server shares nav.queries; the count is the server's.
    const queriesItems: NavItem[] = nav?.queries
        ? [{ title: 'Queries', url: route('queries.index'), icon: MessageSquareText, section: 'queries', count: openQueriesCount ?? 0 }]
        : [];
    // Backlog, 2026-11-25: Dashboard, Cases, File Tracking, Queries, Analytics; each item only when the
    // server shares its flag (permissions, never roles). Vendors: Requests (their home) and Queries.
    const navItems = [
        // Kyle, 2026-11-24: vendors' home is Requests, so the server hides Dashboard for them.
        ...(nav?.dashboard === false ? [] : mainNavItems),
        ...(can?.requests ? [requestsNavItem] : []),
        ...(nav?.cases ? [casesNavItem] : []),
        ...(nav?.tracking ? [trackingNavItem] : []),
        ...queriesItems,
        ...analyticsItems,
    ];
    // Administration: Audit, then Users and Health (ICT), each shown only when the server shares its flag.
    const adminItems: NavItem[] = [
        ...(nav?.audit_url ? [{ title: 'Audit', url: nav.audit_url, icon: ScrollText }] : []),
        ...(nav?.users ? [usersNavItem] : []),
        ...(nav?.it_health ? [{ title: 'Health', url: route('admin.dashboard'), icon: Activity }] : []),
    ];

    const activeAdmin = nav?.section === 'admin' || nav?.section === 'audit' ? longestPrefixItem(adminItems, page.url) : undefined;
    const activeUrl = activeAdmin?.url ?? navItems.find((item) => item.section !== undefined && item.section === nav?.section)?.url ?? null;

    return (
        <aside className="bg-base-100 border-base-300 is-drawer-close:w-16 flex min-h-full w-64 flex-col border-r motion-safe:transition-[width] motion-safe:duration-200">
            <div className="border-base-300 flex h-16 shrink-0 items-center justify-between border-b px-4 lg:hidden">
                <span className="flex items-center gap-2 font-semibold capitalize">
                    <AppLogoMark />
                    Menu
                </span>
                <label htmlFor={id} aria-label="Close navigation" className="btn btn-ghost btn-square btn-sm">
                    <X className="size-5" aria-hidden="true" />
                </label>
            </div>
            <Link
                href="/dashboard"
                prefetch
                className="border-base-300 is-drawer-close:px-2 hidden shrink-0 items-center gap-2 border-b px-4 py-3 outline-none focus-visible:ring-2 lg:flex"
            >
                <SidebarBrand logo={brand?.logo} />
            </Link>
            <nav aria-label="Main" className="flex grow flex-col">
                <NavMain items={navItems} activeUrl={activeUrl} />
                <NavMain label="Administration" items={adminItems} activeUrl={activeUrl} />
            </nav>
            <div className="border-base-300 border-t p-2">
                <NavUser />
            </div>
        </aside>
    );
}
