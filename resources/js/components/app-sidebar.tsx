import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { FileText, FolderOpen, LayoutGrid } from 'lucide-react';
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
    title: 'Cases',
    url: '/cases',
    icon: FolderOpen,
};

export function AppSidebar() {
    const { can } = usePage<SharedData>().props;
    const navItems = [...mainNavItems, ...(can?.requests ? [requestsNavItem] : []), ...(can?.cases ? [casesNavItem] : [])];

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
            </SidebarContent>

            <SidebarFooter>
                {/* The starter kit's Repository and Documentation links are removed (design.md section 6). */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
