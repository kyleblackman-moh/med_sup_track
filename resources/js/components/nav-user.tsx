import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronsUpDown, KeyRound, LogOut, ShieldCheck, UserRound } from 'lucide-react';
import { useRef } from 'react';

const MENU_ID = 'account-menu';

/**
 * The drawer footer (Blueprint dropdown, popover API): the account button
 * (initials, name and the server's subtitle: organisation for vendors, role
 * name for staff) opens a menu above it with who is signed in, the account
 * settings pages and Log Out. One Log Out, inside the menu. In the collapsed
 * rail the button is the avatar alone, with its name as a tooltip.
 */
export function NavUser() {
    const { auth } = usePage<SharedData>().props;
    const getInitials = useInitials();
    const cleanup = useMobileNavigation();
    const menuRef = useRef<HTMLDivElement>(null);
    const initials = getInitials(auth.user.name);

    const close = () => menuRef.current?.hidePopover();

    const items = [
        { label: 'Profile', href: route('profile.edit'), icon: UserRound },
        { label: 'Password', href: route('password.edit'), icon: KeyRound },
        { label: 'Security', href: route('security.edit'), icon: ShieldCheck },
    ];

    return (
        <>
            <button
                type="button"
                popoverTarget={MENU_ID}
                style={{ anchorName: '--account-menu' } as React.CSSProperties}
                className="btn btn-ghost is-drawer-close:justify-center is-drawer-close:px-0 is-drawer-close:tooltip is-drawer-close:tooltip-right h-auto min-h-12 w-full justify-start gap-2.5 px-2 py-2 font-normal"
                data-tip={auth.user.name}
                aria-label={`Account menu for ${auth.user.name}`}
            >
                <span className="avatar avatar-placeholder shrink-0">
                    <span className="bg-accent text-accent-content grid size-8 place-items-center rounded-full">
                        <span className="text-xs font-semibold">{initials}</span>
                    </span>
                </span>
                <span className="is-drawer-close:hidden grid min-w-0 flex-1 text-left leading-tight">
                    <span className="truncate text-sm font-semibold">{auth.user.name}</span>
                    {auth.user.subtitle && <span className="text-base-content/70 truncate text-xs">{auth.user.subtitle}</span>}
                </span>
                <ChevronsUpDown className="text-base-content/60 is-drawer-close:hidden size-4 shrink-0" aria-hidden="true" />
            </button>

            <div
                ref={menuRef}
                id={MENU_ID}
                popover="auto"
                style={{ positionAnchor: '--account-menu' } as React.CSSProperties}
                className="dropdown dropdown-top bg-base-100 border-base-300 rounded-box w-64 border shadow-md"
            >
                <div className="flex items-center gap-3 px-4 pt-4 pb-3">
                    <span className="avatar avatar-placeholder shrink-0">
                        <span className="bg-accent text-accent-content grid size-10 place-items-center rounded-full">
                            <span className="text-sm font-semibold">{initials}</span>
                        </span>
                    </span>
                    <span className="grid min-w-0 leading-tight">
                        <span className="truncate text-sm font-semibold">{auth.user.name}</span>
                        <span className="text-base-content/70 truncate text-xs">{auth.user.email}</span>
                        {auth.user.subtitle && <span className="text-base-content/70 truncate text-xs">{auth.user.subtitle}</span>}
                    </span>
                </div>
                <ul className="menu border-base-300 w-full border-t p-2">
                    <li className="menu-title capitalize">Account Settings</li>
                    {items.map((item) => (
                        <li key={item.label}>
                            <Link href={item.href} prefetch onClick={close} className="capitalize">
                                <item.icon className="size-4" aria-hidden="true" />
                                {item.label}
                            </Link>
                        </li>
                    ))}
                </ul>
                <ul className="menu border-base-300 w-full border-t p-2">
                    <li>
                        <Link
                            method="post"
                            href={route('logout')}
                            as="button"
                            onClick={() => {
                                close();
                                cleanup();
                            }}
                            className="capitalize"
                        >
                            <LogOut className="size-4" aria-hidden="true" />
                            Log Out
                        </Link>
                    </li>
                </ul>
            </div>
        </>
    );
}
