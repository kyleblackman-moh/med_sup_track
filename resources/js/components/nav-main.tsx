import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

/** Query keys that make a list view distinct; sort and page do not. */
const VIEW_KEYS = ['status', 'awaiting', 'type', 'org', 'ref'];

/** Same path and the same view filters, so "/cases?status=WITH_PS" stays active after sorting or paging. */
function isActive(itemUrl: string, pageUrl: string): boolean {
    const item = new URL(itemUrl, window.location.origin);
    const page = new URL(pageUrl, window.location.origin);
    if (item.pathname !== page.pathname) return false;
    return VIEW_KEYS.every((key) => (item.searchParams.get(key) ?? '') === (page.searchParams.get(key) ?? ''));
}

export function NavMain({ items = [], label = 'Platform' }: { items: NavItem[]; label?: string }) {
    const page = usePage();
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={isActive(item.url, page.url)} tooltip={item.title}>
                            <Link href={item.url} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
