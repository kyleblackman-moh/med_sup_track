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

/**
 * One group of drawer links (daisyUI menu). In the icon rail the label hides
 * and a tooltip names the link.
 */
export function NavMain({ items = [], label }: { items: NavItem[]; label?: string }) {
    const page = usePage();

    return (
        <ul className="menu w-full capitalize">
            {label && <li className="menu-title is-drawer-close:hidden">{label}</li>}
            {items.map((item) => {
                const active = isActive(item.url, page.url);
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
