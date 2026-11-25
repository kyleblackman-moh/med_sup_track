import { badgeText } from '@/lib/notifications';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';

interface NavMainProps {
    items: NavItem[];
    label?: string;
    /** The url of the one active item in the whole sidebar (worked out by AppSidebar), or null. */
    activeUrl: string | null;
}

/**
 * One group of drawer links (Blueprint: daisyUI menu w-full). The active item
 * carries menu-active (light grey, base-300) and aria-current="page" together. In the icon rail the
 * label hides and a tooltip names the link. An item with a count (QUERIES-1)
 * ends in a badge (Blueprint: menu with icons and badge); in the icon rail a
 * dot on the icon stands in for it and the tooltip carries the number. A group
 * with no items renders nothing, label included.
 */
export function NavMain({ items = [], label, activeUrl }: NavMainProps) {
    if (items.length === 0) {
        return null;
    }

    return (
        <ul className="menu w-full capitalize [--menu-active-bg:var(--color-base-300)] [--menu-active-fg:var(--color-base-content)]">
            {label && <li className="menu-title is-drawer-close:hidden">{label}</li>}
            {items.map((item) => {
                const active = item.url === activeUrl;
                const count = item.count ?? 0;
                return (
                    <li key={item.title}>
                        <Link
                            href={item.url}
                            prefetch
                            className={
                                active
                                    ? 'menu-active is-drawer-close:tooltip is-drawer-close:tooltip-right motion-safe:transition-colors'
                                    : 'is-drawer-close:tooltip is-drawer-close:tooltip-right motion-safe:transition-colors'
                            }
                            data-tip={count > 0 ? `${item.title} (${badgeText(count)})` : item.title}
                            aria-current={active ? 'page' : undefined}
                        >
                            {item.icon && (
                                <span className="indicator shrink-0">
                                    {count > 0 && (
                                        <span className="indicator-item status status-primary status-sm is-drawer-open:hidden" aria-hidden="true" />
                                    )}
                                    <item.icon className="size-4" aria-hidden="true" />
                                </span>
                            )}
                            <span className="is-drawer-close:hidden">{item.title}</span>
                            {count > 0 && (
                                <span className="badge badge-sm badge-primary is-drawer-close:hidden ms-auto tabular-nums" aria-hidden="true">
                                    {badgeText(count)}
                                </span>
                            )}
                            {count > 0 && <span className="sr-only">, {count} waiting</span>}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
