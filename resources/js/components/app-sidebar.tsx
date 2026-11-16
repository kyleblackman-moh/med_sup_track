import { NavMain } from '@/components/nav-main';
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
// Queries & responses is added when queries are built (Phase 2).
const cmoNavItems: NavItem[] = [
    { title: 'To review', url: '/cases?status=IN_REVIEW', icon: ClipboardList },
    { title: 'CAB', url: '/cases?awaiting=funding', icon: Landmark },
    { title: 'PS file', url: '/cases?status=WITH_PS', icon: FileSignature },
    { title: 'Drafts', url: '/cases?status=NON_OBJECTION_GRANTED', icon: FilePen },
];

export function AppSidebar() {
    const { can, nav } = usePage<SharedData>().props;
    const navItems = [...mainNavItems, ...(can?.requests ? [requestsNavItem] : []), ...(can?.cases ? [casesNavItem] : [])];
    const showCmo = Boolean(nav?.cmo_office);

    return (
        // Flat with a right border (design.md section 6), not the starter kit's "inset".
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
                {showCmo && <NavMain label="Office of the CMO" items={cmoNavItems} />}
            </SidebarContent>

            <SidebarFooter>
                {/* The starter kit's Repository and Documentation links are removed (design.md section 6). */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
