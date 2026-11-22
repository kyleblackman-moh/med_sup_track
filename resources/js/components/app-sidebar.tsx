import { isViewActive, NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ClipboardList, FilePen, FileSignature, FileText, FolderOpen, Landmark, LayoutGrid, UsersRound } from 'lucide-react';
import { SidebarBrand } from './app-logo';

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

// Shown only when the server shares can.cases (staff case pages; display only).
const casesNavItem: NavItem = {
    title: 'File Tracking',
    url: '/cases',
    icon: FolderOpen,
    section: 'cases',
};

// Office of the CMO shortcuts: saved views of the case register. Shown when the
// server shares nav.cmo_office; the /cases routes authorise as usual.
const cmoNavItems: NavItem[] = [
    { title: 'To Review', url: '/cases?status=IN_REVIEW', icon: ClipboardList },
    { title: 'CAB', url: '/cases?awaiting=funding', icon: Landmark },
    { title: 'PS File', url: '/cases?status=WITH_PS', icon: FileSignature },
    { title: 'Drafts', url: '/cases?status=NON_OBJECTION_GRANTED', icon: FilePen },
];

// ADMIN-1: shown when the server shares nav.users.
const adminNavItems: NavItem[] = [{ title: 'Users', url: '/admin/users', icon: UsersRound, section: 'admin' }];

/**
 * The drawer side's panel (Blueprint: drawer + menu w-full); the layout places
 * it in drawer-side. Full width when open, an icon rail with tooltips when
 * collapsed on desktop, an overlay on phones. One active item for the whole
 * sidebar: a matching CMO saved view, else the item for the server's
 * nav.section.
 */
export function AppSidebar() {
    const { can, nav, brand } = usePage<SharedData>().props;
    const page = usePage();
    const navItems = [...mainNavItems, ...(can?.requests ? [requestsNavItem] : []), ...(can?.cases ? [casesNavItem] : [])];
    const showCmo = Boolean(nav?.cmo_office);
    const adminItems = nav?.users ? adminNavItems : [];

    const activeView = showCmo ? cmoNavItems.find((item) => isViewActive(item.url, page.url)) : undefined;
    const activeUrl =
        activeView?.url ?? [...navItems, ...adminItems].find((item) => item.section !== undefined && item.section === nav?.section)?.url ?? null;

    return (
        <aside className="bg-base-100 border-base-300 is-drawer-close:w-16 flex min-h-full w-64 flex-col border-r transition-[width] duration-200">
            <Link
                href="/dashboard"
                prefetch
                className="border-base-300 is-drawer-close:px-2 flex shrink-0 items-center gap-2 border-b px-4 py-3 outline-none focus-visible:ring-2"
            >
                <SidebarBrand logo={brand?.logo} />
            </Link>
            <nav aria-label="Main" className="flex grow flex-col">
                <NavMain items={navItems} activeUrl={activeUrl} />
                {showCmo && <NavMain label="Office of the CMO" items={cmoNavItems} activeUrl={activeUrl} />}
                <NavMain label="Administration" items={adminItems} activeUrl={activeUrl} />
            </nav>
            <div className="border-base-300 border-t p-2">
                <NavUser />
            </div>
        </aside>
    );
}
