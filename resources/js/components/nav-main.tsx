import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';

/** Query keys that make a list view distinct; sort and page do not. */
const VIEW_KEYS = ['status', 'awaiting', 'type', 'org', 'ref'];

/** A saved list view: same path and the same view filters, so "/cases?status=WITH_PS" stays active after sorting or paging. */
export function isViewActive(itemUrl: string, pageUrl: string): boolean {
    const item = new URL(itemUrl, window.location.origin);
    const page = new URL(pageUrl, window.location.origin);
    if (item.pathname !== page.pathname) return false;
    return VIEW_KEYS.every((key) => (item.searchParams.get(key) ?? '') === (page.searchParams.get(key) ?? ''));
}

interface NavMainProps {
    items: NavItem[];
    label?: string;
    /** The url of the one active item in the whole sidebar (worked out by AppSidebar), or null. */
    activeUrl: string | null;
    /** The first group sits right under the header block, whose bottom padding already gives the space. */
    first?: boolean;
}

/**
 * A sidebar group on the navy sidebar (V2-4, design.md sections 4 and 6): the
 * 11px --navy-muted group label and 44px items (8px radius, 20px icon,
 * 14px/500). The active item is the solid --nav-active pill with a 3px white
 * indicator bar, semibold, and aria-current="page" (SidebarMenuButton's
 * data-active style). A group with no items renders nothing, label included.
 */
export function NavMain({ items = [], label = 'Platform', activeUrl, first = false }: NavMainProps) {
    if (items.length === 0) {
        return null;
    }

    return (
        // 12px inner padding; 8px when collapsed to icons (the 48px rail fits a 32px icon button).
        <SidebarGroup className="px-3 py-0 group-data-[collapsible=icon]:px-2">
            <SidebarGroupLabel className={cn('mb-1 h-auto py-1', first ? 'mt-0' : 'mt-5')}>{label}</SidebarGroupLabel>
            <SidebarMenu className="gap-1">
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={item.url === activeUrl} tooltip={item.title} className="h-11 gap-3 px-3 [&>svg]:size-5">
                            <Link href={item.url} prefetch aria-current={item.url === activeUrl ? 'page' : undefined}>
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
