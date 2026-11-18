import { isViewActive, NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { SidebarWatermark } from '@/components/sidebar-watermark';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ClipboardList, FilePen, FileSignature, FileText, FolderOpen, Landmark, LayoutGrid } from 'lucide-react';
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
    title: 'File tracking',
    url: '/cases',
    icon: FolderOpen,
    section: 'cases',
};

// Office of the CMO shortcuts: saved views of the case register. Shown when the
// server shares nav.cmo_office; the /cases routes authorise as usual.
// Queries & responses is added when queries are built (Phase 2).
const cmoNavItems: NavItem[] = [
    { title: 'To review', url: '/cases?status=IN_REVIEW', icon: ClipboardList },
    { title: 'CAB', url: '/cases?awaiting=funding', icon: Landmark },
    { title: 'PS file', url: '/cases?status=WITH_PS', icon: FileSignature },
    { title: 'Drafts', url: '/cases?status=NON_OBJECTION_GRANTED', icon: FilePen },
];

export function AppSidebar() {
    const { can, nav } = usePage<SharedData>().props;
    const page = usePage();
    const navItems = [...mainNavItems, ...(can?.requests ? [requestsNavItem] : []), ...(can?.cases ? [casesNavItem] : [])];
    const showCmo = Boolean(nav?.cmo_office);

    // NEXT: one active item. A saved CMO view that matches exactly wins; otherwise the
    // main item whose section the server matched by URL prefix (nav.section), so
    // /requests/{id}/edit activates Requests and /cases/{id} the cases item.
    const activeView = showCmo ? cmoNavItems.find((item) => isViewActive(item.url, page.url)) : undefined;
    const activeUrl = activeView?.url ?? navItems.find((item) => item.section !== undefined && item.section === nav?.section)?.url ?? null;

    return (
        // V2-4 (design.md sections 5 and 6): the navy sidebar, flush to the left edge,
        // full height, 208px, no outer gutter and no border of its own. Collapses to
        // icons on desktop and is an off-canvas sheet below md (SidebarProvider).
        <Sidebar collapsible="icon" variant="sidebar" className="group-data-[side=left]:border-r-0 print:hidden">
            {/* The header block: the mark (or the crest), the Ministry's name, then a divider. */}
            <SidebarHeader className="gap-0 px-3 pt-5 pb-0 group-data-[collapsible=icon]:px-2 group-data-[collapsible=icon]:pt-3">
                <Link
                    href="/dashboard"
                    prefetch
                    className="ring-sidebar-ring ring-offset-sidebar rounded-lg p-1 outline-hidden focus-visible:ring-2 focus-visible:ring-offset-2"
                >
                    <SidebarBrand />
                </Link>
                <div aria-hidden="true" className="border-navy-border mt-4 border-t group-data-[collapsible=icon]:mt-3" />
            </SidebarHeader>

            <SidebarContent className="gap-0 pt-4">
                <NavMain items={navItems} activeUrl={activeUrl} first />
                {showCmo && <NavMain label="Office of the CMO" items={cmoNavItems} activeUrl={activeUrl} />}
            </SidebarContent>

            {/* Decorative, between the nav items and the user card (never behind nav text). */}
            <SidebarWatermark />

            {/* The starter kit's Repository and Documentation links are removed (design.md section 6). */}
            <SidebarFooter className="border-navy-border border-t p-3 group-data-[collapsible=icon]:p-2">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
