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
}

/**
 * One group of drawer links (Blueprint: daisyUI menu w-full). The active item
 * carries menu-active and aria-current="page" together. In the icon rail the
 * label hides and a tooltip names the link. A group with no items renders
 * nothing, label included.
 */
export function NavMain({ items = [], label, activeUrl }: NavMainProps) {
    if (items.length === 0) {
        return null;
    }

    return (
        <ul className="menu w-full capitalize">
            {label && <li className="menu-title is-drawer-close:hidden">{label}</li>}
            {items.map((item) => {
                const active = item.url === activeUrl;
                return (
                    <li key={item.title}>
                        <Link
                            href={item.url}
                            prefetch
                            className={
                                active
                                    ? 'menu-active is-drawer-close:tooltip is-drawer-close:tooltip-right transition-colors'
                                    : 'is-drawer-close:tooltip is-drawer-close:tooltip-right transition-colors'
                            }
                            data-tip={item.title}
                            aria-current={active ? 'page' : undefined}
                        >
                            {item.icon && <item.icon className="size-4 shrink-0" aria-hidden="true" />}
                            <span className="is-drawer-close:hidden">{item.title}</span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
