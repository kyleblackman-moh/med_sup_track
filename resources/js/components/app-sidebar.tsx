import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ClipboardList, FilePen, FileSignature, FileText, FolderOpen, Landmark, LayoutGrid } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
];

// Shown only when the server shares can.requests (display only; the routes authorise).
const requestsNavItem: NavItem = {
    title: 'Requests',
    url: '/requests',
    icon: FileText,
};

// Shown only when the server shares can.cases (staff case pages; display only).
const casesNavItem: NavItem = {
    title: 'File tracking',
    url: '/cases',
    icon: FolderOpen,
};

// Office of the CMO shortcuts: saved views of the case register. Shown when the
// server shares nav.cmo_office; the /cases routes authorise as usual.
const cmoNavItems: NavItem[] = [
    { title: 'To review', url: '/cases?status=IN_REVIEW', icon: ClipboardList },
    { title: 'CAB', url: '/cases?awaiting=funding', icon: Landmark },
    { title: 'PS file', url: '/cases?status=WITH_PS', icon: FileSignature },
    { title: 'Drafts', url: '/cases?status=NON_OBJECTION_GRANTED', icon: FilePen },
];

/**
 * The drawer side's panel (Blueprint: menu w-full); the layout places it in drawer-side. Full width when open,
 * an icon rail with tooltips when collapsed on desktop, an overlay on phones.
 */
export function AppSidebar() {
    const { can, nav } = usePage<SharedData>().props;
    const navItems = [...mainNavItems, ...(can?.requests ? [requestsNavItem] : []), ...(can?.cases ? [casesNavItem] : [])];
    const showCmo = Boolean(nav?.cmo_office);

    return (
        <aside className="bg-base-100 border-base-300 is-drawer-close:w-16 flex min-h-full w-64 flex-col border-r transition-[width] duration-200">
            <Link
                href="/dashboard"
                prefetch
                className="is-drawer-close:justify-center is-drawer-close:px-0 is-drawer-close:[&>.grid]:hidden flex h-14 shrink-0 items-center gap-2 px-4"
            >
                <AppLogo />
            </Link>
            <nav aria-label="Main" className="flex grow flex-col">
                <NavMain items={navItems} />
                {showCmo && <NavMain label="Office of the CMO" items={cmoNavItems} />}
            </nav>
            <div className="border-base-300 border-t p-2">
                <NavUser />
            </div>
        </aside>
    );
}
