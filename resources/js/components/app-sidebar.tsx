import { isViewActive, NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ClipboardList, FilePen, FileSignature, FileText, FolderOpen, Landmark, LayoutGrid } from 'lucide-react';
import AppLogo from './app-logo';

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
        // UI-4: the sidebar sits on L0 with no background or border of its own; the "inset"
        // variant puts the page on the L1 canvas beside it (design.md sections 5 and 6).
        <Sidebar collapsible="icon" variant="inset" className="print:hidden">
            {/* NEXT: header row padding 8px 12px 20px. */}
            <SidebarHeader className="px-3 pt-2 pb-5 group-data-[collapsible=icon]:px-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" className="h-auto min-h-12 py-2" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-0">
                <NavMain items={navItems} activeUrl={activeUrl} first />
                {showCmo && <NavMain label="Office of the CMO" items={cmoNavItems} activeUrl={activeUrl} />}
            </SidebarContent>

            {/* NEXT: the signed-in user chip with 12px padding. */}
            <SidebarFooter className="border-t p-3 group-data-[collapsible=icon]:p-2">
                {/* The starter kit's Repository and Documentation links are removed (design.md section 6). */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
